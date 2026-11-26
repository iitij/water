<?php

declare(strict_types=1);

namespace App\Support;

use DomainException;

/**
 * Exact cash-reservation arithmetic. No accounting posting, permission,
 * release record or change to the active conservative reserve is made here.
 */
final class HeldCashReleasePolicy
{
    public function evaluate(int $balance, int $pendingRefunds, int $outstanding,
        array $credits, int $handoverReserve = 0): array
    {
        SignedMinor::requireInt($balance);
        $this->need($pendingRefunds >= 0 && $outstanding >= 0 && $handoverReserve >= 0, 'held_release_amount');
        $total = $applied = $released = 0;
        $seen = [];
        foreach ($credits as $credit) {
            $id = $credit['credit_id'];
            $amount = SignedMinor::requireInt($credit['amount_minor']);
            $allocated = SignedMinor::requireInt($credit['applied_minor']);
            $release = SignedMinor::requireInt($credit['released_minor']);
            $this->need(is_int($id) && $id > 0 && ! isset($seen[$id])
                && $amount > 0 && $allocated >= 0 && $allocated <= $amount
                && $release >= 0 && $release <= $allocated, 'held_release_credit_bound');
            $seen[$id] = true;
            $total = SignedMinor::add($total, $amount);
            $applied = SignedMinor::add($applied, $allocated);
            $released = SignedMinor::add($released, $release);
        }
        $protected = SignedMinor::subtract(SignedMinor::add(SignedMinor::add($outstanding, $total), $handoverReserve), $released);
        $margin = SignedMinor::subtract(SignedMinor::subtract($balance, $pendingRefunds), $protected);
        // A discretionary release must never conceal a pre-existing shortfall.
        $candidate = $margin < 0 ? 0 : SignedMinor::subtract($applied, $released);
        return [
            'recorded_balance_minor' => $balance, 'pending_refunds_minor' => $pendingRefunds,
            'outstanding_minor' => $outstanding, 'handover_reserved_minor' => $handoverReserve, 'credited_minor' => $total,
            'applied_minor' => $applied, 'unapplied_minor' => SignedMinor::subtract($total, $applied),
            'released_minor' => $released, 'protected_minor' => $protected,
            'margin_minor' => $margin, 'shortfall_minor' => max(0, SignedMinor::negate($margin)),
            'candidate_additional_release_minor' => $candidate,
            'candidate_protected_minor' => SignedMinor::subtract($protected, $candidate),
            'candidate_margin_minor' => SignedMinor::add($margin, $candidate),
            'spending_release_authorized' => false,
        ];
    }

    /**
     * Re-reserve per credit, never net a shortfall in one credit against a
     * larger allocation in another. No automatic increase in released cash.
     * An optional change must not create a shortfall; an actual completed
     * event is evidence and requires recording, not suppression.
     */
    public function reinstate(int $balance, int $pendingRefunds, int $outstanding,
        array $before, array $after, int $handoverReserve = 0): array
    {
        $prior = $this->evaluate($balance, $pendingRefunds, $outstanding, $before, $handoverReserve);
        $newById = [];
        foreach ($after as $credit) {
            $id = $credit['credit_id'];
            $this->need(is_int($id) && $id > 0 && ! isset($newById[$id]), 'held_release_credit_identity');
            $newById[$id] = $credit;
        }
        $next = [];
        $restored = 0;
        foreach ($before as $credit) {
            $id = $credit['credit_id'];
            $new = $newById[$id] ?? null;
            $this->need($new !== null && $new['amount_minor'] === $credit['amount_minor'],
                'held_release_credit_identity');
            $allocated = SignedMinor::requireInt($new['applied_minor']);
            $this->need($allocated >= 0 && $allocated <= $credit['amount_minor'], 'held_release_credit_bound');
            $release = min($credit['released_minor'], $allocated);
            $restored = SignedMinor::add($restored, SignedMinor::subtract($credit['released_minor'], $release));
            $next[] = array_replace($credit, ['applied_minor' => $allocated, 'released_minor' => $release]);
            unset($newById[$id]);
        }
        // This transition is for changes to existing allocations only.
        $this->need($newById === [], 'held_release_credit_identity');
        $result = $this->evaluate($balance, $pendingRefunds, $outstanding, $next, $handoverReserve);
        return $result + [
            'restored_reserve_minor' => $restored,
            'before_margin_minor' => $prior['margin_minor'],
            'optional_change_has_liquidity' => $result['margin_minor'] >= 0,
            'actual_event_must_be_recorded' => true,
            'credits' => $next,
        ];
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
