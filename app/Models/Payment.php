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
        'sync_uuid',
        'cash_session_id',
        'voided_at',
        'void_reason',
        'voided_by',
        'voided_via',
    ];

    protected $casts = [
        'payment_date' => 'datetime',
        'amount' => 'decimal:2',
        'voided_at' => 'datetime',
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

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /** السندات الصالحة فقط — تُستخدم في كل حساب مالي. */
    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereNull('voided_at');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    protected static function booted()
    {
        // ملاحظة: منطق الإنشاء يُعالَج بالكامل في PaymentService
        // هذا يضمن التوزيع الصحيح للمبالغ والرصيد الدائن

        static::updated(function (Payment $payment) {
            $transactionService = new \App\Services\TransactionService();
            $paymentService = new \App\Services\PaymentService();
            $oldSubscriberId = (int) $payment->getOriginal('subscriber_id');
            $oldMeterId = $payment->getOriginal('meter_id') ? (int) $payment->getOriginal('meter_id') : null;

            $transaction = \App\Models\Transaction::where('reference_type', Payment::class)
                ->where('reference_id', $payment->id)
                ->first();
            if ($transaction) {
                $transaction->update([
                    'subscriber_id' => $payment->subscriber_id,
                    'meter_id' => $payment->meter_id,
                    'amount' => $payment->amount,
                ]);
            }

            if ($oldMeterId) {
                if ($oldMeter = \App\Models\Meter::find($oldMeterId)) {
                    $transactionService->updateMeterBalance($oldMeter);
                }
            } elseif ($oldSubscriber = Subscriber::find($oldSubscriberId)) {
                $transactionService->updateSubscriberBalance($oldSubscriber);
            }

            if ($payment->meter_id && $payment->meter) {
                $transactionService->updateMeterBalance($payment->meter);
            } elseif ($payment->subscriber) {
                $transactionService->updateSubscriberBalance($payment->subscriber);
            }

            if ($oldSubscriber = Subscriber::find($oldSubscriberId)) {
                $paymentService->recalculateInvoices($oldSubscriber, $oldMeterId);
            }
            if ($payment->subscriber) {
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
