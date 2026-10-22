<?php

$payments = App\Models\Payment::all();
foreach($payments as $p) {
    if ($p->invoice_id) {
        $inv = App\Models\Invoice::withTrashed()->find($p->invoice_id);
        if ($inv && $inv->trashed()) {
            // Nullify the invoice_id
            $p->invoice_id = null;
            $p->save();
            
            // Re-distribute the payment
            $svc = new App\Services\PaymentService();
            $svc->distributePayment($p);
        }
    }
}

// Recalculate invoice statuses
App\Models\Invoice::all()->each->updateStatus();

echo "Payment redistribution complete!\n";
