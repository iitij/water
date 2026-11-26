<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Subscriber;
use App\Support\SignedMinor;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Current subscriber balances and independently replayed allocation policy,
 * alongside verified registered movements for the current accounting month.
 * The current balance is NOT a period-end balance and no opening is inferred
 * by subtracting movements from it. No historical repair or close approval.
 */
final class FinancialSubscriberCoverageDiagnostic
{
    public function __construct(
        private FinancialSourceCoverageDiagnostic $sources,
        private FinancialReconciliationService $reconciliation,
        private AllocationHistoryProtection $protection,
    ) {}

    public function inspect(int $periodId): array
    {
        if (DB::transactionLevel() === 0) throw new DomainException('subscriber_coverage_requires_gate');
        $scope = DB::table('financial_scopes')->where('scope_key', 'water')->lockForUpdate()->first();
        $period = DB::table('financial_periods')->where('scope_key', 'water')->where('id', $periodId)->lockForUpdate()->first();
        $today = now('Asia/Aden')->toDateString();
        if (! $scope || ! $period || $today < $period->start_on || $today >= $period->next_on) {
            throw new DomainException('subscriber_coverage_current_period_required');
        }
        DB::table('financial_periods')->where('scope_key', 'water')->orderBy('id')->lockForUpdate()->get();
        if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
            throw new DomainException('subscriber_coverage_issuance_lock');
        }
        $sourceProof = $this->sources->inspect($periodId);
        $issues = [];
        if (! $sourceProof['scanned_sources_match']) {
            $issues[] = ['code' => 'subscriber_period_sources_unmatched'];
        }
        if (! $this->protection->active()) {
            $issues[] = ['code' => 'subscriber_allocation_protection_missing'];
        }

        $movements = [];
        $events = DB::table('financial_posting_events as e')
            ->join('financial_posting_operations as o', 'o.operation_uuid', '=', 'e.operation_uuid')
            ->where('o.period_id', $periodId)->where('e.source_type', 'ledger_transaction')
            ->select('e.*', 'o.effective_on')->orderBy('e.id')->lockForUpdate()->get();
        foreach ($events as $event) {
            // The source diagnostic verifies the exact persisted ledger effect.
            $entry = DB::table('transactions')->where('id', $event->source_id)->lockForUpdate()->first();
            if (! $entry || $entry->deleted_at !== null || $event->effective_on > $today) {
                $issues[] = ['code' => 'subscriber_movement_unverified', 'event_id' => (int) $event->id];
                continue;
            }
            $id = (int) $entry->subscriber_id;
            $movements[$id] = SignedMinor::add($movements[$id] ?? 0, SignedMinor::parse($event->amount_minor));
        }

        $rows = [];
        $totals = ['registered_movement_minor' => 0, 'current_ledger_minor' => 0,
            'current_document_balance_minor' => 0, 'current_debt_minor' => 0, 'current_credit_minor' => 0,
            'current_available_minor' => 0, 'current_allocated_minor' => 0, 'current_surplus_minor' => 0];
        // Include inactive and soft-deleted accounts rather than hide balances
        // with a status filter. All-time current reconciliation is read-only.
        foreach (Subscriber::withTrashed()->orderBy('id')->lockForUpdate()->lazyById(200) as $subscriber) {
            $id = (int) $subscriber->id;
            $movement = $movements[$id] ?? 0;
            unset($movements[$id]);
            if ($subscriber->trashed()) {
                $hasHistory = DB::table('transactions')->where('subscriber_id', $id)->exists()
                    || DB::table('payments')->where('subscriber_id', $id)->exists()
                    || DB::table('invoices')->where('subscriber_id', $id)->exists()
                    || DB::table('payment_allocation_revisions')->where('subscriber_id', $id)->exists()
                    || DB::table('treasury_held_cash_credits')->where('subscriber_id', $id)->exists();
                if ($hasHistory || $movement !== 0) {
                    $issues[] = ['code' => 'deleted_subscriber_with_financial_history', 'subscriber_id' => $id];
                }
                continue;
            }
            $result = $this->reconciliation->inspect($subscriber);
            $revision = DB::table('payment_allocation_revisions')->where('subscriber_id', $id)
                ->orderByDesc('revision')->lockForUpdate()->first();
            foreach ($result['issues'] as $detail) {
                $issues[] = ['code' => 'subscriber_account_unmatched', 'subscriber_id' => $id, 'detail' => $detail];
            }
            $ledger = $result['ledger_minor'];
            $available = $result['available_minor'];
            $paid = $result['paid_minor'];
            $surplus = SignedMinor::subtract($available, $paid);
            $row = ['subscriber_id' => $id, 'registered_movement_minor' => $movement,
                'current_ledger_minor' => $ledger, 'current_document_balance_minor' => $result['document_balance_minor'],
                'current_debt_minor' => max(0, SignedMinor::negate($ledger)), 'current_credit_minor' => max(0, $ledger),
                'current_available_minor' => $available, 'current_allocated_minor' => $paid, 'current_surplus_minor' => $surplus,
                'current_revision' => $result['revision'], 'current_allocation_hash' => $revision?->allocation_hash,
                'matches' => $result['matches'], 'opening_minor' => null, 'period_closing_minor' => null];
            foreach (array_keys($totals) as $key) $totals[$key] = SignedMinor::add($totals[$key], $row[$key]);
            $rows[] = $row;
        }
        foreach ($movements as $id => $minor) {
            $issues[] = ['code' => 'subscriber_movement_owner_missing', 'subscriber_id' => $id, 'minor' => $minor];
        }
        return ['period_id' => $periodId, 'as_of' => $today, 'rows' => $rows, 'totals' => $totals,
            'issues' => $issues, 'period_source_issues' => $sourceProof['issues'],
            'scanned_accounts_match' => $issues === [],
            'opening_verified' => false, 'period_closing_verified' => false,
            'source_coverage_verified' => false, 'closure_authorized' => false];
    }
}
