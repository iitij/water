<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscriber;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateToLedger extends Command
{
    protected $signature = 'ledger:migrate';
    protected $description = 'Migrate existing invoices and payments to the transactions ledger';

    public function handle()
    {
        DB::transaction(function () {
            // Clear existing transactions
            Transaction::query()->delete();

            // 1. Migrate Invoices (Debit)
            $invoices = Invoice::all();
            foreach ($invoices as $invoice) {
                // The new charges are: total_amount + credit_applied. 
                // Wait, if credit was applied, it means they had credit, but the invoice value is subtotal.
                $subtotal = $invoice->consumption_fee;

                Transaction::create([
                    'subscriber_id' => $invoice->subscriber_id,
                    'type' => 'invoice',
                    'amount' => -$subtotal, // Debit
                    'description' => 'إصدار فاتورة رقم ' . $invoice->bill_number,
                    'reference_type' => Invoice::class,
                    'reference_id' => $invoice->id,
                    'created_at' => $invoice->created_at,
                    'updated_at' => $invoice->updated_at,
                ]);
            }

            // 2. Migrate Payments (Credit)
            $payments = Payment::all();
            foreach ($payments as $payment) {
                Transaction::create([
                    'subscriber_id' => $payment->subscriber_id,
                    'type' => 'payment',
                    'amount' => $payment->amount, // Credit
                    'description' => 'سند قبض رقم ' . $payment->receipt_number,
                    'reference_type' => Payment::class,
                    'reference_id' => $payment->id,
                    'created_at' => $payment->created_at,
                    'updated_at' => $payment->updated_at,
                ]);
            }

            // 3. Update Subscriber Balances
            $subscribers = Subscriber::all();
            foreach ($subscribers as $subscriber) {
                $totalBalance = Transaction::where('subscriber_id', $subscriber->id)->sum('amount');
                $subscriber->updateQuietly(['balance' => $totalBalance]);
            }

            $this->info('Migration to ledger completed successfully.');
        });
    }
}
