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
        return DB::transaction(fn () => $this->inspectLocked($actor, $periodId, $now), 1);
    }

    /** Recompute the actual evidence within the coordinator's outer transaction. */
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
        $writerCoverage = app(FinancialWriterCoverageDiagnostic::class)->inspect();
        $source = $this->sources->inspect($periodId);
        $movement = $this->movements->inspect($periodId);
        $heldPeriod = $this->heldPeriod->inspect($periodId);
        $settlement = $this->settlements->inspect($actor, $periodId, $through);
        $claims = $ended ? app(FinancialCollectionPeriodEvidence::class)->inspect($periodId) : null;
        $cashEvidence = $ended ? app(FinancialCashPeriodEvidence::class)->inspect($periodId) : null;
        $outstanding = $ended ? app(FinancialOutstandingPeriodEvidence::class)->inspect($periodId) : null;
        $incident = $this->refundIncidents();
        $batchCoverage = $this->refunds->verifyBatchAuditCoverage();
        $subscriber = $ended ? null : $this->subscribers->inspect($periodId);
        $subscriberCutoff = $ended ? app(FinancialSubscriberPeriodEvidence::class)->inspect($periodId) : null;
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
        if (! $writerCoverage['all_candidate_switches_enabled']) $blockers[] = 'candidate_writer_switches_incomplete';
        if (! $writerCoverage['listed_guards_active']) $blockers[] = 'financial_guard_inventory_unverified';
        if (! $writerCoverage['runtime_grant_allowlist_accepted']) $blockers[] = 'runtime_grant_allowlist_unverified';
        if (! $writerCoverage['runtime_close_control_write_isolated']) $blockers[] = 'runtime_close_control_write_isolation_unverified';
        if (! $ended) $blockers[] = 'period_not_ended';
        if (! $source['scanned_sources_match']) $blockers[] = 'document_registry_mismatch';
        if (! $movement['recorded_movements_verified']) $blockers[] = 'period_movements_unmatched';
        if ($claims !== null && ! $claims['recorded_evidence_matched']) $blockers[] = 'collection_cutoff_evidence_unmatched';
        if (! $settlement['scanned_settlements_match']) $blockers[] = 'settlement_sources_unmatched';
        if ($ended && ! $settlement['bank_period_evidence']['all_accounts_matched']) $blockers[] = 'bank_period_balances_unmatched';
        if ($cashEvidence !== null && ! $cashEvidence['all_accounts_matched']) $blockers[] = 'cash_period_balances_unmatched';
        if ($incident['count'] !== 0 || $incident['orphan_accounts'] !== []) $blockers[] = 'unresolved_refund_incidents';
        if ($incident['unposted_cash_refund_minor'] !== 0) $blockers[] = 'unposted_cash_refunds';
        if ($subscriber !== null && ! $subscriber['scanned_accounts_match']) $blockers[] = 'subscriber_accounts_unmatched';
        if ($continuity !== null && (! $continuity['adjacent_snapshots_match'] || ! $continuity['carry_snapshots_unchanged'])) {
            $blockers[] = 'snapshot_continuity_unmatched';
        }
        if (! app(LedgerProtection::class)->active()) $blockers[] = 'close_ledger_protection_missing';
        if (! app(FinancialCloseJournalProtection::class)->active()) $blockers[] = 'close_journal_protection_missing';
        if ($outstanding !== null && ! $outstanding['transit']['recorded_rollforward_verified']) $blockers[] = 'transit_period_unmatched';
        if ($outstanding !== null && ! $outstanding['custody']['recorded_rollforward_verified']) $blockers[] = 'custody_period_unmatched';
        if ($outstanding !== null && ! $outstanding['custody']['all_count_variances_resolved']) $blockers[] = 'custody_count_variances_unresolved';
        if ($outstanding !== null && $outstanding['adjacent_carry']['predecessor_period_id'] !== null
            && ! $outstanding['adjacent_carry']['recorded_adjacent_rollforward_verified']) $blockers[] = 'outstanding_adjacent_carry_unmatched';
        if ($subscriberCutoff !== null && ! $subscriberCutoff['scanned_cutoff_accounts_match']) $blockers[] = 'subscriber_cutoff_sources_unmatched';
        if ($subscriberCutoff !== null && $subscriberCutoff['prior_accounts'] !== []) $blockers[] = 'subscriber_predecessor_openings_unverified';
        if ($subscriberCutoff !== null && ! $subscriberCutoff['allocation_policy_verified']) $blockers[] = 'subscriber_allocation_policy_unverified';
        $accountMovements = $ended ? app(FinancialCutoffMovementReconciliation::class)->inspect(
            $cashEvidence, $settlement['bank_period_evidence'], $subscriberCutoff, $movement) : null;
        if ($accountMovements !== null && ! $accountMovements['matched']) $blockers[] = 'cutoff_account_movements_unmatched';
        $heldMovements = $ended ? app(FinancialCutoffMovementReconciliation::class)->inspectHeld($heldPeriod, $movement) : null;
        if ($heldMovements !== null && ! $heldMovements['matched']) $blockers[] = 'cutoff_held_movements_unmatched';
        $physicalMovements = $ended ? app(FinancialCutoffMovementReconciliation::class)->inspectPhysical($outstanding, $movement) : null;
        if ($physicalMovements !== null && ! $physicalMovements['matched']) $blockers[] = 'cutoff_physical_movements_unmatched';
        sort($blockers, SORT_STRING);
        $manifest = $ended ? $this->cutoffManifest($period, $cashEvidence,
            $settlement['bank_period_evidence'], $subscriberCutoff, $outstanding, $claims, $source, $movement, $accountMovements, $heldMovements, $physicalMovements, $settlement) : null;
        // Keep operational readiness separate from the financial cutoff fingerprint.
        // This inventory is diagnostic, including when its blockers are nonempty.
        $readiness = ['format_version' => 2, 'scope' => 'water', 'period_id' => $periodId,
            'fence_epoch' => (string) $scope->fence_epoch, 'active_freeze_uuid' => $scope->active_freeze_uuid,
            'calendar_version' => (string) $scope->calendar_version,
            'refund_incidents_hash' => hash('sha256', json_encode($incident, JSON_THROW_ON_ERROR)),
            'refund_batch_coverage_hash' => hash('sha256', json_encode($batchCoverage, JSON_THROW_ON_ERROR)),
            'period_state' => $period->state, 'period_version' => (string) $period->version,
            'as_of' => $today, 'cutoff_manifest_hash' => $manifest['manifest_hash'] ?? null,
            'writer_inventory_hash' => hash('sha256', json_encode($writerCoverage, JSON_THROW_ON_ERROR)),
            'blockers' => $blockers, 'closure_authorized' => false];
        $readiness['inventory_hash'] = hash('sha256', json_encode($readiness, JSON_THROW_ON_ERROR));
        return ['period_id' => $periodId, 'month' => $period->month, 'state' => $period->state,
            'as_of' => $today, 'settlement_through' => $through, 'period_ended' => $ended,
            'writer_coverage' => $writerCoverage, 'source' => $source, 'movements' => $movement, 'held_liabilities' => $heldPeriod, 'settlement' => $settlement, 'refund_incidents' => $incident, 'refund_batch_coverage' => $batchCoverage,
            'outstanding_period_evidence' => $outstanding, 'cash_period_evidence' => $cashEvidence, 'subscriber_period_evidence' => $subscriberCutoff, 'current_subscriber_diagnostic' => $subscriber, 'snapshot_continuity' => $continuity,
            'collection_period_evidence' => $claims,
            'cutoff_account_movements' => $accountMovements, 'cutoff_held_movements' => $heldMovements, 'cutoff_physical_movements' => $physicalMovements, 'cutoff_evidence_manifest' => $manifest, 'readiness_inventory' => $readiness, 'blockers' => $blockers, 'source_coverage_verified' => false,
            'period_end_balances_verified' => false, 'closure_authorized' => false];
    }

    /**
     * Read-only fingerprint of separately reconciled cutoff sectors. No grand
     * total: cash, transit, custody, liabilities and shortage exposure differ
     * in nature; pending payout authority is a subset, not an additional balance.
     * A fingerprint is not a signed close journal or independent opening proof.
     */
    private function cutoffManifest(object $period, array $cash, array $bank, array $subscriber, array $outstanding, array $claims,
        array $source, array $movement, array $accountMovements, array $heldMovements, array $physicalMovements, array $settlement): array
    {
        $sections = [
            'cash' => [$cash['all_accounts_matched'], $cash],
            'bank' => [$bank['all_accounts_matched'], $bank],
            'subscriber' => [$subscriber['all_cutoff_accounts_match'] && $subscriber['allocation_policy_verified'], $subscriber],
            'transit' => [$outstanding['transit']['recorded_rollforward_verified'], $outstanding['transit']],
            'custody' => [$outstanding['custody']['recorded_rollforward_verified'], $outstanding['custody']],
            'held_liability' => [$outstanding['liability']['recorded_rollforward_verified'], $outstanding['liability']],
            'variance_exposure' => [$outstanding['custody']['recorded_rollforward_verified'], $outstanding['variance_exposure']],
            'collection_claims' => [$claims['recorded_evidence_matched'], $claims],
        ];
        $sectors = [];
        $matched = true;
        foreach ($sections as $key => [$verified, $evidence]) {
            $verified = $verified === true;
            $matched = $matched && $verified;
            $sectors[$key] = ['recorded_evidence_matched' => $verified,
                'row_count' => $verified ? count($evidence['rows']) : null,
                'source_hash' => $verified ? hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR)) : null];
        }
        // Bind exact registered movement identity, not just matching sector totals.
        $registryMatched = $source['scanned_sources_match'] === true
            && $source['unverified_registry_legs'] === 0
            && $source['expected_legs'] === $source['matched_legs']
            && $source['matched_legs'] === $source['registry_events']
            && $movement['recorded_movements_verified'] === true
            && $movement['registry_events'] === $source['registry_events']
            && is_string($movement['movement_hash'])
            && preg_match('/\\A[0-9a-f]{64}\\z/', $movement['movement_hash']) === 1;
        $registry = ['recorded_movements_matched' => $registryMatched,
            'registry_events' => $source['registry_events'],
            'movement_hash' => $registryMatched ? $movement['movement_hash'] : null];
        // Settlement documents are independent of balance and movement totals.
        // Pending or unmatched settlement evidence must not yield a complete fingerprint.
        $settlementMatched = $settlement['scanned_settlements_match'] === true
            && $settlement['shortage_settlements']['scanned_documents_match'] === true
            && $settlement['shortage_settlements']['pending_count'] === 0;
        $manifest = ['format_version' => 7, 'scope' => 'water', 'period_id' => (int) $period->id,
            'start_on' => $period->start_on, 'next_on' => $period->next_on,
            'sectors' => $sectors, 'all_listed_sectors_matched' => $matched,
            'registry' => $registry,
            'settlement_evidence_matched' => $settlementMatched,
            'settlement_evidence_hash' => $settlementMatched ? hash('sha256', json_encode($settlement, JSON_THROW_ON_ERROR)) : null,
            'physical_movements_hash' => $physicalMovements['matched'] ? hash('sha256', json_encode($physicalMovements, JSON_THROW_ON_ERROR)) : null,
            'held_movements_hash' => $heldMovements['matched'] ? hash('sha256', json_encode($heldMovements, JSON_THROW_ON_ERROR)) : null,
            'account_movements_hash' => $accountMovements['matched'] ? hash('sha256', json_encode($accountMovements, JSON_THROW_ON_ERROR)) : null,
            'independent_opening_verified' => false, 'closure_authorized' => false];
        return $manifest + ['manifest_hash' => $matched && $registryMatched && $settlementMatched && $accountMovements['matched'] && $heldMovements['matched'] && $physicalMovements['matched'] ? hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR)) : null];
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
