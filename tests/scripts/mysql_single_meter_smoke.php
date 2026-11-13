<?php

declare(strict_types=1);

// Run ONLY on a disposable MySQL database after migrate:fresh.
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
if (Schema::hasColumn('meters', 'current_subscriber_id')) {
    throw new RuntimeException('Obsolete generated current_subscriber_id must be removed.');
}
foreach (['tenant_name', 'tenant_phone'] as $column) {
    if (Schema::hasColumn('meters', $column)) {
        throw new RuntimeException('Legacy tenant column still exists: '.$column);
    }
}

$square = DB::table('squares')->insertGetId(['name' => 'strict single meter MySQL smoke']);
$subscriber = DB::table('subscribers')->insertGetId([
    'name' => 'strict single meter MySQL subscriber',
    'square_id' => $square,
]);
$old = DB::table('meters')->insertGetId([
    'unique_code' => 'mysql-meter-old',
    'subscriber_id' => $subscriber,
    'status' => 'inactive',
]);

$expectDuplicate = static function (callable $operation): void {
    try {
        $operation();
    } catch (QueryException $exception) {
        if (($exception->errorInfo[1] ?? null) === 1062) {
            return;
        }
        throw $exception;
    }
    throw new RuntimeException('BUG: database accepted a second physical meter.');
};

// Inactive is still the sole meter.
$expectDuplicate(fn () => DB::table('meters')->insert([
    'unique_code' => 'mysql-meter-duplicate',
    'subscriber_id' => $subscriber,
]));

// Even legacy removed or soft-deleted rows must keep the slot occupied.
DB::table('meters')->where('id', $old)->update([
    'removed_at' => '2026-11-01',
    'deleted_at' => now(),
]);
$expectDuplicate(fn () => DB::table('meters')->insert([
    'unique_code' => 'mysql-meter-after-removal',
    'subscriber_id' => $subscriber,
]));

// Replacement requires physical deletion of the prior meter.
DB::table('meters')->where('id', $old)->delete();
$new = DB::table('meters')->insertGetId([
    'unique_code' => 'mysql-meter-replacement',
    'subscriber_id' => $subscriber,
]);
if (DB::table('meters')->where('subscriber_id', $subscriber)->count() !== 1
    || DB::table('meters')->where('id', $old)->exists()
    || ! DB::table('meters')->where('id', $new)->exists()) {
    throw new RuntimeException('BUG: prior meter still exists or replacement is missing.');
}

echo "PASS: MySQL strict UNIQUE subscriber_id, soft-delete/removed protection and physical replacement.\n";
