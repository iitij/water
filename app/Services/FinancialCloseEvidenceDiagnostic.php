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
    ) {}

    public function inspect(User $actor, int $periodId, DateTimeImmutable $now): array
    {
        CompletedCashRefundRequest::authenticated($actor);
        abort_unless(app()->environment(['testing', 'staging']), 503);
        if ($periodId <= 0 || DB::transactionLevel() !== 0) {
            throw new DomainException('close_evidence_requires_outer_gate');
        }
        $today = $now->setTimezone(new DateTimeZone('Asia/Aden'))->format('Y-m-d');
        return DB::transaction(function () use ($actor, $periodId, $today): array {
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
            app(TreasuryRegisterService::class)->authorize($actor);
            $ended = $today >= $period->next_on;
            $through = $ended
                ? (new DateTimeImmutable($period->next_on, new DateTimeZone('Asia/Aden')))->modify('-1 day')->format('Y-m-d')
                : $today;
            $source = $this->sources->inspect($periodId);
            $movement = $this->movements->inspect($periodId);
            $heldPeriod = $this->heldPeriod->inspect($periodId);
            $settlement = $this->settlements->inspect($actor, $periodId, $through);
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
            if ($incident['count'] !== 0 || $incident['orphan_accounts'] !== []) $blockers[] = 'unresolved_refund_incidents';
            if ($incident['unposted_cash_refund_minor'] !== 0) $blockers[] = 'unposted_cash_refunds';
            if ($subscriber !== null && ! $subscriber['scanned_accounts_match']) $blockers[] = 'subscriber_accounts_unmatched';
            if ($continuity !== null && (! $continuity['adjacent_snapshots_match'] || ! $continuity['carry_snapshots_unchanged'])) {
                $blockers[] = 'snapshot_continuity_unmatched';
            }
            if (! app(FinancialCloseJournalProtection::class)->active()) $blockers[] = 'close_journal_protection_missing';
            sort($blockers, SORT_STRING);
            return ['period_id' => $periodId, 'month' => $period->month, 'state' => $period->state,
                'as_of' => $today, 'settlement_through' => $through, 'period_ended' => $ended,
                'source' => $source, 'movements' => $movement, 'held_liabilities' => $heldPeriod, 'settlement' => $settlement, 'refund_incidents' => $incident, 'refund_batch_coverage' => $batchCoverage,
                'current_subscriber_diagnostic' => $subscriber, 'snapshot_continuity' => $continuity,
                'blockers' => $blockers, 'source_coverage_verified' => false,
                'period_end_balances_verified' => false, 'closure_authorized' => false];
        }, 1);
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
