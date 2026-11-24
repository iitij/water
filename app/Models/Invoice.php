<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'subscriber_id',
        'meter_id',
        'invoice_type',
        'bill_number',
        'bill_date',
        'billing_period_start',
        'billing_period_end',
        'consumption_fee',
        'previous_balance',
        'credit_applied',
        'total_amount',
        'amount_paid',
        'status',
        'notes',
        'voided_at', 'void_reason', 'voided_by',
    ];

    protected $casts = [
        'voided_at' => 'datetime',
        'bill_date' => 'date',
        'billing_period_start' => 'date',
        'billing_period_end' => 'date',
        'consumption_fee' => 'decimal:2',
        'previous_balance' => 'decimal:2',
        'credit_applied' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
    ];

    public function isFinanciallyLocked(): bool
    {
        return $this->exists && ($this->getOriginal('status') !== 'draft'
            || Transaction::withTrashed()->where('reference_type', self::class)
                ->where('reference_id', $this->id)->exists());
    }

    public function assertFinancialFieldsUnchanged(): void
    {
        if (! $this->isFinanciallyLocked()) {
            return;
        }

        $errors = [];
        foreach ([
            'subscriber_id', 'meter_id', 'invoice_type', 'bill_number', 'bill_date',
            'billing_period_start', 'billing_period_end', 'consumption_fee',
            'previous_balance', 'credit_applied', 'total_amount', 'amount_paid', 'status',
            'voided_at', 'void_reason', 'voided_by',
        ] as $field) {
            if ($this->isDirty($field)) {
                $errors[$field] = 'لا يجوز تعديل بيانات فاتورة معتمدة؛ يجب استخدام إجراء تصحيح محاسبي موثق.';
            }
        }
        if ($errors !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
    }

    // ─── Relationships ───

    protected static function booted(): void
    {
        static::updating(function (Invoice $invoice): void {
            $invoice->assertFinancialFieldsUnchanged();
            if ($invoice->getOriginal('status') === 'draft' && $invoice->isDirty('status')) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'status' => 'اعتماد المسودة يتطلب مسار ترحيل مالي صريح.',
                ]);
            }
        });

        static::created(function (Invoice $invoice) {
            if ($invoice->subscriber) {
                $paymentService = new \App\Services\PaymentService();
                $paymentService->recalculateInvoices($invoice->subscriber, $invoice->meter_id);
            }
        });

        $guard = function (Invoice $invoice): void {
            if ($invoice->isFinanciallyLocked()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'invoice' => 'لا يجوز حذف أو استعادة فاتورة معتمدة؛ استخدم الإلغاء المحاسبي.',
                ]);
            }
        };
        static::deleting($guard);
        static::restoring($guard);
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function meter(): BelongsTo
    {
        return $this->belongsTo(Meter::class);
    }

    public function readings(): HasMany
    {
        return $this->hasMany(Reading::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    // ─── Helper Methods ───

    /**
     * المبلغ المتبقي
     */
    public function getRemainingAmount(): float
    {
        if (in_array($this->status, ['draft', 'cancelled'], true) || $this->trashed()) {
            return 0.0;
        }
        // المبلغ الكلي المستحق لهذه الفاتورة فقط (تجاهل المتأخرات السابقة لتجنب المضاعفة)
        return max(0, (float) $this->total_amount - (float) $this->amount_paid);
    }

    /**
     * Live collection suggestion, not the historical amount printed at issue.
     * Only the newest invoice represents cumulative outstanding debt.
     */
    public function getCollectibleAmount(): float
    {
        if ($this->trashed() || in_array($this->status, ['draft', 'cancelled'], true)) {
            return 0.0;
        }

        return round($this->isLatestInvoice()
            ? $this->getTrueRemainingAmount()
            : $this->getRemainingAmount(), 2);
    }

    /**
     * المبلغ المتبقي التراكمي (يضم هذه الفاتورة والفواتير السابقة لنفس الحساب)
     */
    public function getTrueRemainingAmount(): float
    {
        if (in_array($this->status, ['draft', 'cancelled'], true) || $this->trashed()) {
            return 0.0;
        }
        $thisRemaining = $this->getRemainingAmount();

        $olderInvoicesRemaining = self::where('subscriber_id', $this->subscriber_id)
            ->where('id', '<', $this->id)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->get()
            ->sum(function ($inv) {
                return $inv->getRemainingAmount();
            });

        return $thisRemaining + $olderInvoicesRemaining;
    }
    /**
     * هل هذه الفاتورة هي الأحدث للمشترك/العداد؟
     */
    public function isLatestInvoice(): bool
    {
        $query = self::where('subscriber_id', $this->subscriber_id)
            ->whereNotIn('status', ['draft', 'cancelled']);
        
        $latest = $query->orderBy('id', 'desc')->first();

        return $latest && $latest->id === $this->id;
    }

    /**
     * تحديث الحالة بناءً على المدفوعات التراكمية
     */
    public function updateStatus(): void
    {
        if ($this->trashed() || in_array($this->status, ['draft', 'cancelled'], true)) {
            return;
        }

        $gracePeriod = (int) \App\Models\Setting::get('grace_period', '14');
        $strictOverdue = \App\Models\Setting::get('strict_overdue_on_new_invoice', '0') === '1';

        if ($this->isLatestInvoice()) {
            // للفاتورة الأخيرة: نظهر الحالة التراكمية للحساب
            $trueRemaining = $this->getTrueRemainingAmount();
            $totalDebt = (float) $this->total_amount + (float) $this->previous_balance;

            if ($trueRemaining <= 0) {
                $this->updateQuietly(['status' => 'paid']);
            } elseif ($trueRemaining < $totalDebt) {
                $this->updateQuietly(['status' => 'partially_paid']);
            } elseif (now()->isAfter($this->bill_date->addDays($gracePeriod))) {
                $this->updateQuietly(['status' => 'overdue']);
            } else {
                $this->updateQuietly(['status' => 'issued']);
            }
        } else {
            // للفواتير القديمة: نظهر الحالة الفعلية للفاتورة نفسها
            $remaining = $this->getRemainingAmount();

            if ($remaining <= 0) {
                $this->updateQuietly(['status' => 'paid']);
            } elseif ((float) $this->amount_paid > 0) {
                $this->updateQuietly(['status' => 'partially_paid']);
            } elseif ($strictOverdue || now()->isAfter($this->bill_date->addDays($gracePeriod))) {
                $this->updateQuietly(['status' => 'overdue']);
            } else {
                $this->updateQuietly(['status' => 'issued']);
            }
        }
    }

    /**
     * توليد رقم فاتورة فريد
     */
    public static function generateBillNumber(): string
    {
        $prefix = 'INV-' . date('Ym') . '-';
        $lastInvoice = static::withTrashed()
            ->where('bill_number', 'like', $prefix . '%')
            ->orderByDesc('bill_number')
            ->first();

        if ($lastInvoice) {
            $lastNumber = (int) str_replace($prefix, '', $lastInvoice->bill_number);
            return $prefix . str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        }

        return $prefix . '0001';
    }
}
