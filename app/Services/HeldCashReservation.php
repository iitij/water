<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\SignedMinor;
use App\Support\TreasuryMoney;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Conservative reservation for ordinary outgoing cash while a held surplus
 * exists. Uses the existing issuance lock, never acquires the scope lock
 * after business locks. No release, settlement or certified balance.
 */
final class HeldCashReservation
{
    public function assertOrdinaryOutflow(int $accountId, int $minor, string $date, ?int $postingRefundId = null): void
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

        $held = $this->protectedForAccount($accountId);
        if ($held === 0) return;

        $pending = $this->unpostedCashRefundMinor($postingRefundId, $minor);
        $balance = app(HeldCashRecordedLiquidityEvidence::class)->balance($accountId, $date);
        if (SignedMinor::subtract(SignedMinor::subtract($balance, $held), $pending) < $minor) {
            throw new DomainException('held_cash_reserved');
        }
    }
    /**
     * Evidence of an already executed cash refund may not yet identify its
     * treasury account. Conservatively count the full channel-wide amount
     * against each cash account with held cash, never as an extra liability.
     * The refund being posted is excluded exactly once after identity checks.
     */
    public function unpostedCashRefundMinor(?int $postingRefundId = null, ?int $postingMinor = null): int
    {
        if (DB::transactionLevel() <= 0) throw new DomainException('held_cash_reservation_context');
        $total = 0;
        $matched = $postingRefundId === null;
        $rows = \App\Models\PaymentRefund::query()->where('method', 'treasury_cash')
            ->orderBy('id')->lockForUpdate()->get();
        foreach ($rows as $row) {
            $minor = \App\Support\AccountMoney::stored($row);
            if ($minor <= 0) throw new DomainException('held_cash_unposted_refund_amount');
            $postings = DB::table('treasury_postings')->where('payment_refund_id', $row->id)->lockForUpdate()->get();
            if ($postings->isNotEmpty()) {
                if ($postings->count() !== 1 || (int) $row->id === $postingRefundId) {
                    throw new DomainException('held_cash_refund_posting_identity');
                }
                app(HeldCashRecordedLiquidityEvidence::class)->assertRefund($postings->first(), $minor);
                continue;
            }
            if ((int) $row->id === $postingRefundId) {
                if ($matched || $minor !== $postingMinor) throw new DomainException('held_cash_unposted_refund_identity');
                $matched = true;
                continue;
            }
            $total = SignedMinor::add($total, $minor);
        }
        if (! $matched) throw new DomainException('held_cash_unposted_refund_identity');
        return $total;
    }

    /**
     * Until all invoice/refund writers share a proven fence, do not release
     * cash on the strength of a mutable allocation snapshot. Reserve the full
     * amount reclassified as subscriber credit, including applied credit.
     * This is a conservative liquidity ceiling, not a new liability balance.
     */
    public function subscriberCreditReserveForAccount(int $accountId): int
    {
        if (DB::transactionLevel() <= 0) throw new DomainException('held_cash_reservation_context');
        $total = 0;
        foreach (DB::table('treasury_cash_adjustments')->where('treasury_account_id', $accountId)
            ->where('classification', 'unattributed_cash_held')->orderBy('id')->lockForUpdate()->get() as $adjustment) {
            // Source-linked release validation must run even if the outstanding
            // held liability itself has reached zero.
            app(TreasuryHeldCashLiabilityService::class)->releasedForAdjustment((int) $adjustment->id);
            $credits = DB::table('treasury_held_cash_credits as c')
                ->join('treasury_held_cash_assignments as a', 'a.operation_uuid', '=', 'c.assignment_uuid')
                ->join('treasury_held_cash_liabilities as l', 'l.id', '=', 'a.liability_id')
                ->where('l.treasury_cash_adjustment_id', $adjustment->id)->orderBy('c.id')
                ->select('c.amount')->lockForUpdate()->get();
            foreach ($credits as $credit) {
                $minor = \App\Support\MoneyBoundary::readDecimal($credit->amount, \App\Support\DecimalDomain::D12,
                    DB::getDriverName(), signed: true);
                if ($minor <= 0) throw new DomainException('held_cash_credit_source');
                $total = SignedMinor::add($total, $minor);
            }
        }
        return $total;
    }

    public function protectedForAccount(int $accountId): int
    {
        return SignedMinor::add($this->outstandingForAccount($accountId), $this->subscriberCreditReserveForAccount($accountId));
    }

    /** Caller owns the issuance lock and account lock. No scope acquisition. */
    public function outstandingForAccount(int $accountId): int
    {
        if (DB::transactionLevel() <= 0) throw new DomainException('held_cash_reservation_context');
        $adjustments = DB::table('treasury_cash_adjustments')->where('treasury_account_id', $accountId)
            ->where('classification', 'unattributed_cash_held')->orderBy('id')->lockForUpdate()->get();


        // Reserve even historical, not-yet-adopted held surplus. Never infer
        // release from an owner assignment or missing liability record.
        $held = 0;
        foreach ($adjustments as $adjustment) {
            $posting = DB::table('treasury_postings')->where('treasury_cash_adjustment_id', $adjustment->id)
                ->lockForUpdate()->first();
            $amount = SignedMinor::parse($adjustment->amount_minor);
            if ($amount <= 0 || $posting === null
                || ! app(TreasuryCashCountService::class)->checkAdjustmentPosting($posting)) {
                throw new DomainException('held_cash_reservation_source');
            }
            $released = app(TreasuryHeldCashLiabilityService::class)->releasedForAdjustment((int) $adjustment->id);
            if ($released < 0 || $released > $amount) throw new DomainException('held_cash_reservation_source');
            $held = SignedMinor::add($held, SignedMinor::subtract($amount, $released));
        }

        return $held;
    }
}
