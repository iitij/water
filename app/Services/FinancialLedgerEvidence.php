<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAccountTransfer;
use App\Models\PaymentRefund;
use App\Models\Transaction;
use App\Support\AccountMoney;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Read a supported persisted subscriber-ledger effect. Internal adapter only:
 * callers must already hold the financial scope/period gate and the source's
 * business locks. This does not infer a period from timestamps, backfill legacy
 * postings, prove allocation/cash coverage, or activate any production writer.
 */
final class FinancialLedgerEvidence
{
    public function leg(int $transactionId): array
    {
        $this->need(DB::transactionLevel() > 0 && $transactionId > 0, 'ledger_evidence_requires_gate');
        $entry = Transaction::withTrashed()->lockForUpdate()->find($transactionId);
        $this->need($entry && ! $entry->trashed() && (int) $entry->id === $transactionId, 'ledger_source_missing_or_deleted');
        $type = $entry->type;
        $expectedClass = match ($type) {
            'invoice', 'invoice_reversal' => Invoice::class,
            'payment', 'payment_reversal' => Payment::class,
            'payment_transfer_in' => PaymentAccountTransfer::class,
            'payment_refund' => PaymentRefund::class,
            default => throw new DomainException('ledger_source_type_unsupported'),
        };
        $this->need($entry->reference_type === $expectedClass && (int) $entry->reference_id > 0, 'ledger_document_identity');
        $doc = $expectedClass::query()->lockForUpdate()->find($entry->reference_id);
        $this->need($doc && (int) $doc->id === (int) $entry->reference_id, 'ledger_document_missing');
        $owner = (int) ($doc instanceof PaymentAccountTransfer ? $doc->to_subscriber_id : $doc->subscriber_id);
        $amount = AccountMoney::stored($doc, $doc instanceof Invoice ? 'total_amount' : 'amount');
        $this->need($owner > 0 && ($doc instanceof Invoice ? $amount >= 0 : $amount > 0), 'ledger_document_amount');
        if ($doc instanceof Invoice) {
            $this->need($doc->status !== 'draft'
                && ($type !== 'invoice_reversal' || $doc->status === 'cancelled'), 'ledger_document_status');
        }
        if ($type === 'payment_reversal') {
            // Full returned-check reversals will need an explicit additional
            // verified return document. Do not guess from a negative posting.
            $transfer = PaymentAccountTransfer::where('payment_id', $doc->id)->lockForUpdate()->first();
            $this->need($doc->isVoided() || ($transfer
                && (int) $transfer->from_subscriber_id === $owner
                && AccountMoney::stored($transfer) === $amount), 'ledger_reversal_evidence');
        }

        $expected = in_array($type, ['invoice', 'payment_reversal', 'payment_refund'], true) ? -$amount : $amount;
        $minor = AccountMoney::stored($entry);
        $documentMeter = $doc instanceof Invoice || $doc instanceof Payment
            ? $this->meter($doc->meter_id, $doc->retired_meter_id) : null;
        $entryMeter = $this->meter($entry->meter_id, $entry->retired_meter_id);
        $this->need((int) $entry->subscriber_id === $owner && $minor === $expected
            && $entryMeter === $documentMeter, 'ledger_source_mismatch');

        // A physical meter retirement moves meter_id to retired_meter_id.
        // Hash the original stable identity, not the mutable storage location.
        // Do not hash mutable invoice paid/status or receipt void/transfer state.
        $bytes = json_encode([
            'format' => 1, 'transaction_id' => (string) $transactionId,
            'subscriber_id' => (string) $owner,
            'original_meter_id' => $entryMeter === null ? null : (string) $entryMeter,
            'posting_type' => $type, 'reference_type' => $expectedClass,
            'reference_id' => (string) $doc->id, 'amount_minor' => (string) $minor,
            'currency' => 'YER',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return [
            'source_type' => 'ledger_transaction', 'source_id' => (string) $transactionId,
            'leg' => 'subscriber', 'amount_minor' => $minor,
            'currency' => 'YER', 'source_hash' => hash('sha256', $bytes),
        ];
    }

    private function meter(mixed $current, mixed $retired): ?int
    {
        $this->need($current === null || $retired === null, 'ledger_meter_identity');
        $value = $current ?? $retired;
        $this->need($value === null || (int) $value > 0, 'ledger_meter_identity');
        return $value === null ? null : (int) $value;
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
