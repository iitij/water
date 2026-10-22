<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'subscriber_id',
        'meter_id',
        'invoice_id',
        'user_id',
        'receipt_number',
        'amount',
        'payment_date',
        'payment_method',
        'reference_number',
        'notes',
        'reason',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
    ];

    // ─── Relationships ───

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function meter(): BelongsTo
    {
        return $this->belongsTo(Meter::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    protected static function booted()
    {
        // ملاحظة: منطق الإنشاء يُعالَج بالكامل في PaymentService
        // هذا يضمن التوزيع الصحيح للمبالغ والرصيد الدائن

        static::updated(function (Payment $payment) {
            // Handle transaction ledger
            $transactionService = new \App\Services\TransactionService();
            $transaction = \App\Models\Transaction::where('reference_type', Payment::class)
                ->where('reference_id', $payment->id)
                ->first();

            if ($transaction) {
                $transaction->update([
                    'subscriber_id' => $payment->subscriber_id,
                    'amount' => $payment->amount,
                ]);
            }
            
            // Re-calculate balance for old and new subscriber
            if ($payment->isDirty('subscriber_id') && $oldSubId = $payment->getOriginal('subscriber_id')) {
                if ($oldSub = Subscriber::find($oldSubId)) {
                    $transactionService->updateSubscriberBalance($oldSub);
                }
            }
            if ($payment->subscriber) {
                $transactionService->updateSubscriberBalance($payment->subscriber);
                // Recalculate invoices because amount or invoice target might have changed
                $paymentService = new \App\Services\PaymentService();
                $paymentService->recalculateInvoices($payment->subscriber, $payment->meter_id);
            }
        });

        static::deleted(function (Payment $payment) {
            \App\Models\Transaction::where('reference_type', Payment::class)
                ->where('reference_id', $payment->id)
                ->delete();

            $transactionService = new \App\Services\TransactionService();
            if ($payment->meter) {
                $transactionService->updateMeterBalance($payment->meter);
            } elseif ($payment->subscriber) {
                $transactionService->updateSubscriberBalance($payment->subscriber);
            }
            
            if ($payment->subscriber) {
                $paymentService = new \App\Services\PaymentService();
                $paymentService->recalculateInvoices($payment->subscriber, $payment->meter_id);
            }
        });
    }

    /**
     * توليد رقم سند قبض فريد
     */
    public static function generateReceiptNumber(): string
    {
        $prefix = 'REC-' . date('Ym') . '-';
        $lastPayment = static::withTrashed()
            ->where('receipt_number', 'like', $prefix . '%')
            ->orderByDesc('receipt_number')
            ->first();

        if ($lastPayment) {
            $lastNumber = (int) str_replace($prefix, '', $lastPayment->receipt_number);
            return $prefix . str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        }

        return $prefix . '0001';
    }
}
