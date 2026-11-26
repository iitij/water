<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use App\Support\FinancialClosePolicy;
use App\Support\SignedMinor;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Read-only prospective close evidence inventory. Never a close capability.
 * Current balances are not period-end balances. Missing root evidence is
 * reported, not inferred or reconstructed. All checks share one scope lock.
 */
final class FinancialCloseEvidenceDiagnostic
{
    public function __construct(
        private FinancialSourceCoverageDiagnostic $sources,
        private FinancialSettlementCoverageDiagnostic $settlements,
        private FinancialSubscriberCoverageDiagnostic $subscribers,
        private FinancialOpeningContinuityDiagnostic $continuity,
        private CompletedCashRefundRequest $refunds,
        private HeldCashReservation $reservation,
        private FinancialPeriodMovementDiagnostic $movements,
        private FinancialHeldLiabilityPeriodDiagnostic $heldPeriod,
        private TreasuryCashCountService $cashCounts,
        private FinancialTransitPeriodDiagnostic $transit,
        private FinancialApprovedCustodyPeriodDiagnostic $approvedCustody,
    ) {}

    public function inspect(User $actor, int $periodId, DateTimeImmutable $now): array
    {
        CompletedCashRefundRequest::authenticated($actor);
        abort_unless(app()->environment(['testing', 'staging']), 503);
        if ($periodId <= 0 || DB::transactionLevel() !== 0) {
            throw new DomainException('close_evidence_requires_outer_gate');
        }
        return DB::transaction(fn () => $this->inspectLocked($actor, $periodId, $now), 1);
    }

    /** Re-derive the report inside the coordinator's existing scope-first
     * transaction. Do not open or commit an independent transaction. */
    public function inspectLocked(User $actor, int $periodId, DateTimeImmutable $now): array
    {
        CompletedCashRefundRequest::authenticated($actor);
        abort_unless(app()->environment(['testing', 'staging']), 503);
        if ($periodId <= 0 || DB::transactionLevel() !== 1) {
            throw new DomainException('close_evidence_requires_locked_transaction');
        }
        $today = $now->setTimezone(new DateTimeZone('Asia/Aden'))->format('Y-m-d');
        $scope = DB::table('financial_scopes')->where('scope_key', 'water')->lockForUpdate()->first();
        if (! $scope || $scope->mode !== 'ACTIVE' || $scope->currency !== 'YER'
            || $scope->timezone !== 'Asia/Aden' || (string) $scope->calendar_version !== '1') {
            throw new DomainException('close_scope_inactive');
        }
        $periods = DB::table('financial_periods')->where('scope_key', 'water')->orderBy('id')->lockForUpdate()->get();
        $period = $periods->first(fn ($row) => (int) $row->id === $periodId);
        if (! $period) throw new DomainException('close_period_missing_or_invalid');
        $calendar = app(FinancialClosePolicy::class)->open('water', $period->month, $scope->timezone);
        if ($period->start_on !== $calendar['start'] || $period->next_on !== $calendar['next']
            || ! in_array($period->state, ['OPEN', 'FROZEN', 'CLOSED'], true) || $today < $period->start_on) {
            throw new DomainException('close_period_missing_or_invalid');
        }
        if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
            throw new DomainException('close_evidence_issuance_lock');
        }
        $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
        CompletedCashRefundRequest::authenticated($actor);
        $actor->setRelation('roles', $actor->roles()->lockForUpdate()->get());
        app(TreasuryRegisterService::class)->authorize($actor);
        $ended = $today >= $period->next_on;
        $through = $ended
            ? (new DateTimeImmutable($period->next_on, new DateTimeZone('Asia/Aden')))->modify('-1 day')->format('Y-m-d')
            : $today;
        $source = $this->sources->inspect($periodId);
        $movement = $this->movements->inspect($periodId);
        $heldPeriod = $this->heldPeriod->inspect($periodId);
        $settlement = $this->settlements->inspect($actor, $periodId, $through);
        $bankBalances = $ended ? $this->bankPeriodBalances($settlement, $movement) : null;
        $cashObservations = $ended ? $this->cashPeriodObservations($period->next_on, $movement['recorded_movements_verified']) : null;
        $transit = $ended ? ($movement['recorded_movements_verified']
            ? $this->transit->inspect($period->start_on, $period->next_on)
            : ['matched' => false, 'issues' => [['code' => 'transit_period_movements_unverified']], 'totals' => null, 'rows' => []]) : null;
        $approvedCustody = $ended ? $this->approvedCustody->inspect($period->start_on, $period->next_on) : null;
        $incident = $this->refundIncidents();
        $batchCoverage = $this->refunds->verifyBatchAuditCoverage();
        $subscriber = $ended ? null : $this->subscribers->inspect($periodId);
        $continuity = null;
        if ($period->state === 'CLOSED' || $period->state === 'FROZEN') {
            $state = app(FinancialCloseJournalReader::class)->inspect('water', $periodId)['state'];
            if ($state['state'] === 'CLOSED' || is_array($state['attempt']['prepared'] ?? null)) {
                $continuity = $this->continuity->inspect($periodId);
            }
        }
        $blockers = ['complete_writer_coverage_unverified', 'independent_opening_source_unverified',
            'period_end_balances_unverified', 'carry_source_evidence_unverified',
            'trusted_close_proof_not_bound'];
        if (! $ended) $blockers[] = 'period_not_ended';
        if (! $source['scanned_sources_match']) $blockers[] = 'document_registry_mismatch';
        if (! $movement['recorded_movements_verified']) $blockers[] = 'period_movements_unmatched';
        if (! $settlement['scanned_settlements_match']) $blockers[] = 'settlement_sources_unmatched';
        if ($bankBalances !== null && ! $bankBalances['matched']) $blockers[] = 'bank_period_balances_unmatched';
        if ($cashObservations !== null && ! $cashObservations['matched']) $blockers[] = 'cash_period_observations_unmatched';
        if ($transit !== null && ! $transit['matched']) $blockers[] = 'transit_period_unmatched';
        if ($approvedCustody !== null && ! $approvedCustody['matched']) $blockers[] = 'approved_custody_period_unmatched';
        if ($incident['count'] !== 0 || $incident['orphan_accounts'] !== []) $blockers[] = 'unresolved_refund_incidents';
        if ($incident['unposted_cash_refund_minor'] !== 0) $blockers[] = 'unposted_cash_refunds';
        if ($subscriber !== null && ! $subscriber['scanned_accounts_match']) $blockers[] = 'subscriber_accounts_unmatched';
        if ($continuity !== null && (! $continuity['adjacent_snapshots_match'] || ! $continuity['carry_snapshots_unchanged'])) {
            $blockers[] = 'snapshot_continuity_unmatched';
        }
        if (! app(FinancialCloseJournalProtection::class)->active()) $blockers[] = 'close_journal_protection_missing';
        if (! app(FinancialPostingRegistryProtection::class)->active()) $blockers[] = 'close_registry_protection_missing';
        if (! app(FinancialSourceInsertProtection::class)->active()) $blockers[] = 'close_source_fences_missing';
        if (! app(LedgerProtection::class)->active()) $blockers[] = 'close_ledger_protection_missing';
        sort($blockers, SORT_STRING);
        return ['period_id' => $periodId, 'month' => $period->month, 'state' => $period->state,
            'as_of' => $today, 'settlement_through' => $through, 'period_ended' => $ended,
            'source' => $source, 'movements' => $movement, 'held_liabilities' => $heldPeriod, 'settlement' => $settlement, 'refund_incidents' => $incident, 'refund_batch_coverage' => $batchCoverage,
            'bank_period_balances' => $bankBalances, 'cash_period_observations' => $cashObservations,
            'transit_period' => $transit, 'approved_counted_custody_period' => $approvedCustody,
            'current_subscriber_diagnostic' => $subscriber, 'snapshot_continuity' => $continuity,
            'blockers' => $blockers, 'source_coverage_verified' => false,
            'period_end_balances_verified' => false, 'closure_authorized' => false];
    }

    /**
     * Match the bank statement endpoints against registered in-period effects.
     * The first statement opening for a new account is its documented opening
     * on the account's birth date, which can differ from the period start.
     */
    private function bankPeriodBalances(array $settlement, array $movement): array
    {
        if (! $settlement['bank_statements_match'] || ! $movement['recorded_movements_verified']) {
            return ['matched' => false, 'rows' => []];
        }

        $bankMovements = collect($movement['rows'])->where('sector', 'bank')->keyBy('key');
        $rows = [];
        foreach ($settlement['bank_statement_balances'] as $balance) {
            $key = $balance['account_id'];
            $registered = $bankMovements->pull($key);
            $openingDocument = $registered['recorded_opening_document_minor'] ?? 0;
            $net = $registered['net_movement_minor'] ?? 0;
            $openedInPeriod = $balance['account_opening_on'] >= $settlement['start_on'];
            if (($openedInPeriod && ($registered === null || $openingDocument !== $balance['statement_opening_minor']))
                || (! $openedInPeriod && $openingDocument !== 0)
                || SignedMinor::add($balance['statement_opening_minor'], $net) !== $balance['statement_closing_minor']) {
                return ['matched' => false, 'rows' => []];
            }
            $rows[] = $balance + [
                'registered_opening_document_minor' => $openedInPeriod ? $openingDocument : null,
                'registered_net_movement_minor' => $net,
            ];
        }

        $matched = $bankMovements->isEmpty();

        return ['matched' => $matched, 'rows' => $matched ? $rows : []];
    }

    private function cashPeriodObservations(string $nextOn, bool $movementsVerified): array
    {
        if (! $movementsVerified) {
            return ['matched' => false, 'rows' => [], 'unverified_accounts' => []];
        }

        $accounts = DB::table('treasury_accounts')->where('channel', 'cash')->where('opening_on', '<', $nextOn)
            ->orderBy('id')->lockForUpdate()->get();
        $rows = [];
        $unverified = [];
        foreach ($accounts as $account) {
            $observation = $this->cashCounts->periodEndObservation((int) $account->id, $nextOn);
            if ($observation === null) {
                $unverified[] = (string) $account->id;
            } else {
                $rows[] = $observation;
            }
        }

        return ['matched' => $unverified === [], 'rows' => $unverified === [] ? $rows : [],
            'unverified_accounts' => $unverified];
    }

    /** Scope-wide, deliberately not netted or restricted to the target month:
     * unresolved factual disbursements must remain visible until resolved.
     */
    private function refundIncidents(): array
    {
        $accounts = [];
        foreach (AuditEvent::query()->where('event', CompletedCashRefundRequest::EVENT)
            ->orderBy('id')->lockForUpdate()->get() as $request) {
            $proof = $request->after;
            $id = $proof['account_id'] ?? null;
            if (! is_int($id) || $id <= 0 || $request->entity_type !== 'completed_cash_refund_request') {
                throw new DomainException('completed_cash_refund_request_conflict');
            }
            $accounts[$id] = true;
        }
        ksort($accounts, SORT_NUMERIC);
        $count = $claimed = 0;
        $orphans = $rows = [];
        foreach (array_keys($accounts) as $id) {
            $account = DB::table('treasury_accounts')->where('id', $id)->lockForUpdate()->first();
            if (! $account || $account->channel !== 'cash') $orphans[] = $id;
            $result = $this->refunds->unresolvedForAccount($id);
            $count = SignedMinor::add($count, $result['count']);
            $claimed = SignedMinor::add($claimed, $result['claimed_minor']);
            $rows[] = ['account_id' => $id] + $result;
        }
        return ['scope_wide' => true, 'accounts' => $rows, 'orphan_accounts' => $orphans,
            'count' => $count, 'claimed_minor' => $claimed,
            'unposted_cash_refund_minor' => $this->reservation->unpostedCashRefundMinor()];
    }
}
