<?php

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\PaymentService;

// 1. Reset all invoices for subscriber 52 to 0 and 'issued'
$invoices = Invoice::where('subscriber_id', 52)->get();
foreach ($invoices as $inv) {
    $inv->amount_paid = 0;
    $inv->status = 'issued';
    $inv->saveQuietly();
}

// 2. Re-distribute ALL payments for subscriber 52
$payments = Payment::where('subscriber_id', 52)->orderBy('id')->get();
$paymentService = new PaymentService();

foreach ($payments as $payment) {
    $paymentService->distributePayment($payment);
}

// 3. Update status accurately
foreach ($invoices as $inv) {
    $inv->refresh();
    $inv->updateStatus();
}

echo "Cleanup Fixed!\n";
