<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** Historical product regression, not the revised G10 smoke fixture. */
#[Group('obsolete-meter-legacy')]
class ObsoleteMeterLegacyMigrationTest extends TestCase
{
    public function test_original_twenty_unit_observation_is_removed_by_actual_legacy_migrations(): void
    {
        [$first, $strict] = $this->prepareHistoricalSchema();

        $square = DB::table('squares')->insertGetId(['name' => 'Synthetic historical cleanup']);
        $subscriber = DB::table('subscribers')->insertGetId(['square_id' => $square, 'name' => 'Legacy survivor']);
        $historical = $this->meter($subscriber, 'LEGACY-REMOVED', ['removed_at' => '2026-11-01']);
        $oldCurrent = $this->meter($subscriber, 'LEGACY-DUPLICATE');
        $survivor = $this->meter($subscriber, 'LEGACY-KEEP', ['status' => 'inactive']);
        $softDeleted = $this->meter($subscriber, 'LEGACY-SOFT', ['deleted_at' => now()]);
        $original = $this->reading($historical, 20);
        $duplicate = $this->reading($oldCurrent, 5);
        $kept = $this->reading($survivor, 15);
        $soft = $this->reading($softDeleted, 4);
        $noCurrent = DB::table('subscribers')->insertGetId(['square_id' => $square, 'name' => 'Only removed']);
        $lastRemoved = $this->meter($noCurrent, 'LEGACY-NONE', ['removed_at' => '2026-11-01']);
        $noneReading = $this->reading($lastRemoved, 9);
        $survivorBefore = DB::table('readings')->where('id', $kept)->first();
        $this->assertDatabaseHas('readings', ['id' => $original, 'consumption_units' => 20, 'is_invoiced' => false]);

        $this->migratePaths([$first]);
        $this->assertDatabaseMissing('meters', ['id' => $oldCurrent]);
        $this->assertDatabaseMissing('readings', ['id' => $duplicate]);
        $this->assertDatabaseHas('readings', ['id' => $original, 'consumption_units' => 20]);
        $this->assertDatabaseHas('meters', ['id' => $survivor, 'status' => 'inactive']);

        $this->migratePaths([$strict]);
        $this->assertDatabaseCount('meters', 1);
        $this->assertDatabaseCount('readings', 1);
        $this->assertDatabaseHas('meters', ['id' => $survivor, 'status' => 'inactive']);
        foreach ([$original, $duplicate, $soft, $noneReading] as $id) {
            $this->assertDatabaseMissing('readings', ['id' => $id]);
        }
        $this->assertEquals($survivorBefore, DB::table('readings')->where('id', $kept)->first());
        foreach (['invoices', 'payments', 'transactions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertFalse(Schema::hasColumn('meters', 'current_subscriber_id'));
        $this->assertTrue(Schema::hasIndex('meters', 'meters_one_per_subscriber', 'unique'));
        $this->assertTrue(collect(Schema::getForeignKeys('meters'))->contains(
            fn ($key) => $key['columns'] === ['subscriber_id'] && $key['foreign_table'] === 'subscribers'
        ));
    }

    public function test_legacy_financial_conflict_rolls_back_earlier_pending_cleanup(): void
    {
        [$first, $strict] = $this->prepareHistoricalSchema();
        $square = DB::table('squares')->insertGetId(['name' => 'Synthetic legacy financial rollback']);
        $subscriber = DB::table('subscribers')->insertGetId(['square_id' => $square, 'name' => 'Financial survivor']);
        $financial = $this->meter($subscriber, 'LEGACY-FINANCIAL', ['removed_at' => '2026-11-01']);
        // The higher-ID obsolete pending meter is visited before the protected meter.
        $pending = $this->meter($subscriber, 'LEGACY-PENDING', ['removed_at' => '2026-11-01']);
        $survivor = $this->meter($subscriber, 'LEGACY-FIN-KEEP');
        $this->reading($financial, 20);
        $this->reading($pending, 5);
        $this->reading($survivor, 15);
        $invoice = DB::table('invoices')->insertGetId([
            'subscriber_id' => $subscriber, 'meter_id' => $financial,
            'bill_number' => 'LEGACY-ROLLBACK-INV', 'bill_date' => '2026-10-31',
            'invoice_type' => 'meter', 'total_amount' => '50.00',
        ]);
        DB::table('payments')->insert([
            'subscriber_id' => $subscriber, 'meter_id' => $financial, 'invoice_id' => $invoice,
            'receipt_number' => 'LEGACY-ROLLBACK-REC', 'payment_date' => '2026-11-01',
            'payment_method' => 'cash', 'amount' => '20.00',
        ]);
        $this->migratePaths([$first]);
        $snapshot = fn () => array_map(
            fn ($table) => DB::table($table)->orderBy('id')->get()->all(),
            ['meters', 'readings', 'invoices', 'payments', 'transactions', 'subscribers', 'audit_events', 'migrations']
        );
        $before = $snapshot();
        try {
            (require $strict)->up();
            $this->fail('Legacy migration removed a protected financial meter.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('meter_id', $e->errors());
        }
        $this->assertEquals($before, $snapshot());
        $this->assertTrue(Schema::hasColumn('meters', 'current_subscriber_id'));
        $this->assertFalse(Schema::hasIndex('meters', 'meters_one_per_subscriber'));
    }

    private function prepareHistoricalSchema(): array
    {
        if (getenv('WATER_OBSOLETE_METER_LEGACY_TEST') !== '1') {
            $this->markTestSkipped('Requires explicit isolated empty historical-migration database.');
        }
        $this->assertTrue(app()->environment('testing'));
        $this->assertTrue(
            (DB::getDriverName() === 'sqlite' && DB::connection()->getDatabaseName() === ':memory:')
            || (DB::getDriverName() === 'mysql' && DB::connection()->getDatabaseName() === 'water_test'),
            'Use only a disposable SQLite memory or MySQL water_test schema.'
        );
        $this->assertSame([], Schema::getTables(), 'Historical regression requires an empty schema; no destructive reset.');
        \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;
        $directory = database_path('migrations');
        $first = $directory.'/2026_11_12_230000_enforce_one_current_meter_per_subscriber.php';
        $strict = $directory.'/2026_11_14_010000_enforce_one_meter_record_per_subscriber.php';
        $paths = glob($directory.'/*.php');
        sort($paths, SORT_STRING);
        $this->migratePaths(array_values(array_filter($paths, fn ($p) => strcmp($p, $first) < 0)));

        return [$first, $strict];
    }

    private function migratePaths(array $paths): void
    {
        $this->artisan('migrate', ['--path' => $paths, '--realpath' => true,
            '--force' => true, '--no-interaction' => true])->assertExitCode(0);
    }

    private function meter(int $subscriber, string $code, array $extra = []): int
    {
        return DB::table('meters')->insertGetId($extra + ['subscriber_id' => $subscriber, 'unique_code' => $code]);
    }

    private function reading(int $meter, int $units): int
    {
        return DB::table('readings')->insertGetId([
            'meter_id' => $meter, 'reading_date' => '2026-10-31',
            'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
            'previous_reading' => 0, 'current_reading' => $units, 'consumption_units' => $units,
            'is_invoiced' => false,
        ]);
    }
}
