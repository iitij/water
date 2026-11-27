<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CashHandover;
use App\Models\CashSession;
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
        return $base + ['transit' => $transit, 'custody' => $custody, 'liability' => $liability];
    }

    private function registered(object $posting, array $leg): string
    {
        $event = DB::table('financial_posting_events as e')
            ->join('financial_posting_operations as o', 'o.operation_uuid', '=', 'e.operation_uuid')
            ->where('e.scope_key', 'water')->where('e.source_type', $leg['source_type'])
            ->where('e.source_id', $leg['source_id'])->where('e.leg', $leg['leg'])
            ->select('e.*', 'o.period_id', 'o.effective_on')->lockForUpdate()->first();
        if (! $event || $event->effective_on !== $posting->effective_on
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
                $target = Cash::positiveStored($session->getRawOriginal('counted_amount'), $driver, DecimalDomain::D14, 0);
                if ($approved < $from && $priorReceived === $target) continue;
                $audits = AuditEvent::query()->where('event', 'cash_session.approved')->where('entity_type', CashSession::class)
                    ->where('entity_id', (string) $id)->lockForUpdate()->get();
                if ($audits->count() !== 1 || (int) $audits[0]->actor_user_id !== (int) $session->approved_by
                    || ($audits[0]->after['counted_amount'] ?? null) !== SignedMinor::decimal($target)
                    || ($audits[0]->after['state'] ?? null) !== CashSession::STATE_APPROVED
                    || $audits[0]->getRawOriginal('occurred_at') >= $until) {
                    throw new DomainException('custody_period_approval_unverified');
                }
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
                $rows[] = ['session_id' => $id, 'collector_id' => (int) $session->user_id,
                    'approval_audit_id' => (int) $audits[0]->id, 'opening_minor' => $opening,
                    'recognized_counted_minor' => $recognized, 'received_minor' => $received,
                    'closing_minor' => $closing, 'handover_hashes' => $hashes,
                    'collection_coverage_verified' => false];
            } catch (DomainException $error) {
                $issues[] = ['code' => $error->getMessage(), 'session_id' => $id];
            }
        }
        return $this->section($rows, $issues);
    }

    private function section(array $rows, array $issues): array
    {
        return ['recorded_rollforward_verified' => $issues === [],
            'rows' => $rows, 'issues' => $issues,
            'recorded_closing_minor' => SignedMinor::sum(array_column($rows, 'closing_minor')),
            'independent_opening_verified' => false, 'carry_evidence_verified' => false];
    }
}
