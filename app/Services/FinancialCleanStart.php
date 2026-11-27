<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Support\FinancialClosePolicy;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Explicit fresh-install path. No historical adoption, data deletion,
 * balance inference, or production activation. Run only in an isolated
 * setup database, before admitting any writers (maintenance alone is
 * not a complete external-writer fence).
 */
final class FinancialCleanStart
{
    private const EMPTY_TABLES = [
        'audit_events', 'subscribers', 'meters', 'readings', 'reading_faults', 'route_visits',
        'invoices', 'invoice_items', 'invoice_tariff_lines', 'payments', 'transactions',
        'payment_allocation_revisions', 'payment_allocations', 'payment_account_transfers',
        'payment_refunds', 'historical_invoice_corrections', 'closed_cash_corrections',
        'cash_sessions', 'cash_handovers', 'cash_session_fundings', 'late_receipt_reviews',
        'treasury_accounts', 'treasury_postings', 'treasury_transfers', 'treasury_transfer_receipts',
        'bank_statements', 'bank_statement_lines', 'bank_statement_matches',
        'treasury_cash_counts', 'treasury_cash_count_reviews', 'treasury_bank_fees',
        'treasury_cash_adjustments', 'treasury_bank_collections', 'noncash_collection_claims',
        'treasury_late_cash_receipts', 'treasury_held_cash_liabilities', 'treasury_held_cash_assignments',
        'treasury_held_cash_payouts', 'treasury_held_cash_credits', 'treasury_held_cash_payout_authorizations',
        'treasury_held_cash_payout_cancellations', 'held_cash_release_proposals',
        'held_cash_release_withdrawals', 'held_cash_release_journal', 'held_cash_shadow_reinstatements',
        'held_cash_active_releases', 'document_sequences', 'financial_scopes', 'financial_periods',
        'financial_posting_operations', 'financial_posting_events', 'financial_close_commands',
        'financial_period_closures',
    ];

    public function initialize(User $actor, string $month): array
    {
        CompletedCashRefundRequest::authenticated($actor);
        abort_unless(app()->environment('testing') ||
            (app()->environment('staging') && app()->isDownForMaintenance()), 503);
        if (DB::transactionLevel() !== 0 || $month !== now('Asia/Aden')->format('Y-m')) {
            throw new DomainException('clean_start_requires_current_month_and_outer_transaction');
        }
        return DB::transaction(function () use ($actor, $month): array {
            // Serialize setup attempts, not a substitute for stopping legacy writers.
            if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                throw new DomainException('clean_start_issuance_lock_missing');
            }
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            CompletedCashRefundRequest::authenticated($actor);
            $roles = $actor->roles()->lockForUpdate()->get();
            abort_unless($roles->contains(fn ($role) => $role->name === 'super_admin'
                && $role->guard_name === 'web'), 403);
            if (! app(FinancialCleanStartAuditProtection::class)->active()) {
                throw new DomainException('clean_start_audit_protection_missing');
            }
            // Raw table reads deliberately include soft-deleted records.
            foreach (self::EMPTY_TABLES as $table) {
                if (DB::table($table)->lockForUpdate()->first() !== null) {
                    throw new DomainException('clean_start_nonempty:'.$table);
                }
            }
            $period = app(FinancialClosePolicy::class)->open('water', $month, 'Asia/Aden');
            DB::table('financial_scopes')->insert([
                'scope_key' => 'water', 'currency' => 'YER', 'timezone' => 'Asia/Aden',
                'mode' => 'LEGACY_DISABLED', 'calendar_version' => 1,
                'fence_epoch' => 0, 'active_freeze_uuid' => null,
            ]);
            $id = DB::table('financial_periods')->insertGetId([
                'scope_key' => 'water', 'month' => $month, 'start_on' => $period['start'],
                'next_on' => $period['next'], 'state' => 'OPEN', 'version' => 0,
            ]);
            $result = ['format' => 1, 'scope' => 'water', 'period_id' => (int) $id,
                'month' => $month, 'start_on' => $period['start'], 'next_on' => $period['next'],
                'initialization_mode' => 'LEGACY_DISABLED', 'checked_empty_tables' => self::EMPTY_TABLES,
                'root_opening_verified' => false, 'closure_authorized' => false];
            app(AuditLogger::class)->recordRequired('financial.clean_start_initialized', [
                'actor_user_id' => (int) $actor->id, 'entity_type' => 'financial_scope',
                'entity_id' => 'water', 'source' => 'internal', 'after' => $result,
            ]);
            return $result;
        }, 1);
    }

    /**
     * Independent review before writers are admitted. This is a zero-data
     * commissioning checkpoint, not a certified historical balance or close.
     */
    public function review(User $actor): array
    {
        CompletedCashRefundRequest::authenticated($actor);
        abort_unless(app()->environment('testing') ||
            (app()->environment('staging') && app()->isDownForMaintenance()), 503);
        if (DB::transactionLevel() !== 0) throw new DomainException('clean_start_review_requires_outer_transaction');
        return DB::transaction(function () use ($actor): array {
            $gate = DB::table('financial_scopes')->where('scope_key', 'water')->lockForUpdate()->first();
            if (! $gate || $gate->mode !== 'LEGACY_DISABLED' || $gate->currency !== 'YER'
                || $gate->timezone !== 'Asia/Aden' || (string) $gate->calendar_version !== '1'
                || (string) $gate->fence_epoch !== '0' || $gate->active_freeze_uuid !== null) {
                throw new DomainException('clean_start_review_scope_invalid');
            }
            $periods = DB::table('financial_periods')->lockForUpdate()->get();
            if ($periods->count() !== 1) throw new DomainException('clean_start_review_period_invalid');
            $period = $periods->first();
            $month = now('Asia/Aden')->format('Y-m');
            $calendar = app(FinancialClosePolicy::class)->open('water', $month, 'Asia/Aden');
            if ($period->scope_key !== 'water' || $period->month !== $month
                || $period->start_on !== $calendar['start'] || $period->next_on !== $calendar['next']
                || $period->state !== 'OPEN' || (string) $period->version !== '0') {
                throw new DomainException('clean_start_review_period_invalid');
            }
            if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                throw new DomainException('clean_start_issuance_lock_missing');
            }
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            CompletedCashRefundRequest::authenticated($actor);
            abort_unless($actor->roles()->lockForUpdate()->get()->contains(fn ($role) =>
                $role->name === 'super_admin' && $role->guard_name === 'web'), 403);
            if (! app(FinancialCleanStartAuditProtection::class)->active()
                || ! (new FinancialCleanStartAuditProtection(true))->active()) {
                throw new DomainException('clean_start_audit_protection_missing');
            }
            $audits = \App\Models\AuditEvent::query()->lockForUpdate()->get();
            if ($audits->count() !== 1) throw new DomainException('clean_start_review_audit_count');
            $initial = $audits->first();
            if ($initial->event !== 'financial.clean_start_initialized' || $initial->entity_type !== 'financial_scope'
                || $initial->entity_id !== 'water' || $initial->source !== 'internal'
                || (int) $initial->actor_user_id <= 0 || $initial->before !== null) {
                throw new DomainException('clean_start_review_audit_invalid');
            }
            if ((int) $initial->actor_user_id === (int) $actor->id) {
                throw new DomainException('clean_start_review_requires_independent_actor');
            }
            $expected = ['format' => 1, 'scope' => 'water', 'period_id' => (int) $period->id,
                'month' => $month, 'start_on' => $period->start_on, 'next_on' => $period->next_on,
                'initialization_mode' => 'LEGACY_DISABLED', 'checked_empty_tables' => self::EMPTY_TABLES,
                'root_opening_verified' => false, 'closure_authorized' => false];
            $actual = $initial->after;
            if (! is_array($actual)) throw new DomainException('clean_start_review_audit_invalid');
            ksort($actual, SORT_STRING);
            ksort($expected, SORT_STRING);
            if ($actual !== $expected) throw new DomainException('clean_start_review_audit_invalid');
            foreach (self::EMPTY_TABLES as $table) {
                if (in_array($table, ['audit_events', 'financial_scopes', 'financial_periods'], true)) continue;
                if (DB::table($table)->lockForUpdate()->first() !== null) {
                    throw new DomainException('clean_start_nonempty:'.$table);
                }
            }
            $result = ['format' => 1, 'scope' => 'water', 'period_id' => (int) $period->id,
                'month' => $month, 'initialized_by' => (int) $initial->actor_user_id,
                'initial_audit_id' => (int) $initial->id,
                'initial_evidence_hash' => hash('sha256', json_encode($expected, JSON_THROW_ON_ERROR)),
                'reviewed_by' => (int) $actor->id, 'empty_start_reviewed' => true,
                'root_opening_verified' => false, 'closure_authorized' => false];
            app(AuditLogger::class)->recordRequired('financial.clean_start_reviewed', [
                'actor_user_id' => (int) $actor->id, 'entity_type' => 'financial_scope',
                'entity_id' => 'water', 'source' => 'internal', 'after' => $result,
            ]);
            return $result;
        }, 1);
    }
}
