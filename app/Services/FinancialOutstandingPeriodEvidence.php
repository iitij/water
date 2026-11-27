<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CashHandover;
use App\Models\CashSession;
use App\Models\Payment;
use App\Models\Transaction;
use App\Support\CashCustodyAmounts as Cash;
use App\Support\DecimalDomain;
use App\Support\SignedMinor;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Cutoff evidence for distinct transit, physical custody and held liabilities.
 * No inferred predecessor, historical repair, due date or close authority.
 */
final class FinancialOutstandingPeriodEvidence
{
    private array $checkedPeriods = [];

    public function __construct(private FinancialPostingRegistry $registry,
        private TreasuryTransferEvidence $transfers,
        private TreasuryCustodyEvidence $custody,
        private FinancialHeldLiabilityPeriodDiagnostic $held) {}

    public function inspect(int $periodId): array
    {
        if (DB::transactionLevel() <= 0) throw new DomainException('outstanding_period_requires_gate');
        $this->checkedPeriods = [];
        $this->registry->verifyPeriod('water', $periodId);
        $period = DB::table('financial_periods')->where('scope_key', 'water')->where('id', $periodId)->lockForUpdate()->sole();
        $start = $period->start_on;
        $next = $period->next_on;
        $base = ['period_id' => $periodId, 'period_ended' => now('Asia/Aden')->toDateString() >= $next,
            'carry_evidence_verified' => false, 'closure_authorized' => false];
        if (! $base['period_ended']) return $base + ['transit' => null, 'custody' => null, 'liability' => null];
        if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
            throw new DomainException('outstanding_period_issuance_lock');
        }
        $transit = $this->transit($start, $next);
        $custody = $this->custody($start, $next);
        $held = $this->held->inspect($periodId);
        $totals = $held['totals'];
        $liability = ['rows' => $held['rows'],
            'identified_minor' => $totals['recorded_closing_identified_minor'],
            'unidentified_minor' => $totals['recorded_closing_unidentified_minor'],
            'pending_payout_subset_minor' => $totals['recorded_closing_pending_payout_minor'],
            'closing_minor' => SignedMinor::add($totals['recorded_closing_identified_minor'],
                $totals['recorded_closing_unidentified_minor']),
            'recorded_rollforward_verified' => $held['registered_held_subledger_rollforward_verified'],
            'all_liabilities_verified' => false];
        // Expose gross unresolved variances separately. A count shortage is
        // not by itself an enforceable receivable, and a liquidity restriction
        // is not a second liability. Never net a shortage against a surplus.
        $variance = ['unresolved_shortage_minor' => 0, 'unallocated_surplus_minor' => 0,
            'surplus_liquidity_reserve_minor' => 0, 'rows' => [], 'settlement_coverage_verified' => false];
        foreach ($custody['rows'] as $row) {
            $source = $row['source_evidence'];
            if ($source['variance_minor'] === 0) continue;
            $disposition = $source['surplus_disposition'] ?? null;
            $shortage = $source['shortage_minor'];
            $surplus = $disposition['unallocated_minor'] ?? $source['surplus_minor'];
            $reserved = $disposition['liquidity_reserve']['reserved_minor'] ?? 0;
            $variance['unresolved_shortage_minor'] = SignedMinor::add($variance['unresolved_shortage_minor'], $shortage);
            $variance['unallocated_surplus_minor'] = SignedMinor::add($variance['unallocated_surplus_minor'], $surplus);
            $variance['surplus_liquidity_reserve_minor'] = SignedMinor::add($variance['surplus_liquidity_reserve_minor'], $reserved);
            $variance['rows'][] = ['session_id' => $row['session_id'], 'collector_id' => $row['collector_id'],
                'shortage_minor' => $shortage, 'unallocated_surplus_minor' => $surplus,
                'reserved_liquidity_minor' => $reserved, 'recoverable_recognized' => false];
        }
        return $base + ['transit' => $transit, 'custody' => $custody, 'liability' => $liability,
            'variance_exposure' => $variance];
    }

    private function registered(object $posting, array $leg): string
    {
        return $this->registeredLeg($posting->effective_on, $leg);
    }

    private function registeredLeg(string $date, array $leg): string
    {
        $event = DB::table('financial_posting_events as e')
            ->join('financial_posting_operations as o', 'o.operation_uuid', '=', 'e.operation_uuid')
            ->where('e.scope_key', 'water')->where('e.source_type', $leg['source_type'])
            ->where('e.source_id', $leg['source_id'])->where('e.leg', $leg['leg'])
            ->select('e.*', 'o.period_id', 'o.effective_on')->lockForUpdate()->first();
        if (! $event || $event->effective_on !== $date
            || SignedMinor::parse($event->amount_minor) !== $leg['amount_minor']
            || $event->currency !== $leg['currency'] || ! hash_equals($leg['source_hash'], $event->source_hash)) {
            throw new DomainException('outstanding_source_unregistered');
        }
        $id = (int) $event->period_id;
        if (! isset($this->checkedPeriods[$id])) {
            $this->registry->verifyPeriod('water', $id);
            $this->checkedPeriods[$id] = true;
        }
        return $event->source_hash;
    }

    private function transit(string $start, string $next): array
    {
        $rows = $issues = [];
        foreach (DB::table('treasury_transfers')->where('dispatched_on', '<', $next)
            ->orderBy('id')->lockForUpdate()->get() as $transfer) {
            $id = (int) $transfer->id;
            $receipt = DB::table('treasury_transfer_receipts')->where('treasury_transfer_id', $id)->lockForUpdate()->first();
            if ($receipt && $receipt->received_on < $start && $transfer->dispatched_on < $start) continue;
            try {
                $dispatch = DB::table('treasury_postings')->where('treasury_transfer_id', $id)->lockForUpdate()->first();
                if (! $dispatch) throw new DomainException('transit_dispatch_missing');
                $hash = $this->registered($dispatch, $this->transfers->leg((int) $dispatch->id));
                $amount = SignedMinor::parseUnsigned($transfer->amount_minor);
                $received = $receipt && $receipt->received_on < $next;
                $receiptHash = null;
                if ($received) {
                    $posting = DB::table('treasury_postings')->where('treasury_transfer_receipt_id', $receipt->id)->lockForUpdate()->first();
                    if (! $posting) throw new DomainException('transit_receipt_missing');
                    $receiptHash = $this->registered($posting, $this->transfers->leg((int) $posting->id));
                }
                $opening = $transfer->dispatched_on < $start ? $amount : 0;
                $sent = $opening === 0 ? $amount : 0;
                $arrived = $received ? $amount : 0;
                $closing = SignedMinor::subtract(SignedMinor::add($opening, $sent), $arrived);
                $rows[] = ['transfer_id' => $id, 'from_account_id' => (int) $transfer->from_account_id,
                    'to_account_id' => (int) $transfer->to_account_id, 'sender_id' => (int) $transfer->dispatched_by,
                    'opening_minor' => $opening, 'dispatched_minor' => $sent, 'received_minor' => $arrived,
                    'closing_minor' => $closing, 'dispatch_hash' => $hash, 'receipt_hash' => $receiptHash];
            } catch (DomainException $error) {
                $issues[] = ['code' => $error->getMessage(), 'transfer_id' => $id];
            }
        }
        return $this->section($rows, $issues);
    }

    private function custody(string $start, string $next): array
    {
        $rows = $issues = [];
        $zone = new DateTimeZone('Asia/Aden');
        $utc = new DateTimeZone('UTC');
        $from = (new DateTimeImmutable($start, $zone))->setTimezone($utc)->format('Y-m-d H:i:s');
        $until = (new DateTimeImmutable($next, $zone))->setTimezone($utc)->format('Y-m-d H:i:s');
        foreach (CashSession::query()->where('session_date', '<', $next)->orderBy('id')->lockForUpdate()->get() as $session) {
            $id = (int) $session->id;
            $driver = $session->getConnection()->getDriverName();
            $handovers = CashHandover::query()->where('cash_session_id', $id)->orderBy('id')->lockForUpdate()->get();
            $priorReceived = 0;
            foreach ($handovers as $handover) {
                if ($handover->received_on->toDateString() < $start) {
                    $priorReceived = SignedMinor::add($priorReceived, Cash::positiveStored(
                        $handover->getRawOriginal('amount'), $driver, DecimalDomain::D14));
                }
            }
            try {
                $approved = $session->getRawOriginal('approved_at');
                $closed = $session->getRawOriginal('closed_at');
                if (! $session->isApproved() || $session->settlement_basis !== 'physical_cash'
                    || ! $approved || ! $closed || $approved >= $until || $closed > $approved
                    || (int) $session->approved_by <= 0 || (int) $session->approved_by === (int) $session->user_id) {
                    throw new DomainException('custody_period_independent_count_missing');
                }
                $target = Cash::stored($session->getRawOriginal('counted_amount'), $driver, DecimalDomain::D14);
                if ($approved < $from && $priorReceived === $target
                    && Cash::stored($session->getRawOriginal('variance_amount'), $driver, signed: true) === 0) continue;
                $audits = AuditEvent::query()->where('event', 'cash_session.approved')->where('entity_type', CashSession::class)
                    ->where('entity_id', (string) $id)->lockForUpdate()->get();
                if ($audits->count() !== 1 || (int) $audits[0]->actor_user_id !== (int) $session->approved_by
                    || ($audits[0]->after['counted_amount'] ?? null) !== SignedMinor::decimal($target)
                    || ($audits[0]->after['state'] ?? null) !== CashSession::STATE_APPROVED
                    || $audits[0]->getRawOriginal('occurred_at') >= $until) {
                    throw new DomainException('custody_period_approval_unverified');
                }
                $source = $this->sessionSources($session, $audits[0], $target, $next);
                $received = 0;
                $hashes = [];
                foreach ($handovers as $handover) {
                    $date = $handover->received_on->toDateString();
                    if ($date >= $next) continue;
                    $posting = DB::table('treasury_postings')->where('cash_handover_id', $handover->id)->lockForUpdate()->first();
                    if (! $posting) throw new DomainException('custody_period_handover_unposted');
                    $hashes[] = $this->registered($posting, $this->custody->leg((int) $posting->id));
                    if ($date >= $start) $received = SignedMinor::add($received, Cash::positiveStored(
                        $handover->getRawOriginal('amount'), $driver, DecimalDomain::D14));
                }
                $opening = $approved < $from ? SignedMinor::subtract($target, $priorReceived) : 0;
                $recognized = $approved < $from ? 0 : $target;
                if ($approved >= $from && $priorReceived !== 0) throw new DomainException('custody_period_date_conflict');
                $closing = SignedMinor::subtract(SignedMinor::add($opening, $recognized), $received);
                if (min($opening, $closing) < 0) throw new DomainException('custody_period_conservation');
                if ($source['variance_minor'] > 0) {
                    $source['surplus_disposition'] = $this->surplusDisposition($session, $audits[0],
                        $handovers, $source['variance_minor'], $target, $next);
                    $source['variance_disposition_verified'] =
                        $source['surplus_disposition']['unallocated_minor'] === 0;
                }
                $rows[] = ['session_id' => $id, 'collector_id' => (int) $session->user_id,
                    'approval_audit_id' => (int) $audits[0]->id, 'opening_minor' => $opening,
                    'recognized_counted_minor' => $recognized, 'received_minor' => $received,
                    'closing_minor' => $closing, 'handover_hashes' => $hashes,
                    'collection_coverage_verified' => true, 'source_evidence' => $source];
            } catch (DomainException $error) {
                $issues[] = ['code' => $error->getMessage(), 'session_id' => $id];
            }
        }
        return $this->section($rows, $issues) + ['all_count_variances_resolved' => $issues === [] && ! array_filter($rows,
            fn ($row) => ! $row['source_evidence']['variance_disposition_verified'])];
    }


    /** Revalidate the original cash-only till, including funded opening and
     * ordinary pre-close voids. Unsupported settlements remain explicit
     * blockers; an approved count alone is not collection coverage.
     */
    private function sessionSources(CashSession $session, AuditEvent $approval, int $counted, string $next): array
    {
        $id = (int) $session->id;
        $driver = $session->getConnection()->getDriverName();
        $expected = Cash::stored($session->getRawOriginal('expected_amount'), $driver, nullable: true);
        $variance = Cash::stored($session->getRawOriginal('variance_amount'), $driver, signed: true, nullable: true);
        $opening = Cash::stored($session->getRawOriginal('opening_amount'), $driver);
        if (! Cash::consistent($counted, $expected, $variance)
            || ($approval->after['expected_amount'] ?? null) !== SignedMinor::decimal($expected)
            || ($approval->after['variance_amount'] ?? null) !== SignedMinor::decimal($variance)) {
            throw new DomainException('custody_period_count_sources_mismatch');
        }
        if (DB::table('closed_cash_corrections')->where('cash_session_id', $id)->lockForUpdate()->first()
            || ($variance <= 0 && DB::table('late_receipt_reviews')->where('cash_session_id', $id)->lockForUpdate()->first())) {
            throw new DomainException('custody_period_special_settlement_requires_proof');
        }
        $fundings = DB::table('cash_session_fundings')->where('cash_session_id', $id)->lockForUpdate()->get();
        if ($fundings->count() !== ($opening > 0 ? 1 : 0)) {
            throw new DomainException('custody_period_funding_unverified');
        }
        $fundingHash = null;
        if ($opening > 0) {
            $fund = $fundings->first();
            $posting = DB::table('treasury_postings')->where('cash_session_funding_id', $fund->id)->lockForUpdate()->first();
            if (! $posting || $fund->issued_on >= $next) throw new DomainException('custody_period_funding_unverified');
            $leg = app(TreasuryOpeningFundingEvidence::class)->funding((int) $posting->id, $fund->issued_on);
            if ($leg['amount_minor'] !== -$opening) throw new DomainException('custody_period_funding_unverified');
            $fundingHash = $this->registered($posting, $leg);
        }
        $total = $opening;
        $collections = $voids = 0;
        $hashes = [];
        $zone = new DateTimeZone('Asia/Aden');
        $utc = new DateTimeZone('UTC');
        $closed = new DateTimeImmutable($session->getRawOriginal('closed_at'), $utc);
        foreach (Payment::withTrashed()->where('cash_session_id', $id)->orderBy('id')->lockForUpdate()->get() as $payment) {
            $date = $payment->payment_date->toDateString();
            if ($payment->trashed() || $payment->hasPendingVoidRequest() || $payment->payment_method !== 'cash'
                || (int) $payment->user_id !== (int) $session->user_id
                || $date < $session->session_date->toDateString() || $date > $closed->setTimezone($zone)->format('Y-m-d')
                || $date >= $next) {
                throw new DomainException('custody_period_collection_unverified');
            }
            $entries = Transaction::withTrashed()->where('reference_type', Payment::class)
                ->where('reference_id', $payment->id)->where('type', 'payment')->lockForUpdate()->get();
            if ($entries->count() !== 1) throw new DomainException('custody_period_collection_unverified');
            $leg = app(FinancialLedgerEvidence::class)->leg((int) $entries[0]->id);
            $hashes[] = $this->registeredLeg($date, $leg);
            $collections = SignedMinor::add($collections, $leg['amount_minor']);
            if ($payment->isVoided()) {
                $void = new DateTimeImmutable($payment->getRawOriginal('voided_at'), $utc);
                if (! in_array($payment->voided_via, ['gated', 'approval'], true) || $void > $closed
                    || $void->setTimezone($zone)->format('Y-m-d') < $date) {
                    throw new DomainException('custody_period_void_unverified');
                }
                $reversals = Transaction::withTrashed()->where('reference_type', Payment::class)
                    ->where('reference_id', $payment->id)->where('type', 'payment_reversal')->lockForUpdate()->get();
                if ($reversals->count() !== 1) throw new DomainException('custody_period_void_unverified');
                $reverse = app(FinancialLedgerEvidence::class)->leg((int) $reversals[0]->id);
                if ($reverse['amount_minor'] !== -$leg['amount_minor']) throw new DomainException('custody_period_void_unverified');
                $hashes[] = $this->registeredLeg($void->setTimezone($zone)->format('Y-m-d'), $reverse);
                $voids = SignedMinor::add($voids, $leg['amount_minor']);
            }
        }
        $total = SignedMinor::add($total, SignedMinor::subtract($collections, $voids));
        if ($total !== $expected) throw new DomainException('custody_period_count_sources_mismatch');
        return ['funding_minor' => $opening, 'gross_collections_minor' => $collections, 'voided_minor' => $voids,
            'expected_minor' => $expected, 'variance_minor' => $variance,
            'shortage_minor' => $variance < 0 ? SignedMinor::negate($variance) : 0,
            'surplus_minor' => max(0, $variance), 'funding_hash' => $fundingHash, 'collection_hashes' => $hashes,
            'variance_disposition_verified' => $variance === 0];
    }

    /** Surplus attribution changes ownership, not the physical cash total.
     * A conservative reserve remains a restriction on liquidity, not an
     * additional liability once the same cash has been attributed.
     */
    private function surplusDisposition(CashSession $session, AuditEvent $approval, iterable $handovers,
        int $variance, int $counted, string $next): array
    {
        $allocation = app(GatedCountedCashWriter::class)->verifySessionThrough((int) $session->id, $next);
        if ($allocation['capacity_minor'] !== $variance) throw new DomainException('custody_period_surplus_capacity_mismatch');
        $received = [];
        foreach ($handovers as $handover) {
            if ($handover->received_on->toDateString() < $next) $received[] = $handover;
        }
        $reserve = null;
        if ($received !== []) {
            if (count($received) !== 1) throw new DomainException('custody_period_surplus_handover_mismatch');
            $handover = $received[0];
            $posting = DB::table('treasury_postings')->where('cash_handover_id', $handover->id)->lockForUpdate()->sole();
            $audit = AuditEvent::query()->where('event', 'financial.handover_surplus_reserved')
                ->where('entity_type', CashHandover::class)->where('entity_id', (string) $handover->id)
                ->lockForUpdate()->first();
            $until = (new DateTimeImmutable($next, new DateTimeZone('Asia/Aden')))
                ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            if (! $audit || $audit->getRawOriginal('occurred_at') >= $until
                || ($audit->after['approval_id'] ?? null) !== (int) $approval->id
                || ($audit->after['reserved_minor'] ?? null) !== $variance
                || SignedMinor::parse($posting->amount_minor) !== $counted
                || app(HandoverSurplusReservation::class)->forAccount((int) $posting->treasury_account_id) < $variance) {
                throw new DomainException('custody_period_surplus_reserve_unverified');
            }
            $reserve = ['account_id' => (int) $posting->treasury_account_id,
                'handover_id' => (int) $handover->id, 'audit_id' => (int) $audit->id, 'reserved_minor' => $variance];
        }
        return ['capacity_minor' => $variance, 'allocated_minor' => $allocation['allocated_minor'],
            'unallocated_minor' => $allocation['unallocated_minor'], 'allocations' => $allocation['allocations'],
            'liquidity_reserve' => $reserve, 'new_cash_movement' => false, 'reserve_release_authorized' => false];
    }

    private function section(array $rows, array $issues): array
    {
        return ['recorded_rollforward_verified' => $issues === [],
            'rows' => $rows, 'issues' => $issues,
            'recorded_closing_minor' => SignedMinor::sum(array_column($rows, 'closing_minor')),
            'independent_opening_verified' => false, 'carry_evidence_verified' => false];
    }
}
