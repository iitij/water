<?php

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\InvoiceItem;
use App\Services\PaymentService;

// 1. Fix INV-0060
$inv60 = Invoice::find(70);
if ($inv60) {
    $inv60->credit_applied = 0;
    $inv60->total_amount = $inv60->consumption_fee + $inv60->subscription_installment + $inv60->tanker_charges;
    $inv60->saveQuietly(); // avoid triggering events

    // Remove the credit item
    InvoiceItem::where('invoice_id', 70)->where('type', 'credit')->delete();
}

// 2. Reset amount_paid on all active invoices for subscriber 52
$invoices = Invoice::where('subscriber_id', 52)->get();
foreach ($invoices as $inv) {
    $inv->amount_paid = 0;
    $inv->saveQuietly();
}

// 3. Re-distribute all payments for subscriber 52
$payments = Payment::where('subscriber_id', 52)->orderBy('id')->get();
$paymentService = new PaymentService();

foreach ($payments as $payment) {
    // If the payment is linked to a trashed invoice, unlink it!
    if ($payment->invoice_id) {
        $linkedInv = Invoice::withTrashed()->find($payment->invoice_id);
        if ($linkedInv && $linkedInv->trashed()) {
            $payment->invoice_id = null;
            $payment->saveQuietly();
        }
    }
    
    // Distribute
    $paymentService->distributePayment($payment);
}

// 4. Update status for all invoices
foreach ($invoices as $inv) {
    $inv->refresh();
    $inv->updateStatus();
}

echo "Final Cleanup Complete!\n";
