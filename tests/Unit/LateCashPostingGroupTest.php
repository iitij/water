<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\LateCashPostingGroup;
use DomainException;
use PHPUnit\Framework\TestCase;

final class LateCashPostingGroupTest extends TestCase
{
    private const UUID = '11111111-1111-4111-8111-111111111111';

    private function inputs(): array
    {
        return [
            self::UUID, 1, 2, 3,
            ['period_id' => 4, 'scope' => 'water', 'effective_on' => '2026-10-31', 'epoch' => 5, 'version' => 7],
            ['period_id' => 6, 'scope' => 'water', 'effective_on' => '2026-11-25', 'epoch' => 5, 'version' => 2],
            ['source_type' => 'ledger_transaction', 'source_id' => '8', 'leg' => 'subscriber',
                'amount_minor' => 1025, 'currency' => 'YER', 'source_hash' => str_repeat('a', 64)],
            ['source_type' => 'treasury_posting', 'source_id' => '9', 'leg' => 'treasury',
                'amount_minor' => 1025, 'currency' => 'YER', 'source_hash' => str_repeat('b', 64)],
        ];
    }

    private function build(?array $inputs = null): array
    {
        return (new LateCashPostingGroup())->build(...($inputs ?? $this->inputs()));
    }

    private function rejects(string $reason, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected rejection.');
        } catch (DomainException $error) {
            $this->assertSame($reason, $error->getMessage());
        }
    }

    public function test_canonical_group_keeps_one_business_identity_and_two_exact_dates(): void
    {
        $result = $this->build();
        $payload = $result['payload'];
        $this->assertSame(2, $payload['format']);
        $this->assertSame(self::UUID, $payload['operation_uuid']);
        $this->assertSame('1', $payload['review_id']);
        $this->assertSame('2', $payload['payment_id']);
        $this->assertSame('3', $payload['receipt_id']);
        $this->assertSame(['subscriber', 'treasury'], array_column($payload['parts'], 'role'));
        $this->assertSame('2026-10-31', $payload['parts'][0]['context']['effective_on']);
        $this->assertSame('2026-11-25', $payload['parts'][1]['context']['effective_on']);
        $this->assertSame('1025', $payload['parts'][0]['leg']['amount_minor']);
        $this->assertSame('1025', $payload['parts'][1]['leg']['amount_minor']);
        $this->assertSame(hash('sha256', $result['canonical_payload']), $result['payload_hash']);
        $input = $this->inputs();
        foreach ([4, 5, 6, 7] as $i) $input[$i] = array_reverse($input[$i], true);
        $this->assertSame($result, $this->build($input));
    }

    public function test_each_identity_and_source_hash_is_bound_to_payload(): void
    {
        $original = $this->build();
        foreach ([1, 2, 3] as $index) {
            $input = $this->inputs();
            $input[$index]++;
            $this->assertNotSame($original['payload_hash'], $this->build($input)['payload_hash']);
        }
        foreach ([6, 7] as $index) {
            $input = $this->inputs();
            $input[$index]['source_hash'] = str_repeat('c', 64);
            $this->assertNotSame($original['payload_hash'], $this->build($input)['payload_hash']);
        }
    }

    public function test_distinct_days_in_same_month_use_same_period_and_version(): void
    {
        $input = $this->inputs();
        $input[4] = $input[5];
        $input[4]['effective_on'] = '2026-11-24';
        $this->assertSame(6, $this->build($input)['payload']['parts'][0]['context']['period_id']);
        $input[4]['version']++;
        $this->rejects('late_cash_group_period_identity', fn () => $this->build($input));
        $input = $this->inputs();
        $input[4]['period_id'] = $input[5]['period_id'];
        $this->rejects('late_cash_group_period_identity', fn () => $this->build($input));
    }

    public function test_same_day_reversed_date_invalid_calendar_and_epoch_mismatch_rejected(): void
    {
        $input = $this->inputs();
        $input[4] = $input[5];
        $this->rejects('late_cash_group_distinct_ordered_dates', fn () => $this->build($input));
        $input = $this->inputs();
        [$input[4], $input[5]] = [$input[5], $input[4]];
        $this->rejects('late_cash_group_distinct_ordered_dates', fn () => $this->build($input));
        $input = $this->inputs();
        $input[4]['effective_on'] = '2026-02-30';
        $this->rejects('late_cash_group_date', fn () => $this->build($input));
        $input = $this->inputs();
        $input[5]['epoch']++;
        $this->rejects('late_cash_group_epoch', fn () => $this->build($input));
    }

    public function test_amount_mismatch_nonpositive_and_out_of_range_rejected(): void
    {
        $input = $this->inputs();
        $input[7]['amount_minor']++;
        $this->rejects('late_cash_group_amount_mismatch', fn () => $this->build($input));
        foreach ([0, -1, 1000000000000, '1025', 10.25] as $amount) {
            $input = $this->inputs();
            $input[6]['amount_minor'] = $amount;
            $this->rejects('late_cash_group_leg', fn () => $this->build($input));
        }
        $input = $this->inputs();
        $input[6]['amount_minor'] = $input[7]['amount_minor'] = 999999999999;
        $this->assertSame('999999999999', $this->build($input)['payload']['parts'][0]['leg']['amount_minor']);
    }

    public function test_invalid_uuid_scope_source_role_and_extra_fields_rejected(): void
    {
        $input = $this->inputs();
        $input[0] = 'not-a-uuid';
        $this->rejects('late_cash_group_identity', fn () => $this->build($input));
        $input = $this->inputs();
        $input[4]['scope'] = 'other';
        $this->rejects('late_cash_group_context', fn () => $this->build($input));
        foreach (['source_id' => '08', 'leg' => 'treasury', 'currency' => 'USD',
            'source_hash' => 'invalid', 'extra' => true] as $key => $value) {
            $input = $this->inputs();
            $input[6][$key] = $value;
            $this->rejects('late_cash_group_leg', fn () => $this->build($input));
        }
    }

    public function test_changed_payload_rejected_even_when_hash_is_recomputed(): void
    {
        $group = new LateCashPostingGroup();
        $actual = $this->build();
        $group->assertMatches($actual, $actual['canonical_payload'], $actual['payload_hash']);
        $input = $this->inputs();
        $input[5]['effective_on'] = '2026-11-24';
        $changed = $this->build($input);
        $this->rejects('late_cash_group_proof_mismatch', fn () =>
            $group->assertMatches($actual, $changed['canonical_payload'], $changed['payload_hash']));
        $this->rejects('late_cash_group_proof_mismatch', fn () =>
            $group->assertMatches($actual, $actual['canonical_payload'], str_repeat('0', 64)));
        $this->addToAssertionCount(1);
    }
}
