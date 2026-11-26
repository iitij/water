<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Support\SignedMinor;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Candidate recorded cash reconstruction under the existing issuance lock.
 * Requires source-backed rows, not a net SUM. Not a physical count or
 * global registry coverage proof. Never obtains a scope lock here.
 */
final class HeldCashRecordedLiquidityEvidence
{
    private const SOURCE_COLUMNS = [
        'cash_handover' => 'cash_handover_id',
        'payment_refund' => 'payment_refund_id',
        'collector_funding' => 'cash_session_funding_id',
        'internal_transfer_out' => 'treasury_transfer_id',
        'internal_transfer_in' => 'treasury_transfer_receipt_id',
        'cash_count_adjustment' => 'treasury_cash_adjustment_id',
        'late_cash_receipt' => 'treasury_late_cash_receipt_id',
        'held_cash_payout' => 'held_cash_payout_uuid',
        'bank_fee' => 'treasury_bank_fee_id',
        'bank_collection' => 'treasury_bank_collection_id',
    ];

    public function balance(int $accountId, string $date): int
    {
        $this->need(DB::transactionLevel() > 0
            && DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null,
            'held_cash_reservation_context');
        $account = DB::table('treasury_accounts')->where('id', $accountId)->lockForUpdate()->first();
        $this->need($account !== null && $account->channel === 'cash', 'held_cash_reservation_account');
        $this->need($account->opening_on <= $date, 'held_cash_outflow_backdated');
        $opening = app(TreasuryOpeningFundingEvidence::class)->opening($accountId, $account->opening_on);
        $audit = AuditEvent::query()->where('event', 'treasury.opened')
            ->where('entity_type', 'treasury_account')->where('entity_id', (string) $accountId)->lockForUpdate()->get();
        $this->need($audit->count() === 1 && (int) $audit->first()->actor_user_id === (int) $account->opened_by,
            'held_cash_liquidity_opening_audit');
        foreach (['code', 'name', 'channel', 'opening_on', 'opening_minor', 'opening_reference', 'opening_evidence', 'opened_by'] as $key) {
            $this->need(array_key_exists($key, $audit->first()->after ?? [])
                && (string) $audit->first()->after[$key] === (string) $account->$key, 'held_cash_liquidity_opening_audit');
        }
        $balance = $opening['amount_minor'];
        $rows = DB::table('treasury_postings')->where('treasury_account_id', $accountId)
            ->orderBy('id')->lockForUpdate()->get();
        foreach ($rows as $row) {
            $this->need($row->effective_on <= $date, 'held_cash_outflow_backdated');
            $this->need($row->effective_on >= $account->opening_on, 'held_cash_liquidity_source');
            $this->exclusive($row);
            $minor = SignedMinor::parse($row->amount_minor);
            $this->need($minor !== 0, 'held_cash_liquidity_source');
            $id = (int) $row->id;
            $proof = match ($row->kind) {
                'cash_handover', 'payment_refund' => app(TreasuryCustodyEvidence::class)->leg($id),
                'collector_funding' => app(TreasuryOpeningFundingEvidence::class)->funding($id, $row->effective_on),
                'internal_transfer_out', 'internal_transfer_in' => app(TreasuryTransferEvidence::class)->leg($id),
                'cash_count_adjustment' => app(TreasuryAdjustmentEvidence::class)->leg($id),
                'late_cash_receipt' => app(TreasuryCollectionEvidence::class)->leg($id),
                'held_cash_payout' => $this->payout($row),
                default => throw new DomainException('held_cash_liquidity_source'),
            };
            $this->need($proof['amount_minor'] === $minor, 'held_cash_liquidity_source');
            $balance = SignedMinor::add($balance, $minor);
        }
        // Do not treat a missing outgoing posting as unused cash.
        foreach ([
            ['cash_session_fundings', 'treasury_account_id', 'cash_session_funding_id'],
            ['treasury_transfers', 'from_account_id', 'treasury_transfer_id'],
            ['treasury_cash_adjustments', 'treasury_account_id', 'treasury_cash_adjustment_id'],
        ] as [$table, $accountColumn, $sourceColumn]) {
            $this->need(! DB::table($table)->where($accountColumn, $accountId)
                ->whereNotIn('id', DB::table('treasury_postings')->where('treasury_account_id', $accountId)
                    ->whereNotNull($sourceColumn)->select($sourceColumn))
                ->exists(), 'held_cash_liquidity_missing_posting');
        }
        return $balance;
    }

    public function assertRefund(object $row, int $minor): void
    {
        $this->exclusive($row);
        $this->need($row->kind === 'payment_refund'
            && app(TreasuryCustodyEvidence::class)->leg((int) $row->id)['amount_minor'] === SignedMinor::negate($minor),
            'held_cash_refund_posting_identity');
    }

    private function exclusive(object $row): void
    {
        $expected = self::SOURCE_COLUMNS[$row->kind] ?? null;
        $this->need($expected !== null, 'held_cash_liquidity_source');
        foreach (self::SOURCE_COLUMNS as $column) {
            $this->need(($row->$column !== null) === ($column === $expected), 'held_cash_liquidity_source');
        }
    }

    private function payout(object $row): array
    {
        $this->need(app(TreasuryHeldCashLiabilityService::class)->checkPayoutPosting($row), 'held_cash_liquidity_source');
        return ['amount_minor' => SignedMinor::parse($row->amount_minor)];
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
