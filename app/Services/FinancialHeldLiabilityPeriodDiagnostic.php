<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TreasuryHeldCashCredit;
use App\Support\AccountMoney;
use App\Support\SignedMinor;
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
            'recorded_closing_unidentified_minor', 'recorded_closing_identified_minor'], 0);
        foreach (DB::table('treasury_held_cash_liabilities')->orderBy('id')->lockForUpdate()->get() as $lot) {
            $posting = DB::table('treasury_postings')->where('id', $lot->treasury_posting_id)->lockForUpdate()->sole();
            if ($posting->effective_on >= $next) continue;
            $amount = SignedMinor::parseUnsigned($lot->amount_minor);
            $before = ['recognized' => 0, 'assigned' => 0, 'paid' => 0, 'credited' => 0];
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
                $assigned = SignedMinor::parseUnsigned($assignment->amount_minor);
                $record('assigned', $assignment->effective_on, $assigned);
                $opening = $assignment->effective_on < $start ? $assigned : 0;
                $closing = $assigned;
                foreach (DB::table('treasury_held_cash_payouts')->where('assignment_uuid', $assignment->operation_uuid)
                    ->orderBy('operation_uuid')->lockForUpdate()->get() as $payout) {
                    $minor = SignedMinor::parseUnsigned($payout->amount_minor);
                    $record('paid', $payout->effective_on, $minor);
                    if ($payout->effective_on < $start) $opening = SignedMinor::subtract($opening, $minor);
                    if ($payout->effective_on < $next) $closing = SignedMinor::subtract($closing, $minor);
                }
                foreach (TreasuryHeldCashCredit::query()->where('assignment_uuid', $assignment->operation_uuid)
                    ->orderBy('id')->lockForUpdate()->get() as $credit) {
                    $minor = AccountMoney::stored($credit);
                    $record('credited', $credit->effective_on, $minor);
                    if ($credit->effective_on < $start) $opening = SignedMinor::subtract($opening, $minor);
                    if ($credit->effective_on < $next) $closing = SignedMinor::subtract($closing, $minor);
                }
                if ($opening < 0 || $closing < 0) throw new DomainException('held_period_owner_conservation');
                $owners[] = ['assignment_uuid' => $assignment->operation_uuid, 'subscriber_id' => (int) $assignment->subscriber_id,
                    'recorded_opening_minor' => $opening, 'recorded_closing_minor' => $closing];
            }
            $ou = SignedMinor::subtract($before['recognized'], $before['assigned']);
            $oi = SignedMinor::subtract(SignedMinor::subtract($before['assigned'], $before['paid']), $before['credited']);
            $cu = SignedMinor::subtract(SignedMinor::add($ou, $during['recognized']), $during['assigned']);
            $ci = SignedMinor::subtract(SignedMinor::subtract(SignedMinor::add($oi, $during['assigned']), $during['paid']), $during['credited']);
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
                'recorded_closing_unidentified_minor' => $cu, 'recorded_closing_identified_minor' => $ci,
                'owners' => $owners];
            foreach (array_keys($totals) as $field) $totals[$field] = SignedMinor::add($totals[$field], $row[$field]);
            $rows[] = $row;
        }
        return ['period_id' => $periodId, 'start_on' => $start, 'next_on' => $next, 'rows' => $rows, 'totals' => $totals,
            'registered_held_subledger_rollforward_verified' => true, 'independent_opening_verified' => false,
            'all_liabilities_verified' => false, 'closure_authorized' => false];
    }
}
