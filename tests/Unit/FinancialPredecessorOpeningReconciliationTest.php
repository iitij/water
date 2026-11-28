<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\FinancialPredecessorOpeningReconciliation;
use PHPUnit\Framework\TestCase;

final class FinancialPredecessorOpeningReconciliationTest extends TestCase
{
    private function fixture(): array
    {
        $predecessor = ['predecessor_hash' => str_repeat('a', 64), 'predecessor_journal_verified' => true,
            'rows' => ['cash:1' => ['closing' => 100], 'bank:2' => ['closing' => -20],
                'subscriber:3' => ['closing' => -5], 'transit:4' => ['closing' => 30],
                'custody:5' => ['closing' => 10], 'liability:lot-6' => ['closing' => 2],
                'liability:assignment-abc' => ['closing' => 8], 'allocation:3' => ['closing' => 4]]];
        $accounts = ['matched' => true, 'rows' => [
            ['account' => 'cash:1', 'opening_minor' => 100],
            ['account' => 'bank:2', 'opening_minor' => -20],
            ['account' => 'subscriber:3', 'opening_minor' => -5],
            ['account' => 'subscriber:7', 'opening_minor' => 0]]];
        $outstanding = [
            'transit' => ['rows' => [['transfer_id' => 4, 'opening_minor' => 30]]],
            'custody' => ['rows' => [['session_id' => 5, 'opening_minor' => 10]]],
            'liability' => ['recorded_rollforward_verified' => true, 'rows' => [
                ['liability_id' => 6, 'recorded_opening_unidentified_minor' => 2,
                    'owners' => [['assignment_uuid' => 'abc', 'recorded_opening_minor' => 8,
                        'recorded_opening_pending_payout_minor' => 3]]]]]];
        return [$predecessor, $accounts, $outstanding, ['matched' => true], ['matched' => true]];
    }

    public function test_six_sectors_match_without_counting_pending_authority_twice(): void
    {
        $result = (new FinancialPredecessorOpeningReconciliation())->inspect(...$this->fixture());
        self::assertTrue($result['matched']);
        self::assertCount(7, $result['matched_rows']);
        self::assertSame(['subscriber:7'], $result['new_zero_opening_rows']);
        self::assertSame(['allocation', 'recoverable'], $result['uncovered_sectors']);
        self::assertFalse($result['independent_opening_verified']);
        self::assertFalse($result['closure_authorized']);
        self::assertSame([], $result['issues']);
    }

    public function test_offsetting_errors_missing_zero_and_new_nonzero_are_individually_rejected(): void
    {
        $f = $this->fixture();
        $f[0]['rows']['subscriber:9'] = ['closing' => 0];
        $f[1]['rows'][0]['opening_minor'] = 101;
        $f[1]['rows'][1]['opening_minor'] = -21;
        $f[1]['rows'][3]['opening_minor'] = 2;
        $result = (new FinancialPredecessorOpeningReconciliation())->inspect(...$f);
        self::assertFalse($result['matched']);
        self::assertSame([
            ['code' => 'opening_predecessor_balance_mismatch', 'row' => 'bank:2', 'expected_minor' => -20, 'actual_minor' => -21],
            ['code' => 'opening_predecessor_balance_mismatch', 'row' => 'cash:1', 'expected_minor' => 100, 'actual_minor' => 101],
            ['code' => 'opening_predecessor_row_missing', 'row' => 'subscriber:9'],
            ['code' => 'opening_new_row_nonzero', 'row' => 'subscriber:7', 'actual_minor' => 2],
        ], $result['issues']);
        self::assertFalse($result['closure_authorized']);
    }

    public function test_missing_journal_unverified_source_and_duplicate_identity_fail_closed(): void
    {
        $service = new FinancialPredecessorOpeningReconciliation();
        $f = $this->fixture();
        $f[0]['predecessor_journal_verified'] = false;
        self::assertSame([['code' => 'opening_predecessor_journal_unverified']], $service->inspect(...$f)['issues']);
        $f = $this->fixture();
        $f[4]['matched'] = false;
        self::assertSame([['code' => 'opening_current_source_unverified']], $service->inspect(...$f)['issues']);
        $f = $this->fixture();
        $f[1]['rows'][] = $f[1]['rows'][0];
        $result = $service->inspect(...$f);
        self::assertFalse($result['matched']);
        self::assertSame([['code' => 'opening_duplicate_source', 'row' => 'cash:1']], $result['issues']);
    }
}
