<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Square;
use App\Services\FinancialWriteGate;
use App\Services\PaymentService;
use DateTimeImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class FinancialWriteGateFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing') || ! (
            (DB::getDriverName() === 'sqlite' && DB::getDatabaseName() === ':memory:')
            || (DB::getDriverName() === 'mysql' && DB::getDatabaseName() === 'water_test')
        )) {
            $this->markTestSkipped('Requires disposable isolated financial gate test database.');
        }
        RefreshDatabaseState::$migrated = false;
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }

    private function scope(array $changes = []): void
    {
        DB::table('financial_scopes')->insert(array_replace([
            'scope_key' => 'water', 'currency' => 'YER', 'timezone' => 'Asia/Aden',
            'calendar_version' => 1,
        ], $changes));
    }

    private function period(array $changes = []): int
    {
        return DB::table('financial_periods')->insertGetId(array_replace([
            'scope_key' => 'water', 'month' => '2026-11', 'start_on' => '2026-11-01',
            'next_on' => '2026-12-01', 'state' => 'OPEN', 'version' => 0,
        ], $changes));
    }

    private function runGate(callable $writer, string $date = '2026-11-25'): mixed
    {
        return app(FinancialWriteGate::class)->withinOpenPeriod('water', $date,
            new DateTimeImmutable('2026-11-24T21:05:00Z'), $writer);
    }

    private function rejected(string $expected, callable $action): void
    {
        try {
            $action();
            $this->fail('Gate accepted an invalid destination.');
        } catch (DomainException $e) {
            $this->assertSame($expected, $e->getMessage());
        }
        $this->assertSame(0, DB::connection()->transactionLevel());
    }

    public function test_no_automatic_activation_and_no_writer_on_invalid_state_or_date(): void
    {
        $never = fn () => $this->fail('Blocked writer was invoked.');
        $this->assertSame(0, DB::table('financial_scopes')->count());
        $this->assertSame(0, DB::table('financial_periods')->count());
        $this->rejected('financial_scope_not_active', fn () => $this->runGate($never));
        $this->scope();
        $this->assertSame('LEGACY_DISABLED', DB::table('financial_scopes')->value('mode'));
        $this->period();
        $this->rejected('financial_scope_not_active', fn () => $this->runGate($never));
        DB::table('financial_scopes')->update(['mode' => 'ACTIVE', 'active_freeze_uuid' => '27b7cb50-8b2e-4324-a763-3d9c0cc8a443']);
        $this->rejected('financial_scope_not_active', fn () => $this->runGate($never));
        DB::table('financial_scopes')->update(['active_freeze_uuid' => null]);
        foreach (['FROZEN', 'CLOSED', 'CORRUPT'] as $state) {
            DB::table('financial_periods')->update(['state' => $state]);
            $this->rejected('financial_period_not_open', fn () => $this->runGate($never));
        }
        DB::table('financial_periods')->update(['state' => 'OPEN', 'next_on' => '2026-12-02']);
        $this->rejected('financial_period_not_open', fn () => $this->runGate($never));
        DB::table('financial_periods')->update(['next_on' => '2026-12-01']);
        $this->rejected('financial_period_not_open', fn () => $this->runGate($never, '2026-10-01'));
        $this->rejected('invalid_financial_destination', fn () => $this->runGate($never, '2026-11-26'));
        $this->rejected('invalid_financial_destination', fn () => $this->runGate($never, '2026-11-31'));
    }

    public function test_gate_returns_verified_context_and_payment_rollback_is_atomic(): void
    {
        // Only synthetic fixture activation. There is deliberately no activation API.
        $this->scope(['mode' => 'ACTIVE', 'fence_epoch' => 9]);
        $id = $this->period(['version' => 7]);
        $account = Square::create(['name' => 'Synthetic gate'])
            ->subscribers()->create(['name' => 'Synthetic customer', 'status' => 'active']);
        try {
            $this->runGate(function (array $context) use ($id, $account): void {
                $this->assertSame(['period_id' => $id, 'scope' => 'water', 'effective_on' => '2026-11-25',
                    'epoch' => 9, 'version' => 7], $context);
                $this->assertSame(1, DB::connection()->transactionLevel());
                app(PaymentService::class)->collectPayment($account, '10.25');
                throw new \RuntimeException('synthetic outer rollback');
            });
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $e) {
            $this->assertSame('synthetic outer rollback', $e->getMessage());
        }
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, DB::table('transactions')->count());
        $this->assertSame(0, DB::table('document_sequences')->count());
        $payment = $this->runGate(fn () => app(PaymentService::class)->collectPayment($account, '10.25'));
        $this->assertSame('10.25', $payment->amount);
        $this->assertSame(1025, $account->fresh()->getAccountBalanceMinor());
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame(1, DB::table('transactions')->count());
    }

    public function test_gate_refuses_nested_entry_and_database_enforces_scope_and_month_uniqueness(): void
    {
        $this->scope(['mode' => 'ACTIVE']);
        $this->period();
        DB::transaction(function (): void {
            try {
                $this->runGate(fn () => $this->fail('Nested gate was invoked.'));
                $this->fail('Nested gate was accepted.');
            } catch (DomainException $e) {
                $this->assertSame('financial_gate_must_be_first', $e->getMessage());
            }
        });
        try {
            $this->period();
            $this->fail('Duplicate monthly period accepted.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame(1, DB::table('financial_periods')->count());
        }
        try {
            DB::table('financial_scopes')->where('scope_key', 'water')->delete();
            $this->fail('Referenced scope was deleted.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame(1, DB::table('financial_scopes')->count());
        }
    }
}
