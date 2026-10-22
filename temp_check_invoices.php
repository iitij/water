<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$invoices = \App\Models\Invoice::latest()->take(3)->get()->map(function($i) {
    return [
        'id' => $i->id, 
        'type' => $i->invoice_type, 
        'total' => $i->total_amount, 
        'paid' => $i->amount_paid, 
        'credit' => $i->credit_applied, 
        'status' => $i->status, 
        'bal_before' => $i->previous_balance
    ];
});
echo json_encode($invoices);
