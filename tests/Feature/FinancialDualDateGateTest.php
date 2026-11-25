<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\FinancialPostingRegistry;
use App\Services\FinancialWriteGate;
use DomainException;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class FinancialDualDateGateTest extends TestCase
{
    private const UUID = '11111111-1111-4111-8111-111111111111';
    private const OTHER = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing') || ! (
            (DB::getDriverName() === 'sqlite' && DB::getDatabaseName() === ':memory:')
            || (DB::getDriverName() === 'mysql' && DB::getDatabaseName() === 'water_test')
        )) {
            $this->markTestSkipped('Requires disposable isolated ledger evidence database.');
        }
        RefreshDatabaseState::$migrated = false;
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        DB::table('financial_scopes')->insert([
            'scope_key' => 'water', 'currency' => 'YER', 'timezone' => 'Asia/Aden',
            'mode' => 'ACTIVE', 'calendar_version' => 1, 'fence_epoch' => 5,
        ]);
        DB::table('financial_periods')->insert([
            'scope_key' => 'water', 'month' => '2026-11', 'start_on' => '2026-11-01',
            'next_on' => '2026-12-01', 'state' => 'OPEN', 'version' => 2,
        ]);
    }

    private function nowAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-25T08:30:00.123456Z');
    }

    private function gate(callable $writer): mixed
    {
        return app(FinancialWriteGate::class)->withinOpenPeriod('water', '2026-11-25', $this->nowAt(), $writer);
    }

    private function october(string $state = 'OPEN'): int
    {
        return DB::table('financial_periods')->insertGetId([
            'scope_key' => 'water', 'month' => '2026-10', 'start_on' => '2026-10-01',
            'next_on' => '2026-11-01', 'state' => $state, 'version' => 7,
        ]);
    }

    private function both(array $dates, callable $callback): mixed
    {
        return app(FinancialWriteGate::class)->withinOpenPeriods('water', $dates, $this->nowAt(), $callback);
    }

    private function rejects(string $message, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected rejection.');
        } catch (DomainException $error) {
            $this->assertSame($message, $error->getMessage());
        }
    }

    public function test_two_open_months_get_distinct_ordered_contexts_in_one_transaction(): void
    {
        $october = $this->october();
        $november = DB::table('financial_periods')->where('month', '2026-11')->value('id');
        $contexts = $this->both(['2026-11-25', '2026-10-31'], function (array $contexts): array {
            $this->assertSame(1, DB::transactionLevel());
            return $contexts;
        });
        $this->assertSame(['2026-10-31', '2026-11-25'], array_keys($contexts));
        $this->assertSame(['period_id' => $october, 'scope' => 'water', 'effective_on' => '2026-10-31',
            'epoch' => 5, 'version' => 7], $contexts['2026-10-31']);
        $this->assertSame(['period_id' => (int) $november, 'scope' => 'water', 'effective_on' => '2026-11-25',
            'epoch' => 5, 'version' => 2], $contexts['2026-11-25']);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_same_month_distinct_dates_and_same_day_deduplication(): void
    {
        $contexts = $this->both(['2026-11-25', '2026-11-24'], fn ($c) => $c);
        $this->assertSame(['2026-11-24', '2026-11-25'], array_keys($contexts));
        $this->assertSame($contexts['2026-11-24']['period_id'], $contexts['2026-11-25']['period_id']);
        $single = $this->both(['2026-11-25', '2026-11-25'], fn ($c) => $c);
        $this->assertSame(['2026-11-25'], array_keys($single));
        $this->assertSame($this->gate(fn ($c) => $c), $single['2026-11-25']);
    }

    public function test_closed_frozen_or_missing_period_never_runs_callback(): void
    {
        $called = false;
        $callback = function () use (&$called): void { $called = true; };
        $dates = ['2026-11-25', '2026-10-31'];
        $this->rejects('financial_period_not_open', fn () => $this->both($dates, $callback));
        $id = $this->october('CLOSED');
        $this->rejects('financial_period_not_open', fn () => $this->both($dates, $callback));
        DB::table('financial_periods')->where('id', $id)->update(['state' => 'FROZEN']);
        $this->rejects('financial_period_not_open', fn () => $this->both($dates, $callback));
        DB::table('financial_periods')->where('id', $id)->update(['state' => 'OPEN']);
        DB::table('financial_periods')->where('month', '2026-11')->update(['state' => 'CLOSED']);
        $this->rejects('financial_period_not_open', fn () => $this->both($dates, $callback));
        $this->assertFalse($called);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_invalid_future_and_malformed_destinations_fail_before_business_callback(): void
    {
        foreach ([[], ['2026-11-25', '2026-11-24', '2026-11-23'], ['2026-02-30'],
            ['2026-11-26'], [20261125], ['day' => '2026-11-25']] as $dates) {
            $this->rejects('invalid_financial_destination', fn () => $this->both($dates,
                fn () => $this->fail('Invalid destination accepted.')));
        }
        DB::table('financial_scopes')->update(['active_freeze_uuid' => self::UUID]);
        $this->rejects('financial_scope_not_active', fn () => $this->both(['2026-11-25'],
            fn () => $this->fail('Frozen scope accepted.')));
    }

    public function test_nested_gate_rejected_and_callback_failure_rolls_back_all_writes(): void
    {
        $this->october();
        $dates = ['2026-10-31', '2026-11-25'];
        $this->rejects('financial_gate_must_be_first', fn () => $this->gate(fn () =>
            $this->both($dates, fn () => $this->fail('Nested gate accepted.'))));
        $registry = app(FinancialPostingRegistry::class);
        $leg = fn (string $id) => ['source_type' => 'synthetic_gate_probe', 'source_id' => $id,
            'leg' => 'probe', 'amount_minor' => 1, 'currency' => 'YER', 'source_hash' => str_repeat('a', 64)];
        $this->rejects('synthetic_callback_failure', fn () => $this->both($dates, function ($contexts) use ($registry, $leg): void {
            $this->assertTrue($registry->record($contexts['2026-10-31'], self::UUID, [$leg('one')], $this->nowAt()));
            $this->assertTrue($registry->record($contexts['2026-11-25'], self::OTHER, [$leg('two')], $this->nowAt()));
            throw new DomainException('synthetic_callback_failure');
        }));
        $this->assertDatabaseCount('financial_posting_operations', 0);
        $this->assertDatabaseCount('financial_posting_events', 0);
        $this->assertSame(0, DB::transactionLevel());
    }
}
