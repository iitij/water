<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$sub = \App\Models\Subscriber::first();
$meter = \App\Models\Meter::where('subscriber_id', $sub->id)->first();

DB::table('readings')->insert([
    'meter_id' => $meter->id,
    'reading_date' => '2026-06-30',
    'actual_reading_date' => '2026-07-02',
    'current_reading' => 50,
    'consumption_units' => 10,
    'is_invoiced' => false,
    'created_by' => 1
]);

$service = new \App\Services\InvoiceService();

echo "Generating second invoice for subscriber {$sub->id}...\n";
try {
    $inv = $service->generateInvoice($sub);
    echo "Invoice generated: {$inv->bill_number}\n";
    echo "Period: {$inv->billing_period_start} to {$inv->billing_period_end}\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
