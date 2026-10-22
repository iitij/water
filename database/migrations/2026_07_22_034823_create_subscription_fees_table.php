<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('subscription_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meter_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 10, 2)->default(0);
            $table->enum('status', ['uninvoiced', 'invoiced', 'partially_paid', 'paid'])->default('uninvoiced');
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Migrate existing data from subscribers table
        $subscribers = \App\Models\Subscriber::where('initial_subscription_fee', '>', 0)->get();
        foreach ($subscribers as $subscriber) {
            $status = 'uninvoiced';
            
            // Check existing invoice status logic from getSubscriptionFeeStatus()
            $hasUnpaid = \App\Models\Invoice::where('subscriber_id', $subscriber->id)
                ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
                ->whereHas('items', function ($q) {
                    $q->where('type', 'subscription');
                })
                ->exists();

            if ($subscriber->is_subscription_fee_fully_paid || $subscriber->subscription_fee_paid >= $subscriber->initial_subscription_fee) {
                $status = 'paid';
            } elseif ($hasUnpaid) {
                $status = 'invoiced';
                // Technically could be partially_paid if invoice is partially paid, but invoiced is safe.
            } elseif ($subscriber->subscription_fee_paid > 0) {
                $status = 'partially_paid';
            }
            
            // Find the invoice that contains the subscription fee, if any
            $invoiceId = null;
            if ($status !== 'uninvoiced') {
                $invoiceItem = \App\Models\InvoiceItem::where('type', 'subscription')
                    ->whereHas('invoice', function ($q) use ($subscriber) {
                        $q->where('subscriber_id', $subscriber->id);
                    })
                    ->latest()
                    ->first();
                if ($invoiceItem) {
                    $invoiceId = $invoiceItem->invoice_id;
                    $invStatus = $invoiceItem->invoice->status;
                    if ($invStatus === 'partially_paid' && $status !== 'paid') {
                        $status = 'partially_paid';
                    }
                }
            }

            // Optional: get first meter if exists
            $meterId = $subscriber->meters()->first()?->id;

            DB::table('subscription_fees')->insert([
                'subscriber_id' => $subscriber->id,
                'meter_id' => $meterId,
                'amount' => $subscriber->initial_subscription_fee,
                'status' => $status,
                'invoice_id' => $invoiceId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_fees');
    }
};
