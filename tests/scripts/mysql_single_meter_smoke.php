<?php

declare(strict_types=1);

// Run ONLY against a disposable MySQL database, after migrate:fresh.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (DB::connection()->getDriverName() !== 'mysql') {
    throw new RuntimeException('Refusing to run: expected MySQL.');
}
if (! str_contains((string) DB::connection()->getDatabaseName(), 'test')) {
    throw new RuntimeException('Refusing to run against a non-test database.');
}
if (! Schema::hasColumn('meters', 'current_subscriber_id')) {
    throw new RuntimeException('Generated column missing from MySQL schema.');
}
foreach (['tenant_name', 'tenant_phone'] as $obsoleteColumn) {
    if (Schema::hasColumn('meters', $obsoleteColumn)) {
        throw new RuntimeException('Legacy tenant column still exists: '.$obsoleteColumn);
    }
}

$square = DB::table('squares')->insertGetId(['name' => 'single meter MySQL smoke']);
$subscriber = DB::table('subscribers')->insertGetId([
    'name' => 'single meter MySQL subscriber',
    'square_id' => $square,
]);
$old = DB::table('meters')->insertGetId([
    'unique_code' => 'mysql-meter-old',
    'subscriber_id' => $subscriber,
    'status' => 'inactive',
]);
try {
    DB::table('meters')->insert([
        'unique_code' => 'mysql-meter-duplicate',
        'subscriber_id' => $subscriber,
    ]);
    throw new RuntimeException('BUG: database accepted a second current meter.');
} catch (QueryException $exception) {
    // A UNIQUE conflict is the expected result. Do not swallow other errors.
    if (($exception->errorInfo[1] ?? null) != 1062) {
        throw $exception;
    }
}

DB::table('meters')->where('id', $old)->update(['removed_at' => '2026-11-01']);
$new = DB::table('meters')->insertGetId([
    'unique_code' => 'mysql-meter-replacement',
    'subscriber_id' => $subscriber,
]);
if (DB::table('meters')->where('subscriber_id', $subscriber)->whereNull('removed_at')->whereNull('deleted_at')->count() !== 1) {
    throw new RuntimeException('BUG: wrong number of current meters.');
}
if (DB::table('meters')->where('subscriber_id', $subscriber)->count() !== 2) {
    throw new RuntimeException('BUG: historical record lost.');
}
try {
    DB::table('meters')->where('id', $old)->update(['removed_at' => null]);
    throw new RuntimeException('BUG: historical reactivation bypassed uniqueness.');
} catch (QueryException $exception) {
    if (($exception->errorInfo[1] ?? null) != 1062) {
        throw $exception;
    }
}

echo "PASS: MySQL generated UNIQUE index, inactive-current block, replacement history and reactivation block.\n";
