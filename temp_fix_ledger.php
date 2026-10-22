<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\Subscriber;
use App\Services\TransactionService;
use App\Services\PaymentService;

// 1. Find all wrong invoice transactions (positive amounts for invoices)
$wrongInvoicesTransactions = Transaction::where('type', 'debit')
    ->where('amount', '>', 0)
    ->where('reference_type', Invoice::class)
    ->get();

foreach ($wrongInvoicesTransactions as $tx) {
    // Check if the reference invoice exists
    $invoice = $tx->reference;
    if ($invoice && in_array($invoice->invoice_type, ['subscription', 'tanker'])) {
        // Fix the amount to be negative
        $tx->amount = -$tx->amount;
        $tx->type = 'invoice';
        $tx->saveQuietly();
        echo "Fixed transaction {$tx->id} for invoice {$invoice->id}\n";
    }
}

// 2. Delete all credit applied transactions (because they were redundant/wrong)
$wrongCreditTransactions = Transaction::where('type', 'credit')
    ->where('reference_type', Invoice::class)
    ->whereHasMorph('reference', [Invoice::class], function ($q) {
        $q->whereIn('invoice_type', ['subscription', 'tanker']);
    })
    ->get();

foreach ($wrongCreditTransactions as $tx) {
    $tx->delete();
    echo "Deleted credit transaction {$tx->id}\n";
}

// 3. Reset credit_applied and amount_paid for these invoices
$affectedInvoices = Invoice::whereIn('invoice_type', ['subscription', 'tanker'])
    ->where('credit_applied', '>', 0)
    ->get();

foreach ($affectedInvoices as $inv) {
    $inv->credit_applied = 0;
    // amount_paid will be recalculated by PaymentService
    $inv->saveQuietly();
    echo "Reset credit_applied for invoice {$inv->id}\n";
}

// 4. Recalculate balances and invoices for ALL subscribers
$subscribers = Subscriber::all();
$transactionService = new TransactionService();
$paymentService = new PaymentService();

foreach ($subscribers as $sub) {
    $transactionService->updateSubscriberBalance($sub);
    // Also update meter balances
    foreach ($sub->meters as $meter) {
        $transactionService->updateMeterBalance($meter);
    }
    // Redistribute payments
    $paymentService->recalculateInvoices($sub);
    foreach ($sub->meters as $meter) {
        $paymentService->recalculateInvoices($sub, $meter->id);
    }
}

echo "All balances and invoices recalculated successfully.\n";
