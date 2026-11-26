<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CashHandover;
use App\Models\CashSession;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\Transaction;
use App\Support\CashCustodyAmounts as Cash;
use App\Support\DecimalDomain;
use App\Support\TreasuryMoney;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Read-only evidence for persisted handover and refund treasury postings.
 * Scope-first transaction required. No claim of complete coverage or activation.
 */
final class TreasuryCustodyEvidence
{
    public function leg(int $postingId): array
    {
        $this->need(DB::transactionLevel() > 0 && $postingId > 0, 'custody_evidence_requires_gate');
        $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null,
            'custody_evidence_issuance_lock');
        $row = DB::table('treasury_postings')->where('id', $postingId)->lockForUpdate()->first();
        $this->need($row !== null, 'custody_evidence_missing');
        $handover = $row->kind === 'cash_handover';
        $this->need($handover || $row->kind === 'payment_refund', 'custody_evidence_unsupported');
        foreach (['treasury_transfer_id', 'treasury_transfer_receipt_id', 'cash_session_funding_id',
            'treasury_bank_fee_id', 'treasury_cash_adjustment_id', 'treasury_bank_collection_id',
            'treasury_late_cash_receipt_id'] as $field) {
            $this->need($row->$field === null, 'custody_evidence_source_conflict');
        }
        $this->need($handover ? ($row->cash_handover_id !== null && $row->payment_refund_id === null)
            : ($row->payment_refund_id !== null && $row->cash_handover_id === null),
            'custody_evidence_source_conflict');

        $account = DB::table('treasury_accounts')->where('id', $row->treasury_account_id)->lockForUpdate()->first();
        $this->need($account !== null && (int) $row->posted_by > 0, 'custody_evidence_account');
        $source = $handover
            ? CashHandover::query()->lockForUpdate()->find($row->cash_handover_id)
            : PaymentRefund::query()->lockForUpdate()->find($row->payment_refund_id);
        $this->need($source !== null, 'custody_evidence_missing');
        $amount = Cash::positiveStored($source->getRawOriginal('amount'), $source->getConnection()->getDriverName(),
            $handover ? DecimalDomain::D14 : DecimalDomain::D12);

        if ($handover) {
            $session = CashSession::query()->lockForUpdate()->find($source->cash_session_id);
            $this->need($session !== null && $session->closed_at !== null, 'custody_evidence_session');
            $date = $source->received_on->toDateString();
            $reference = $source->reference;
            $actor = (int) $source->received_by;
            $summary = app(CashHandoverService::class)->summary($session);
            $this->need(in_array($summary['status'], ['partial', 'complete'], true)
                && $actor > 0 && $actor !== (int) $session->user_id
                && $date >= $session->closed_at->toDateString(), 'custody_evidence_session');
            $channel = 'cash';
            $parent = ['session_id' => (string) $session->id, 'collector_id' => (string) $session->user_id,
                'counted_minor' => (string) Cash::positiveStored($session->getRawOriginal('counted_amount'),
                    $session->getConnection()->getDriverName(), DecimalDomain::D14)];
        } else {
            $payment = Payment::query()->lockForUpdate()->find($source->payment_id);
            $this->need($payment !== null && (int) $source->subscriber_id === (int) $payment->subscriber_id,
                'custody_evidence_payment');
            $actor = (int) $source->recorded_by;
            $date = $source->disbursed_on->toDateString();
            $reference = $source->disbursement_reference;
            $channel = match ($source->method) {
                'treasury_cash' => 'cash', 'bank_transfer' => 'bank', default => null,
            };
            $this->need($actor > 0 && $actor !== (int) $payment->user_id
                && $date >= $payment->payment_date->toDateString(), 'custody_evidence_payment');
            $entry = Transaction::where('reference_type', PaymentRefund::class)
                ->where('reference_id', $source->id)->where('type', 'payment_refund')->lockForUpdate()->sole();
            $leg = app(FinancialLedgerEvidence::class)->leg((int) $entry->id);
            $this->need($leg['amount_minor'] === -$amount, 'custody_evidence_refund_ledger');
            $parent = ['payment_id' => (string) $payment->id, 'subscriber_id' => (string) $source->subscriber_id,
                'refund_ledger_hash' => $leg['source_hash'], 'reason' => $source->reason];
        }
        $minor = $handover ? $amount : -$amount;
        $this->need($channel === $account->channel && $date >= $account->opening_on
            && $date === $row->effective_on && $reference === $row->evidence_reference
            && mb_strlen($reference) >= 3 && mb_strlen(trim($source->evidence)) >= 10
            && TreasuryMoney::signed($row->amount_minor) === $minor, 'custody_evidence_source_mismatch');

        $bytes = json_encode([
            'format' => 1, 'posting_id' => (string) $postingId, 'kind' => $row->kind,
            'account_id' => (string) $account->id, 'channel' => $channel,
            'source_id' => (string) $source->id, 'parent' => $parent,
            'source_actor' => (string) $actor, 'posted_by' => (string) $row->posted_by,
            'amount_minor' => (string) $minor, 'effective_on' => $date,
            'reference' => $reference, 'evidence' => $source->evidence, 'currency' => 'YER',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return ['source_type' => 'treasury_posting', 'source_id' => (string) $postingId,
            'leg' => 'treasury', 'amount_minor' => $minor, 'currency' => 'YER',
            'source_hash' => hash('sha256', $bytes)];
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
