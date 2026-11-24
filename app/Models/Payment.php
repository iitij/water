<?php

namespace App\Models;

use App\Services\PaymentService;
use App\Services\TransactionService;
use Illuminate\Database\Eloquent\Builder;
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
        'void_requested_at',
        'void_requested_by',
        'void_request_reason',
        'void_decision_notes',
        'whatsapp_sent_at',
        'whatsapp_sent_via',
        'whatsapp_sent_by',
        'void_decided_by',
        'void_decided_at',
    ];

    protected $casts = [
        'payment_date' => 'datetime',
        'amount' => 'decimal:2',
        'voided_at' => 'datetime',
        'void_requested_at' => 'datetime',
        'void_decided_at' => 'datetime',
        'whatsapp_sent_at' => 'datetime',
    ];

    /** حقول محسوبة تُضاف تلقائياً إلى استجابات الـ API لتطبيق الهاتف. */
    protected $appends = ['voided', 'void_requested'];

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

    /** المحصّل الذي طلب الإلغاء (قبل الاعتماد). */
    public function voidRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'void_requested_by');
    }

    /** المشرف الذي بتّ في طلب الإلغاء. */
    public function voidDecider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'void_decided_by');
    }

    /** طلب إلغاء معلّق: طُلب ولم يُلغَ بعد ولم يُرفض. */
    public function scopeVoidRequested(Builder $query): Builder
    {
        return $query->whereNotNull('void_requested_at')->whereNull('voided_at');
    }

    public function hasPendingVoidRequest(): bool
    {
        return $this->void_requested_at !== null && $this->voided_at === null;
    }

    /** السندات الصالحة فقط — تُستخدم في كل حساب مالي. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /** السند الملغي (للاستخدام في JSON). */
    public function getVoidedAttribute(): bool
    {
        return $this->voided_at !== null;
    }

    /** هل يوجد طلب إلغاء معلّق بانتظار قرار المشرف؟ */
    public function getVoidRequestedAttribute(): bool
    {
        return $this->void_requested_at !== null && $this->voided_at === null;
    }

    public function assertFinancialFieldsUnchanged(): void
    {
        $errors = [];
        foreach ([
            'subscriber_id', 'meter_id', 'invoice_id', 'user_id', 'receipt_number',
            'amount', 'payment_date', 'payment_method', 'reference_number',
            'reason', 'sync_uuid', 'cash_session_id',
        ] as $field) {
            if ($this->isDirty($field)) {
                $errors[$field] = 'لا يجوز تعديل بيانات سند قبض معتمد؛ استخدم إجراء إلغاء السند المعتمد ثم سجل سنداً صحيحاً.';
            }
        }
        if ($errors !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
    }

    protected static function booted()
    {
        static::updating(function (Payment $payment): void {
            $payment->assertFinancialFieldsUnchanged();
        });

        // ملاحظة: منطق الإنشاء يُعالَج بالكامل في PaymentService
        // هذا يضمن التوزيع الصحيح للمبالغ والرصيد الدائن

        static::updated(function (Payment $payment) {
            // Administrative notes and delivery metadata must not rewrite money.
            // The explicit void service handles financial reversal and allocation.
            if (! $payment->wasChanged(['amount', 'subscriber_id', 'meter_id', 'invoice_id'])) {
                return;
            }
            $transactionService = new TransactionService;
            $paymentService = new PaymentService;
            $oldSubscriberId = (int) $payment->getOriginal('subscriber_id');
            $oldMeterId = $payment->getOriginal('meter_id') ? (int) $payment->getOriginal('meter_id') : null;

            $transaction = Transaction::where('reference_type', Payment::class)
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
                if ($oldMeter = Meter::find($oldMeterId)) {
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
            Transaction::where('reference_type', Payment::class)
                ->where('reference_id', $payment->id)
                ->delete();

            $transactionService = new TransactionService;
            if ($payment->meter) {
                $transactionService->updateMeterBalance($payment->meter);
            } elseif ($payment->subscriber) {
                $transactionService->updateSubscriberBalance($payment->subscriber);
            }

            if ($payment->subscriber) {
                $paymentService = new PaymentService;
                $paymentService->recalculateInvoices($payment->subscriber, $payment->meter_id);
            }
        });
    }

    /**
     * توليد رقم سند قبض فريد
     */
    public static function generateReceiptNumber(): string
    {
        $prefix = 'REC-'.date('Ym').'-';
        $lastPayment = static::withTrashed()
            ->where('receipt_number', 'like', $prefix.'%')
            ->orderByDesc('receipt_number')
            ->first();

        if ($lastPayment) {
            $lastNumber = (int) str_replace($prefix, '', $lastPayment->receipt_number);

            return $prefix.str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        }

        return $prefix.'0001';
    }
}
