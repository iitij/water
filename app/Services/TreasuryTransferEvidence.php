<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\TreasuryMoney;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Read-only evidence of actual internal treasury dispatch/receipt postings.
 * Scope-first outer transaction is a precondition; this is not a writer,
 * transit reconciliation, coverage certification or close authorization.
 */
final class TreasuryTransferEvidence
{
    public function leg(int $postingId): array
    {
        $this->need(DB::transactionLevel() > 0 && $postingId > 0, 'treasury_evidence_requires_gate');
        $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null,
            'treasury_evidence_issuance_lock');
        $row = DB::table('treasury_postings')->where('id', $postingId)->lockForUpdate()->first();
        $this->need($row !== null, 'treasury_evidence_missing');
        $out = $row->kind === 'internal_transfer_out';
        $in = $row->kind === 'internal_transfer_in';
        $this->need($out || $in, 'treasury_evidence_unsupported');
        foreach (['cash_handover_id', 'payment_refund_id', 'cash_session_funding_id',
            'treasury_bank_fee_id', 'treasury_cash_adjustment_id', 'treasury_bank_collection_id',
            'treasury_late_cash_receipt_id'] as $field) {
            $this->need($row->$field === null, 'treasury_evidence_source_conflict');
        }
        $this->need($out
            ? ($row->treasury_transfer_id !== null && $row->treasury_transfer_receipt_id === null)
            : ($row->treasury_transfer_id === null && $row->treasury_transfer_receipt_id !== null),
            'treasury_evidence_source_conflict');

        $receipt = $in ? DB::table('treasury_transfer_receipts')
            ->where('id', $row->treasury_transfer_receipt_id)->lockForUpdate()->first() : null;
        $this->need(! $in || $receipt !== null, 'treasury_evidence_missing');
        $transfer = DB::table('treasury_transfers')
            ->where('id', $out ? $row->treasury_transfer_id : $receipt->treasury_transfer_id)
            ->lockForUpdate()->first();
        $this->need($transfer !== null, 'treasury_evidence_missing');
        $from = (int) $transfer->from_account_id;
        $to = (int) $transfer->to_account_id;
        $accounts = DB::table('treasury_accounts')->whereIn('id', [$from, $to])
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $this->need($from > 0 && $to > 0 && $from !== $to && $accounts->count() === 2
            && $transfer->dispatched_on >= $accounts[$from]->opening_on
            && $transfer->dispatched_on >= $accounts[$to]->opening_on, 'treasury_evidence_accounts');
        $amount = TreasuryMoney::document($transfer->amount_minor, true);
        $sender = (int) $transfer->dispatched_by;
        $this->need($sender > 0, 'treasury_evidence_actor');
        if ($in) {
            $this->need(TreasuryMoney::document($receipt->amount_minor, true) === $amount
                && $receipt->received_on >= $transfer->dispatched_on
                && $receipt->reference !== $transfer->reference
                && (int) $receipt->received_by > 0 && (int) $receipt->received_by !== $sender,
                'treasury_evidence_receipt');
            // Verify matching dispatch exists; do not certify the entire transit balance.
            $dispatch = DB::table('treasury_postings')->where('treasury_transfer_id', $transfer->id)
                ->lockForUpdate()->sole();
            $this->need($dispatch->kind === 'internal_transfer_out', 'treasury_evidence_dispatch');
            $this->leg((int) $dispatch->id);
        }
        $source = $out ? $transfer : $receipt;
        $date = $out ? $transfer->dispatched_on : $receipt->received_on;
        $actor = $out ? $sender : (int) $receipt->received_by;
        $minor = $out ? -$amount : $amount;
        $this->need((int) $row->treasury_account_id === ($out ? $from : $to)
            && TreasuryMoney::signed($row->amount_minor) === $minor
            && $row->effective_on === $date && $row->evidence_reference === $source->reference
            && (int) $row->posted_by === $actor, 'treasury_evidence_source_mismatch');
        // Outbound proof is intentionally independent of a later receipt.
        $bytes = json_encode([
            'format' => 1, 'posting_id' => (string) $postingId,
            'kind' => $row->kind, 'transfer_id' => (string) $transfer->id,
            'receipt_id' => $in ? (string) $receipt->id : null,
            'from_account_id' => (string) $from, 'to_account_id' => (string) $to,
            'amount_minor' => (string) $minor, 'effective_on' => $date,
            'actor_id' => (string) $actor, 'reference' => $source->reference,
            'evidence' => $source->evidence, 'currency' => 'YER',
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
