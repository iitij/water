<?php

declare(strict_types=1);

// Only a disposable MySQL database may be used for destructive upgrade tests.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (DB::connection()->getDriverName() !== 'mysql'
    || ! str_contains((string) DB::connection()->getDatabaseName(), 'test')) {
    throw new RuntimeException('A disposable MySQL test database is required.');
}

$upgrader = require dirname(__DIR__, 2).'/database/migrations/2026_11_16_000001_final_retired_billing_cleanup.php';

Schema::table('invoices', static function (Blueprint $table): void {
    $table->decimal('tanker_charges', 12, 2)->default(0);
});
Schema::create('service_requests', static function (Blueprint $table): void {
    $table->id();
    $table->unsignedBigInteger('subscriber_id');
    $table->decimal('amount', 12, 2);
});
$square = DB::table('squares')->insertGetId(['name' => 'retired billing MySQL test']);
$subscriber = DB::table('subscribers')->insertGetId([
    'name' => 'retired billing test subscriber',
    'square_id' => $square,
]);
$invoice = DB::table('invoices')->insertGetId([
    'subscriber_id' => $subscriber,
    'bill_number' => 'RET-CLEAN-SMOKE-1',
    'bill_date' => now()->toDateString(),
    'invoice_type' => 'meter',
    'tanker_charges' => 50,
]);
DB::table('service_requests')->insert(['subscriber_id' => $subscriber, 'amount' => 50]);

$mustReject = static function () use ($upgrader): void {
    try {
        $upgrader->up();
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), 'reconciled')) {
            return;
        }
        throw $e;
    }
    throw new RuntimeException('Unreconciled retired invoice data was silently destroyed.');
};
$mustReject();
if (! Schema::hasTable('service_requests')
    || ! Schema::hasColumn('invoices', 'tanker_charges')) {
    throw new RuntimeException('The migration dropped legacy tables despite pending money.');
}

DB::table('invoices')->where('id', $invoice)->update([
    'tanker_charges' => 0,
    'invoice_type' => 'tanker',
]);
$mustReject();
DB::table('invoices')->where('id', $invoice)->update(['invoice_type' => 'meter']);

$item = DB::table('invoice_items')->insertGetId([
    'invoice_id' => $invoice,
    'type' => 'tanker',
    'description' => 'obsolete test charge',
    'amount' => 0,
]);
$mustReject();
DB::table('invoice_items')->where('id', $item)->delete();

DB::table('settings')->insert([
    ['key' => 'inv_show_tanker', 'value' => '1'],
    ['key' => 'reading_invoice_message_template', 'value' => 'رسوم الوايت في فاتورة قديمة'],
    ['key' => 'subscription_invoice_template', 'value' => 'اشتراك سليم'],
]);
DB::table('permissions')->insert([
    ['name' => 'ViewAny:ServiceRequest', 'guard_name' => 'web'],
    ['name' => 'view_any_service_request', 'guard_name' => 'web'],
]);
$upgrader->up();

if (Schema::hasTable('service_requests')
    || Schema::hasColumn('invoices', 'tanker_charges')
    || ! DB::table('invoices')->where('id', $invoice)->exists()
    || DB::table('settings')->where('key', 'inv_show_tanker')->exists()
    || DB::table('settings')->where('key', 'reading_invoice_message_template')->exists()
    || ! DB::table('settings')->where('key', 'subscription_invoice_template')->exists()
    || DB::table('permissions')->where('name', 'ViewAny:ServiceRequest')->exists()
    || DB::table('permissions')->where('name', 'view_any_service_request')->exists()) {
    throw new RuntimeException('Legacy schema upgrade did not fully clean retired dependencies.');
}
echo "PASS: MySQL upgrade blocks unreconciled charges and cleans only retired schema/config.\n";
