<?php

namespace Tests\Feature;

use App\Models\Meter;
use App\Models\Payment;
use App\Models\Square;
use App\Models\Subscriber;
use App\Services\PaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Real MySQL DDL test; no RefreshDatabase transaction around trigger installation. */
final class LedgerMysqlProtectionTest extends TestCase
{
    public function test_mysql_guard_installation_and_raw_posting_immutability(): void
    {
        if (! app()->environment('testing') || DB::getDriverName() !== 'mysql'
            || DB::connection()->getDatabaseName() !== 'water_test') {
            $this->markTestSkipped('Requires isolated MySQL water_test database.');
        }
        // This test deliberately recreates the disposable database outside RefreshDatabase.
        // Force the next transactional test class to rebuild its own empty fixtures.
        \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->artisan('ledger:protect', ['--install' => true])->assertExitCode(0);
        $this->artisan('ledger:protect', ['--check' => true])->assertExitCode(0);
        $subscriber = Subscriber::create(['name' => 'Synthetic MySQL guard', 'square_id' => Square::create(['name' => 'Protection'])->id, 'status' => 'active']);
        $meter = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'MYSQL-LEDGER-GUARD']);
        $receipt = app(PaymentService::class)->collectPayment($subscriber, 100.25, requestedMeterId: $meter->id);
        $row = DB::table('transactions')->where('reference_type', Payment::class)->where('reference_id', $receipt->id)->first();
        foreach ([
            fn () => DB::table('transactions')->where('id', $row->id)->update(['amount' => 1]),
            fn () => DB::table('transactions')->where('id', $row->id)->update(['deleted_at' => now()]),
            fn () => DB::table('transactions')->where('id', $row->id)->update(['meter_id' => null]),
            fn () => DB::table('transactions')->where('id', $row->id)->delete(),
            fn () => DB::statement('REPLACE INTO transactions (id, subscriber_id, amount, type) VALUES (?, ?, ?, ?)',
                [$row->id, $subscriber->id, 1, 'adjustment']),
            fn () => DB::table('subscribers')->where('id', $subscriber->id)->delete(),
            fn () => DB::table('meters')->where('id', $meter->id)->delete(),
        ] as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('Unsafe MySQL operation succeeded.');
            } catch (QueryException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
        DB::transaction(fn () => app(\App\Services\FinancialMeterRetirementService::class)->retire($meter, $subscriber));
        $this->assertDatabaseHas('transactions', ['id' => $row->id, 'meter_id' => null,
            'retired_meter_id' => $meter->id, 'amount' => '100.25']);
        $this->assertSame(100.25, $subscriber->getAccountBalance());
    }
}
