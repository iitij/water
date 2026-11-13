<?php

declare(strict_types=1);

// Run ONLY on a disposable MySQL database after migrate:fresh.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

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


 // Simulate the prior schema with a current meter plus a historical one.
 // This proves the new migration removes pre-existing data, not just that
 // a freshly created database is valid.
 Schema::table('meters', function (Blueprint $table): void {
     $table->dropUnique('meters_one_per_subscriber');
 });
 Schema::table('meters', function (Blueprint $table): void {
     $table->unsignedBigInteger('current_subscriber_id')
         ->nullable()
         ->virtualAs('CASE WHEN removed_at IS NULL AND deleted_at IS NULL THEN subscriber_id ELSE NULL END');
     $table->unique('current_subscriber_id', 'meters_one_current_per_subscriber');
 });
 $obsolete = DB::table('meters')->insertGetId([
     'unique_code' => 'mysql-meter-obsolete-history',
     'subscriber_id' => $subscriber,
     'removed_at' => '2026-11-01',
 ]);
 $obsoleteInvoice = DB::table('invoices')->insertGetId([
     'subscriber_id' => $subscriber,
     'meter_id' => $obsolete,
     'bill_number' => 'MYSQL-HIST-INV',
     'bill_date' => '2026-11-14',
     'invoice_type' => 'meter',
 ]);
 $obsoleteReceipt = DB::table('payments')->insertGetId([
     'subscriber_id' => $subscriber,
     'meter_id' => $obsolete,
     'invoice_id' => $obsoleteInvoice,
     'receipt_number' => 'MYSQL-HIST-REC',
     'payment_date' => '2026-11-14',
     'payment_method' => 'cash',
     'amount' => 20,
 ]);
 $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_14_010000_enforce_one_meter_record_per_subscriber.php';
 $migration->up();
 if (DB::table('meters')->where('subscriber_id', $subscriber)->count() !== 1
     || DB::table('meters')->where('id', $obsolete)->exists()
     || ! DB::table('meters')->where('id', $new)->exists()
     || DB::table('invoices')->where('id', $obsoleteInvoice)->exists()
     || DB::table('payments')->where('id', $obsoleteReceipt)->exists()
     || Schema::hasColumn('meters', 'current_subscriber_id')) {
     throw new RuntimeException('BUG: data backfill retained historical meter or finance.');
 }
 $expectDuplicate(fn () => DB::table('meters')->insert([
     'unique_code' => 'mysql-meter-after-migration',
     'subscriber_id' => $subscriber,
 ]));
 
 echo "PASS: MySQL one physical meter, irreversible legacy backfill and linked finance cleanup.\n";
// A database skipping the 2026-11-12 migration might still contain two
// CURRENT meters. Run the older migration before the final strict migration
// to prove that the older preflight no longer blocks the owner-requested
// cleanup or silently reuses an obsolete meter.
Schema::table('meters', function (Blueprint $table): void {
    $table->dropUnique('meters_one_per_subscriber');
});
$latestId = DB::table('meters')->insertGetId([
    'unique_code' => 'mysql-meter-new-current',
    'subscriber_id' => $subscriber,
]);
$olderMigration = require dirname(__DIR__, 2).'/database/migrations/2026_11_12_230000_enforce_one_current_meter_per_subscriber.php';
$olderMigration->up();
if (DB::table('meters')->where('id', $new)->exists()
    || ! DB::table('meters')->where('id', $latestId)->exists()
    || DB::table('meters')->where('subscriber_id', $subscriber)->count() !== 1) {
    throw new RuntimeException('BUG: earlier migration did not erase duplicate current meter.');
}
$migration->up();
$expectDuplicate(fn () => DB::table('meters')->insert([
    'unique_code' => 'mysql-meter-final-duplicate',
    'subscriber_id' => $subscriber,
]));

echo "PASS: pre-existing duplicate current meters also purge before the legacy migration.\n";


