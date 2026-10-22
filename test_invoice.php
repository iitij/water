<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$sub = \App\Models\Subscriber::first();
$service = new \App\Services\InvoiceService();

echo "Generating invoice for subscriber {$sub->id}...\n";
try {
    $inv = $service->generateInvoice($sub);
    echo "Invoice generated: {$inv->bill_number}\n";
    echo "Period: {$inv->billing_period_start} to {$inv->billing_period_end}\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
