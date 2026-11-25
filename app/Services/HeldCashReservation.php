<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\SignedMinor;
use App\Support\TreasuryMoney;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Conservative reservation for ordinary outgoing cash while a held surplus
 * exists. Uses the existing issuance lock, never acquires the scope lock
 * after business locks. Discharges release reserved capacity safely.
 */
final class HeldCashReservation
{
    public function assertOrdinaryOutflow(int $accountId, int $minor, string $date): void
    {
        if (DB::transactionLevel() <= 0 || $minor <= 0) {
            throw new DomainException('held_cash_reservation_context');
        }
        if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
            throw new DomainException('held_cash_issuance_lock');
        }
        $account = DB::table('treasury_accounts')->where('id', $accountId)->lockForUpdate()->first();
        if ($account === null) throw new DomainException('held_cash_reservation_account');
        if ($account->channel !== 'cash') return;

        $adjustments = DB::table('treasury_cash_adjustments')->where('treasury_account_id', $accountId)
            ->where('classification', 'unattributed_cash_held')->orderBy('id')->lockForUpdate()->get();
        if ($adjustments->isEmpty()) return;

        // Reserve active undischarged held surplus. Discharged amounts
        // have either been refunded in cash or converted to billing credit.
        $held = 0;
        foreach ($adjustments as $adjustment) {
            $posting = DB::table('treasury_postings')->where('treasury_cash_adjustment_id', $adjustment->id)
                ->lockForUpdate()->first();
            $amount = SignedMinor::parse($adjustment->amount_minor);
            if ($amount <= 0 || $posting === null
                || ! app(TreasuryCashCountService::class)->checkAdjustmentPosting($posting)) {
                throw new DomainException('held_cash_reservation_source');
            }
            $liability = DB::table('treasury_held_cash_liabilities')
                ->where('treasury_cash_adjustment_id', $adjustment->id)->lockForUpdate()->first();
            $discharged = 0;
            if ($liability !== null && Schema::hasTable('treasury_held_cash_discharges')) {
                $discharged = (int) DB::table('treasury_held_cash_discharges')
                    ->where('liability_id', $liability->id)->lockForUpdate()->sum('amount_minor');
            }
            $remainingHeld = max(0, SignedMinor::subtract($amount, $discharged));
            $held = SignedMinor::add($held, $remainingHeld);
        }

        $balance = TreasuryMoney::document($account->opening_minor);
        foreach (DB::table('treasury_postings')->where('treasury_account_id', $accountId)
            ->orderBy('id')->lockForUpdate()->get() as $posting) {
            // No backdating through a balance reconstructed from later cash.
            if ($posting->effective_on > $date) {
                throw new DomainException('held_cash_outflow_backdated');
            }
            $balance = SignedMinor::add($balance, SignedMinor::parse($posting->amount_minor));
        }
        if (SignedMinor::subtract($balance, $held) < $minor) {
            throw new DomainException('held_cash_reserved');
        }
    }
}
