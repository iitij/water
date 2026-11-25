<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Run only against an isolated synthetic MySQL water_test database.
 * fresh: caller has migrated an empty database to the current schema.
 * legacy-clean / legacy-financial: caller provides an entirely empty schema.
 * Never dismantle a current schema to emulate a historical schema.
 */
function requireCondition(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function expectMySqlError(callable $operation, int $code): void
{
    try {
        $operation();
    } catch (QueryException $exception) {
        if ((int) ($exception->errorInfo[1] ?? 0) === $code) {
            return;
        }
        throw $exception;
    }
    throw new RuntimeException("Expected MySQL error {$code}, but the write succeeded.");
}

function assertForeignKey(): void
{
    requireCondition((int) DB::selectOne('SELECT @@SESSION.foreign_key_checks AS enabled')->enabled === 1,
        'Foreign key checks must remain enabled.');
    $foreignKeys = Schema::getForeignKeys('meters');
    requireCondition(collect($foreignKeys)->contains(fn (array $key): bool =>
        $key['columns'] === ['subscriber_id']
        && $key['foreign_table'] === 'subscribers'
        && $key['foreign_columns'] === ['id']
        && strtolower($key['on_delete'] ?? '') === 'cascade'),
        'The subscriber foreign key or its delete rule is missing.');
    $missing = ((int) DB::table('subscribers')->max('id')) + 1;
    expectMySqlError(fn () => DB::table('meters')->insert([
        'unique_code' => 'qa-orphan-meter', 'subscriber_id' => $missing,
    ]), 1452);
}

function assertStrictSchema(): void
{
    requireCondition(! Schema::hasColumn('meters', 'current_subscriber_id'), 'Obsolete generated column remains.');
    foreach (['ten'.'ant_name', 'ten'.'ant_phone'] as $column) {
        requireCondition(! Schema::hasColumn('meters', $column), 'Obsolete identity column remains: '.$column);
    }
    requireCondition(collect(Schema::getIndexes('meters'))->contains(fn (array $index): bool =>
        $index['name'] === 'meters_one_per_subscriber'
        && $index['columns'] === ['subscriber_id'] && $index['unique']),
        'Final single-meter unique index is missing.');
    assertForeignKey();
}

function subscriber(string $name): int
{
    $square = DB::table('squares')->insertGetId(['name' => $name]);
    return (int) DB::table('subscribers')->insertGetId(['name' => $name, 'square_id' => $square]);
}

function meter(int $subscriber, string $code, array $attributes = []): int
{
    return (int) DB::table('meters')->insertGetId($attributes + [
        'subscriber_id' => $subscriber, 'unique_code' => $code,
    ]);
}

function expectDuplicateMeter(int $subscriber, string $code): void
{
    expectMySqlError(fn () => meter($subscriber, $code), 1062);
}

function migratePaths(array $paths): void
{
    requireCondition($paths !== [], 'No migration paths selected.');
    $result = Artisan::call('migrate', ['--path' => $paths, '--realpath' => true, '--force' => true, '--no-interaction' => true]);
    fwrite(STDOUT, Artisan::output());
    requireCondition($result === 0, 'Migration command failed.');
}

function freshScenario(): void
{
    assertStrictSchema();
    requireCondition(DB::table('meters')->count() === 0 && DB::table('subscribers')->count() === 0,
        'Expected fresh synthetic schema with no subscriber or meter records.');
    $subscriber = subscriber('QA fresh single meter');
    $old = meter($subscriber, 'qa-fresh-old', ['status' => 'inactive']);
    expectDuplicateMeter($subscriber, 'qa-fresh-duplicate');
    DB::table('meters')->where('id', $old)->update(['removed_at' => '2026-11-01', 'deleted_at' => now()]);
    expectDuplicateMeter($subscriber, 'qa-fresh-after-removal');
    DB::table('meters')->where('id', $old)->delete();
    $new = meter($subscriber, 'qa-fresh-replacement');
    requireCondition(DB::table('meters')->where('subscriber_id', $subscriber)->count() === 1
        && ! DB::table('meters')->where('id', $old)->exists()
        && DB::table('meters')->where('id', $new)->exists(), 'Replacement did not preserve one physical meter.');
    assertStrictSchema();
    echo "PASS: fresh single-meter uniqueness and foreign key\n";
}

function legacyScenario(bool $financial): void
{
    requireCondition(Schema::getTables() === [], 'Legacy fixture requires an entirely empty disposable schema.');
    $directory = dirname(__DIR__, 2).'/database/migrations';
    $first = $directory.'/2026_11_12_230000_enforce_one_current_meter_per_subscriber.php';
    $strict = $directory.'/2026_11_14_010000_enforce_one_meter_record_per_subscriber.php';
    requireCondition(is_file($first) && is_file($strict), 'Expected historical migration boundary not found.');
    $paths = glob($directory.'/*.php') ?: [];
    sort($paths, SORT_STRING);
    migratePaths(array_values(array_filter($paths, fn (string $path): bool => strcmp($path, $first) < 0)));
    requireCondition(! Schema::hasColumn('meters', 'current_subscriber_id'), 'Unexpected historical schema.');
    assertForeignKey();

    $subscriber = subscriber('QA historical single meter');
    $historical = meter($subscriber, 'qa-legacy-removed', ['removed_at' => '2026-11-01']);
    $oldCurrent = meter($subscriber, 'qa-legacy-current-old');
    $newCurrent = meter($subscriber, 'qa-legacy-current-new');
    $softDeleted = meter($subscriber, 'qa-legacy-soft-deleted', ['deleted_at' => now()]);
    $reading = DB::table('readings')->insertGetId([
        'meter_id' => $historical, 'reading_date' => '2026-10-31',
        'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
        'previous_reading' => 0, 'current_reading' => 20, 'consumption_units' => 20,
    ]);

    // The actual older migration must remove only the obsolete current meter.
    migratePaths([$first]);
    requireCondition(Schema::hasColumn('meters', 'current_subscriber_id')
        && ! DB::table('meters')->where('id', $oldCurrent)->exists()
        && DB::table('meters')->where('id', $newCurrent)->exists()
        && DB::table('meters')->where('id', $historical)->exists()
        && DB::table('meters')->where('id', $softDeleted)->exists(),
        'Earlier migration did not preserve the expected historical/current rows.');
    assertForeignKey();
    expectDuplicateMeter($subscriber, 'qa-legacy-duplicate-current');

    if ($financial) {
        // Current MeterDataPurger must refuse to delete financial history.
        $invoice = DB::table('invoices')->insertGetId([
            'subscriber_id' => $subscriber, 'meter_id' => $historical,
            'bill_number' => 'QA-LEGACY-INV', 'bill_date' => '2026-10-31',
            'invoice_type' => 'meter', 'total_amount' => '50.00',
        ]);
        DB::table('payments')->insert([
            'subscriber_id' => $subscriber, 'meter_id' => $historical, 'invoice_id' => $invoice,
            'receipt_number' => 'QA-LEGACY-REC', 'payment_date' => '2026-11-01',
            'payment_method' => 'cash', 'amount' => '20.00',
        ]);
        $snapshot = fn (): array => array_map(
            fn (string $table) => DB::table($table)->orderBy('id')->get()->all(),
            ['meters', 'invoices', 'payments', 'readings', 'transactions', 'subscribers']
        );
        $before = $snapshot();
        $rejected = false;
        try {
            // Call the unchanged migration directly to inspect its domain exception.
            (require $strict)->up();
        } catch (ValidationException $exception) {
            $rejected = array_key_exists('meter_id', $exception->errors());
            if (! $rejected) {
                throw $exception;
            }
        }
        requireCondition($rejected, 'Strict migration did not reject a financially protected meter.');
        requireCondition($before == $snapshot(), 'Financial rejection changed fixture records.');
        requireCondition(Schema::hasColumn('meters', 'current_subscriber_id')
            && ! DB::table('migrations')->where('migration', basename($strict, '.php'))->exists(),
            'Rejected strict migration changed the schema or migration history.');
        assertForeignKey();
        expectDuplicateMeter($subscriber, 'qa-legacy-after-rejection');
        echo "PASS: historical financial protection and rollback\n";
        return;
    }

    migratePaths([$strict]);
    requireCondition(DB::table('meters')->where('subscriber_id', $subscriber)->count() === 1
        && DB::table('meters')->where('id', $newCurrent)->exists()
        && ! DB::table('meters')->whereIn('id', [$oldCurrent, $historical, $softDeleted])->exists()
        && ! DB::table('readings')->where('id', $reading)->exists(),
        'Strict migration did not remove obsolete unbilled operational records.');
    assertStrictSchema();
    expectDuplicateMeter($subscriber, 'qa-legacy-final-duplicate');
    echo "PASS: historical operational cleanup and final constraints\n";
}

try {
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    requireCondition($app->environment('testing')
        && DB::connection()->getDriverName() === 'mysql'
        && DB::connection()->getDatabaseName() === 'water_test'
        && getenv('WATER_SINGLE_METER_TEST_DB') === 'water_test',
        'Refusing to run without explicit isolated MySQL water_test authorization.');
    $mode = $argv[1] ?? 'fresh';
    match ($mode) {
        'fresh' => freshScenario(),
        'legacy-clean' => legacyScenario(false),
        'legacy-financial' => legacyScenario(true),
        default => throw new InvalidArgumentException('Unknown smoke test mode.'),
    };
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: '.get_class($exception).': '.$exception->getMessage().PHP_EOL);
    exit(1);
}
