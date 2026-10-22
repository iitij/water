<?php

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscriber;
use Illuminate\Support\Facades\DB;

// Bootstrap Laravel inside a script (if running standalone, but we will run it via `php artisan tinker`)

echo "Starting data cleanup...\n";

DB::transaction(function () {
    // 1. Reset all invoice amounts paid to 0 and statuses to issued.
    echo "Resetting invoices...\n";
    Invoice::query()->update([
        'amount_paid' => 0,
        'status' => 'issued',
    ]);

    // 2. We need to re-apply all payments to their invoices properly using the new logic.
    echo "Reapplying payments...\n";
    $payments = Payment::orderBy('payment_date')->orderBy('id')->get();

    foreach ($payments as $payment) {
        $remainingAmount = $payment->amount;

        // Apply to the attached invoice first
        if ($payment->invoice_id) {
            $invoice = Invoice::find($payment->invoice_id);
            if ($invoice) {
                $totalDue = (float) $invoice->total_amount;
                $alreadyPaid = (float) $invoice->amount_paid;
                $invoiceRemaining = max(0, $totalDue - $alreadyPaid);
                $applied = min($remainingAmount, $invoiceRemaining);

                if ($applied > 0) {
                    $invoice->update(['amount_paid' => $alreadyPaid + $applied]);
                    $invoice->refresh();
                    $invoice->updateStatus();
                }

                $remainingAmount -= $applied;
            }
        }

        // Apply remaining amount to oldest unpaid invoices (FIFO)
        if ($remainingAmount > 0) {
            $unpaidInvoices = Invoice::where('subscriber_id', $payment->subscriber_id)
                ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
                ->orderBy('bill_date')
                ->orderBy('id')
                ->get();

            foreach ($unpaidInvoices as $invoice) {
                if ($remainingAmount <= 0) break;

                $totalDue = (float) $invoice->total_amount;
                $alreadyPaid = (float) $invoice->amount_paid;
                $invoiceRemaining = max(0, $totalDue - $alreadyPaid);
                $applied = min($remainingAmount, $invoiceRemaining);

                if ($applied > 0) {
                    $invoice->update(['amount_paid' => $alreadyPaid + $applied]);
                    $invoice->refresh();
                    $invoice->updateStatus();
                }

                $remainingAmount -= $applied;
            }
        }
    }

    echo "Finished cleaning up payments and invoices.\n";
});

echo "Done!\n";
