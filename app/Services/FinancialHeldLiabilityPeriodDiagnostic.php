<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\TreasuryHeldCashCredit;
use App\Support\AccountMoney;
use App\Support\SignedMinor;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Roll forward the verified registered held-cash subledger, not historical
 * accounting openings or independently certified period-end cash balances. */
final class FinancialHeldLiabilityPeriodDiagnostic
{
    public function __construct(private FinancialPostingRegistry $registry,
        private TreasuryHeldCashLiabilityService $held) {}

    public function inspect(int $periodId): array
    {
        if (DB::transactionLevel() <= 0 || $periodId <= 0) {
            throw new DomainException('held_period_requires_gate');
        }
        $this->registry->verifyPeriod('water', $periodId);
        DB::table('financial_periods')->where('scope_key', 'water')->orderBy('id')->lockForUpdate()->get();
        $period = DB::table('financial_periods')->where('scope_key', 'water')->where('id', $periodId)->lockForUpdate()->sole();
        $this->held->verifyCoverage();
        $start = $period->start_on;
        $next = $period->next_on;
        $rows = [];
        $totals = array_fill_keys(['recorded_opening_unidentified_minor', 'recorded_opening_identified_minor',
            'recognized_minor', 'assigned_minor', 'paid_minor', 'credited_minor',
            'recorded_opening_pending_payout_minor', 'authorized_minor', 'cancelled_authorization_minor',
            'recorded_closing_pending_payout_minor',
            'recorded_closing_unidentified_minor', 'recorded_closing_identified_minor'], 0);
        foreach (DB::table('treasury_held_cash_liabilities')->orderBy('id')->lockForUpdate()->get() as $lot) {
            $posting = DB::table('treasury_postings')->where('id', $lot->treasury_posting_id)->lockForUpdate()->sole();
            if ($posting->effective_on >= $next) continue;
            $this->registeredBeforeCutoff($lot->operation_uuid, $posting->effective_on);
            $amount = SignedMinor::parseUnsigned($lot->amount_minor);
            $before = ['recognized' => 0, 'assigned' => 0, 'paid' => 0, 'credited' => 0,
                'authorized' => 0, 'cancelled' => 0];
            $during = $before;
            $record = static function (string $kind, string $date, int $minor) use (&$before, &$during, $start, $next): void {
                if ($date < $start) {
                    $before[$kind] = SignedMinor::add($before[$kind], $minor);
                } elseif ($date < $next) {
                    $during[$kind] = SignedMinor::add($during[$kind], $minor);
                }
            };
            $record('recognized', $posting->effective_on, $amount);
            $owners = [];
            foreach (DB::table('treasury_held_cash_assignments')->where('liability_id', $lot->id)
                ->orderBy('operation_uuid')->lockForUpdate()->get() as $assignment) {
                if ($assignment->effective_on >= $next) continue;
                $this->registeredBeforeCutoff($assignment->operation_uuid, $assignment->effective_on);
                $assigned = SignedMinor::parseUnsigned($assignment->amount_minor);
                $record('assigned', $assignment->effective_on, $assigned);
                $opening = $assignment->effective_on < $start ? $assigned : 0;
                $closing = $assigned;
                foreach (DB::table('treasury_held_cash_payouts')->where('assignment_uuid', $assignment->operation_uuid)
                    ->orderBy('operation_uuid')->lockForUpdate()->get() as $payout) {
                    if ($payout->effective_on < $next) $this->registeredBeforeCutoff($payout->operation_uuid, $payout->effective_on);
                    $minor = SignedMinor::parseUnsigned($payout->amount_minor);
                    $record('paid', $payout->effective_on, $minor);
                    if ($payout->effective_on < $start) $opening = SignedMinor::subtract($opening, $minor);
                    if ($payout->effective_on < $next) $closing = SignedMinor::subtract($closing, $minor);
                }
                foreach (TreasuryHeldCashCredit::query()->where('assignment_uuid', $assignment->operation_uuid)
                    ->orderBy('id')->lockForUpdate()->get() as $credit) {
                    if ($credit->effective_on < $next) $this->registeredBeforeCutoff($credit->operation_uuid, $credit->effective_on);
                    $minor = AccountMoney::stored($credit);
                    $record('credited', $credit->effective_on, $minor);
                    if ($credit->effective_on < $start) $opening = SignedMinor::subtract($opening, $minor);
                    if ($credit->effective_on < $next) $closing = SignedMinor::subtract($closing, $minor);
                }
                // Coverage above revalidates approval/cancellation documents and
                // exact payout linkage. Pending authority is a reservation of
                // identified liability, never a new liability or cash movement.
                $pendingOpening = $pendingClosing = 0;
                foreach (DB::table('treasury_held_cash_payout_authorizations')->where('assignment_uuid', $assignment->operation_uuid)
                    ->orderBy('operation_uuid')->lockForUpdate()->get() as $auth) {
                    if ($auth->effective_on >= $next) continue;
                    $this->auditBeforeCutoff('treasury.held_cash_payout_authorized', $auth->operation_uuid, $auth->effective_on);
                    $minor = SignedMinor::parseUnsigned($auth->amount_minor);
                    $record('authorized', $auth->effective_on, $minor);
                    $cancel = DB::table('treasury_held_cash_payout_cancellations')
                        ->where('operation_uuid', $auth->operation_uuid)->lockForUpdate()->first();
                    $paid = DB::table('treasury_held_cash_payouts')
                        ->where('operation_uuid', $auth->operation_uuid)->lockForUpdate()->first();
                    if ($cancel) {
                        if ($cancel->effective_on < $next) $this->auditBeforeCutoff('treasury.held_cash_payout_cancelled', $cancel->operation_uuid, $cancel->effective_on);
                        $record('cancelled', $cancel->effective_on, $minor);
                    }
                    $terminal = $cancel?->effective_on ?? $paid?->effective_on;
                    if ($auth->effective_on < $start && ($terminal === null || $terminal >= $start)) {
                        $pendingOpening = SignedMinor::add($pendingOpening, $minor);
                    }
                    if ($terminal === null || $terminal >= $next) {
                        $pendingClosing = SignedMinor::add($pendingClosing, $minor);
                    }
                }
                if ($opening < 0 || $closing < 0 || $pendingOpening > $opening || $pendingClosing > $closing) {
                    throw new DomainException('held_period_owner_conservation');
                }
                $owners[] = ['assignment_uuid' => $assignment->operation_uuid, 'subscriber_id' => (int) $assignment->subscriber_id,
                    'recorded_opening_minor' => $opening, 'recorded_closing_minor' => $closing,
                    'recorded_opening_pending_payout_minor' => $pendingOpening,
                    'recorded_closing_pending_payout_minor' => $pendingClosing];
            }
            $ou = SignedMinor::subtract($before['recognized'], $before['assigned']);
            $oi = SignedMinor::subtract(SignedMinor::subtract($before['assigned'], $before['paid']), $before['credited']);
            $cu = SignedMinor::subtract(SignedMinor::add($ou, $during['recognized']), $during['assigned']);
            $ci = SignedMinor::subtract(SignedMinor::subtract(SignedMinor::add($oi, $during['assigned']), $during['paid']), $during['credited']);
            $po = SignedMinor::subtract(SignedMinor::subtract($before['authorized'], $before['cancelled']), $before['paid']);
            $pc = SignedMinor::subtract(SignedMinor::subtract(SignedMinor::add($po, $during['authorized']), $during['cancelled']), $during['paid']);
            if (min($po, $pc) < 0 || $po > $oi || $pc > $ci
                || SignedMinor::sum(array_column($owners, 'recorded_opening_pending_payout_minor')) !== $po
                || SignedMinor::sum(array_column($owners, 'recorded_closing_pending_payout_minor')) !== $pc) {
                throw new DomainException('held_period_pending_payout_conservation');
            }
            if (min($ou, $oi, $cu, $ci) < 0
                || SignedMinor::sum(array_column($owners, 'recorded_opening_minor')) !== $oi
                || SignedMinor::sum(array_column($owners, 'recorded_closing_minor')) !== $ci
                || SignedMinor::add($cu, $ci) !== SignedMinor::subtract(SignedMinor::subtract(
                    SignedMinor::add(SignedMinor::add($ou, $oi), $during['recognized']), $during['paid']), $during['credited'])) {
                throw new DomainException('held_period_conservation');
            }
            $row = ['liability_id' => (int) $lot->id, 'account_id' => (int) $posting->treasury_account_id,
                'recorded_opening_unidentified_minor' => $ou, 'recorded_opening_identified_minor' => $oi,
                'recognized_minor' => $during['recognized'], 'assigned_minor' => $during['assigned'],
                'paid_minor' => $during['paid'], 'credited_minor' => $during['credited'],
                'recorded_opening_pending_payout_minor' => $po, 'authorized_minor' => $during['authorized'],
                'cancelled_authorization_minor' => $during['cancelled'], 'recorded_closing_pending_payout_minor' => $pc,
                'recorded_closing_unidentified_minor' => $cu, 'recorded_closing_identified_minor' => $ci,
                'owners' => $owners];
            foreach (array_keys($totals) as $field) $totals[$field] = SignedMinor::add($totals[$field], $row[$field]);
            $rows[] = $row;
        }
        return ['period_id' => $periodId, 'start_on' => $start, 'next_on' => $next, 'rows' => $rows, 'totals' => $totals,
            'registered_held_subledger_rollforward_verified' => true, 'independent_opening_verified' => false,
            'all_liabilities_verified' => false, 'closure_authorized' => false];
    }

    /** verifyCoverage() already reconstructs the operation payload and all
     * source legs. A valid payload alone does not establish the recording time.
     * Evaluate each relevant source against its OWN period, not the report month. */
    private function registeredBeforeCutoff(string $uuid, string $date): void
    {
        $op = DB::table('financial_posting_operations')->where('operation_uuid', $uuid)
            ->where('scope_key', 'water')->lockForUpdate()->first();
        if (! $op || $op->effective_on !== $date) {
            throw new DomainException('held_period_registry_source_missing');
        }
        $this->beforeCutoff($date, $op->recorded_at_utc, (int) $op->period_id);
    }

    private function auditBeforeCutoff(string $event, string $uuid, string $date): void
    {
        $audit = AuditEvent::query()->where('event', $event)
            ->where('entity_type', 'held_cash_payout_authorization')->where('entity_id', $uuid)
            ->lockForUpdate()->sole();
        $recorded = $audit->getRawOriginal('occurred_at');
        if (! is_string($recorded) || $recorded === '') {
            throw new DomainException('held_period_recording_time_invalid');
        }
        $this->beforeCutoff($date, $recorded);
    }

    private function beforeCutoff(string $date, string $recorded, ?int $periodId = null): void
    {
        $periods = DB::table('financial_periods')->where('scope_key', 'water')
            ->where('start_on', '<=', $date)->where('next_on', '>', $date)->lockForUpdate()->get();
        if ($periods->count() !== 1 || ($periodId !== null && (int) $periods[0]->id !== $periodId)) {
            throw new DomainException('held_period_recording_period_invalid');
        }
        $zone = new DateTimeZone('Asia/Aden');
        $utc = new DateTimeZone('UTC');
        $at = new DateTimeImmutable($recorded, $utc);
        $end = new DateTimeImmutable($periods[0]->next_on, $zone);
        if ($at >= $end) throw new DomainException('held_period_source_recorded_after_cutoff');
        if ($at->setTimezone($zone)->format('Y-m-d') < $date) {
            throw new DomainException('held_period_recording_time_invalid');
        }
    }
}
