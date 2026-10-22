<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$invoices = \App\Models\Invoice::all();
$count = 0;
foreach ($invoices as $invoice) {
    if ($invoice->amount_paid > 0) {
        $directPayments = \App\Models\Payment::where('invoice_id', $invoice->id)->sum('amount');
        $creditApplied = max(0, $invoice->amount_paid - $directPayments);
        
        if ($creditApplied > 0 && $invoice->credit_applied == 0) {
            $invoice->update(['credit_applied' => $creditApplied]);
            $count++;
            echo "Updated Invoice ID {$invoice->id} with credit_applied = {$creditApplied}\n";
        }
    }
}
echo "Backfilled {$count} invoices.\n";
