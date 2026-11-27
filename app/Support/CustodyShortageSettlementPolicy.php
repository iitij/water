<?php

declare(strict_types=1);

namespace App\Support;

use DomainException;

/**
 * Exact custody shortage settlement policy. Pure arithmetic only: callers
 * must prove each persisted source, independent authorization and cutoff.
 * This does not post cash, create an employee receivable or authorize close.
 */
final class CustodyShortageSettlementPolicy
{
    public function reconcile(int $shortage, array $events): array
    {
        if ($shortage <= 0 || ! array_is_list($events)) {
            throw new DomainException('shortage_settlement_invalid');
        }
        $remaining = $shortage;
        $recovered = $loss = 0;
        $seen = [];
        foreach ($events as $event) {
            if (! is_array($event) || ! is_string($event['source'] ?? null)
                || ! preg_match('/\A[a-zA-Z0-9:_-]{1,120}\z/', $event['source'])
                || isset($seen[$event['source']])
                || ! is_int($event['minor'] ?? null) || $event['minor'] <= 0
                || $event['minor'] > $remaining
                || ! is_int($event['proposed_by'] ?? null) || $event['proposed_by'] <= 0
                || ! is_int($event['approved_by'] ?? null) || $event['approved_by'] <= 0
                || $event['approved_by'] === $event['proposed_by']
                || ! is_int($event['collector_id'] ?? null) || $event['collector_id'] <= 0
                || $event['approved_by'] === $event['collector_id']
                || ! is_string($event['evidence_hash'] ?? null)
                || ! preg_match('/\A[0-9a-f]{64}\z/', $event['evidence_hash'])) {
                throw new DomainException('shortage_settlement_invalid_source');
            }
            $amount = $event['minor'];
            switch ($event['kind'] ?? null) {
                case 'cash_recovery':
                    // Cash received must have a distinct verified treasury leg.
                    if (($event['treasury_minor'] ?? null) !== $amount) {
                        throw new DomainException('shortage_recovery_cash_mismatch');
                    }
                    $recovered = SignedMinor::add($recovered, $amount);
                    break;
                case 'confirmed_loss':
                    // The physical shortage is already reflected in counted
                    // custody. A classification cannot remove cash a second time.
                    if (($event['treasury_minor'] ?? null) !== 0) {
                        throw new DomainException('shortage_loss_duplicate_cash_effect');
                    }
                    $loss = SignedMinor::add($loss, $amount);
                    break;
                default:
                    throw new DomainException('shortage_settlement_unsupported_kind');
            }
            $seen[$event['source']] = true;
            $remaining = SignedMinor::subtract($remaining, $amount);
        }
        return ['gross_shortage_minor' => $shortage, 'recovered_minor' => $recovered,
            'confirmed_loss_minor' => $loss, 'remaining_minor' => $remaining,
            'amount_fully_classified' => $remaining === 0, 'receivable_created' => false,
            'closure_authorized' => false];
    }
}
