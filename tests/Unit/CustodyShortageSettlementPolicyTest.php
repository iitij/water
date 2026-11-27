<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CustodyShortageSettlementPolicy;
use DomainException;
use PHPUnit\Framework\TestCase;

final class CustodyShortageSettlementPolicyTest extends TestCase
{
    private function event(string $source, int $amount, string $kind, int $cash): array
    {
        return ['source' => $source, 'minor' => $amount, 'kind' => $kind,
            'treasury_minor' => $cash, 'proposed_by' => 1, 'approved_by' => 2,
            'collector_id' => 3, 'evidence_hash' => str_repeat('a', 64)];
    }

    public function test_exact_partial_and_full_disposition_without_inventing_debt(): void
    {
        $policy = new CustodyShortageSettlementPolicy();
        $recovery = $this->event('receipt:1', 120, 'cash_recovery', 120);
        $loss = $this->event('loss:1', 80, 'confirmed_loss', 0);
        $this->assertSame(200, $policy->reconcile(200, [])['remaining_minor']);
        $partial = $policy->reconcile(200, [$recovery]);
        $this->assertSame(80, $partial['remaining_minor']);
        $this->assertFalse($partial['amount_fully_classified']);
        $full = $policy->reconcile(200, [$recovery, $loss]);
        $this->assertSame(120, $full['recovered_minor']);
        $this->assertSame(80, $full['confirmed_loss_minor']);
        $this->assertSame(0, $full['remaining_minor']);
        $this->assertTrue($full['amount_fully_classified']);
        $this->assertFalse($full['receivable_created']);
        $this->assertFalse($full['closure_authorized']);
    }

    public function test_rejects_duplicate_source_over_settlement_unverified_actor_and_duplicate_cash(): void
    {
        $policy = new CustodyShortageSettlementPolicy();
        $receipt = $this->event('receipt:1', 100, 'cash_recovery', 100);
        $loss = $this->event('loss:1', 100, 'confirmed_loss', 0);
        foreach ([
            [[$receipt, $receipt], 'shortage_settlement_invalid_source'],
            [[$receipt, array_replace($loss, ['minor' => 101])], 'shortage_settlement_invalid_source'],
            [[array_replace($loss, ['approved_by' => 1])], 'shortage_settlement_invalid_source'],
            [[array_replace($loss, ['approved_by' => 3])], 'shortage_settlement_invalid_source'],
            [[array_replace($loss, ['evidence_hash' => 'invalid'])], 'shortage_settlement_invalid_source'],
            [[array_replace($loss, ['treasury_minor' => -100])], 'shortage_loss_duplicate_cash_effect'],
            [[array_replace($receipt, ['treasury_minor' => 99])], 'shortage_recovery_cash_mismatch'],
            [[array_replace($loss, ['kind' => 'employee_debt'])], 'shortage_settlement_unsupported_kind'],
        ] as [$events, $reason]) {
            try {
                $policy->reconcile(200, $events);
                $this->fail('Invalid shortage disposition accepted.');
            } catch (DomainException $error) {
                $this->assertSame($reason, $error->getMessage());
            }
        }
    }
}
