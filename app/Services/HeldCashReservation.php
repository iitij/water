<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\SignedMinor;
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
        if ($held === 0 && ! app(ActiveHeldCashRelease::class)->hasAccount($accountId)) return;

        $pending = $this->unpostedCashRefundMinor($postingRefundId, $minor);
        $balance = app(HeldCashRecordedLiquidityEvidence::class)->balance($accountId, $date);
        if (SignedMinor::subtract(SignedMinor::subtract($balance, $held), $pending) < $minor) {
            throw new DomainException('held_cash_reserved');
        }
    }
    /** No ordinary spending bypass: callable only inside the independent
     * completed-refund writer's exact mutation identity. In this narrow slice
     * there must be no other unposted cash refund or negative physical cash.
     */
    public function assertCompletedCashRefund(int $accountId, int $minor, string $date,
        \App\Models\PaymentRefund $refund, \App\Models\User $actor, GatedRefundWriter $writer): void
    {
        FinancialWriteGate::currentContext();
        if ($minor <= 0 || ! $writer->allowsCompletedCashPosting($refund, $accountId, (int) $actor->id)
            || $minor !== \App\Support\AccountMoney::stored($refund)
            || $refund->disbursed_on->toDateString() !== $date
            || ! app(ActiveHeldCashRelease::class)->hasAccount($accountId)) {
            throw new DomainException('completed_cash_refund_identity');
        }
        $this->protectedForAccount($accountId); // verify active reserve evidence
        $pending = $this->unpostedCashRefundMinor((int) $refund->id, $minor);
        $balance = app(HeldCashRecordedLiquidityEvidence::class)->balance($accountId, $date);
        if ($pending !== 0 || $balance < $minor) {
            throw new DomainException('completed_cash_refund_unresolved_custody');
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
        foreach ($this->heldAdjustmentsForAccount($accountId) as $adjustment) {
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
        $full = $this->conservativeProtectedForAccount($accountId);
        $released = app(ActiveHeldCashRelease::class)->amount($accountId);
        if ($released < 0 || $released > $full) throw new DomainException('active_held_release_evidence');
        return SignedMinor::subtract($full, $released);
    }

    public function conservativeProtectedForAccount(int $accountId): int
    {
        return SignedMinor::add(SignedMinor::add($this->outstandingForAccount($accountId), $this->subscriberCreditReserveForAccount($accountId)),
            app(HandoverSurplusReservation::class)->forAccount($accountId));
    }

    /** Caller owns the issuance lock and account lock. No scope acquisition. */
    public function outstandingForAccount(int $accountId): int
    {
        if (DB::transactionLevel() <= 0) throw new DomainException('held_cash_reservation_context');
        $adjustments = $this->heldAdjustmentsForAccount($accountId);


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
    /** Reconstruct candidates in both directions before a zero reserve can bypass checks. */
    private function heldAdjustmentsForAccount(int $accountId): array
    {
        $adjustments = DB::table('treasury_cash_adjustments')
            ->where(function ($query) use ($accountId): void {
                $query->where('treasury_account_id', $accountId)
                    ->orWhereIn('treasury_cash_count_id', DB::table('treasury_cash_counts')
                        ->where('treasury_account_id', $accountId)->select('id'))
                    ->orWhereIn('id', DB::table('treasury_postings')->where('treasury_account_id', $accountId)
                        ->whereNotNull('treasury_cash_adjustment_id')->select('treasury_cash_adjustment_id'))
                    ->orWhereIn('id', DB::table('treasury_held_cash_liabilities as l')
                        ->join('treasury_postings as p', 'p.id', '=', 'l.treasury_posting_id')
                        ->where('p.treasury_account_id', $accountId)->select('l.treasury_cash_adjustment_id'));
            })->orderBy('id')->lockForUpdate()->get();
        $held = [];
        foreach ($adjustments as $adjustment) {
            $count = DB::table('treasury_cash_counts')->where('id', $adjustment->treasury_cash_count_id)->lockForUpdate()->first();
            $postings = DB::table('treasury_postings')->where('treasury_cash_adjustment_id', $adjustment->id)->lockForUpdate()->get();
            $lot = DB::table('treasury_held_cash_liabilities')->where('treasury_cash_adjustment_id', $adjustment->id)->lockForUpdate()->first();
            $amount = SignedMinor::parse($adjustment->amount_minor);
            $positive = $adjustment->classification === 'unattributed_cash_held'
                || $lot !== null || $amount > 0
                || ($count !== null && SignedMinor::parse($count->variance_minor) > 0)
                || $postings->contains(fn ($row) => SignedMinor::parse($row->amount_minor) > 0);
            if (! $positive) continue;
            if ($adjustment->classification !== 'unattributed_cash_held' || $amount <= 0
                || (int) $adjustment->treasury_account_id !== $accountId
                || $count === null || (int) $count->treasury_account_id !== $accountId
                || $postings->count() !== 1 || (int) $postings->first()->treasury_account_id !== $accountId
                || ($lot !== null && (int) $lot->treasury_posting_id !== (int) $postings->first()->id)
                || ! app(TreasuryCashCountService::class)->checkAdjustmentPosting($postings->first())) {
                throw new DomainException('held_cash_reservation_source');
            }
            $held[] = $adjustment;
        }
        return $held;
    }
}
