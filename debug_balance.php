<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$sub = \App\Models\Subscriber::where('name', 'like', '%الشتيوي%')->first();
echo $sub->name . ' - Outstanding: ' . $sub->getOutstandingBalance() . PHP_EOL;
foreach($sub->invoices as $inv) {
    echo $inv->bill_number . ' Total: ' . $inv->total_amount . ' Paid: ' . $inv->amount_paid . ' Status: ' . $inv->status . PHP_EOL;
}
