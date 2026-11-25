<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\Support\AccountMoney;
use App\Support\MoneyBoundary;
use App\Support\SignedMinor;

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
            'voided_at', 'void_reason', 'voided_by', 'retired_meter_id', 'retired_meter_code',
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

    public function tariffLines(): HasMany
    {
        return $this->hasMany(InvoiceTariffLine::class)->orderBy('period_start')->orderBy('id');
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
    public function getRemainingMinor(): int
    {
        if (in_array($this->status, ['draft', 'cancelled'], true) || $this->trashed()) {
            return 0;
        }
        $billed = AccountMoney::stored($this, 'total_amount');
        $paid = AccountMoney::stored($this, 'amount_paid');
        if ($billed < 0 || $paid < 0 || $paid > $billed) {
            throw new \LogicException('Invoice allocation must be between zero and its own billed amount.');
        }
        return SignedMinor::subtract($billed, $paid);
    }

    public function getRemainingAmount(): float
    {
        return MoneyBoundary::numericMajorOutput($this->getRemainingMinor());
    }

    public function scopeWithOwnDebt(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereNotIn('status', ['draft', 'cancelled'])
            ->whereNull('deleted_at')->whereColumn('total_amount', '>', 'amount_paid');
    }

    public function scopeCollectibleForSubscriber(\Illuminate\Database\Eloquent\Builder $query, ?int $subscriberId): \Illuminate\Database\Eloquent\Builder
    {
        $query->where('subscriber_id', $subscriberId)->whereNotIn('status', ['draft', 'cancelled']);
        $latestId = null;
        if ($subscriberId && self::where('subscriber_id', $subscriberId)->withOwnDebt()->exists()) {
            $latestId = self::where('subscriber_id', $subscriberId)->whereNotIn('status', ['draft', 'cancelled'])->max('id');
        }
        return $query->where(function ($eligible) use ($latestId) {
            $eligible->whereColumn('total_amount', '>', 'amount_paid');
            if ($latestId !== null) {
                $eligible->orWhere('id', $latestId);
            }
        });
    }

    public function canCollect(): bool
    {
        return $this->getCollectibleMinor() > 0;
    }

    /**
     * Live collection suggestion, not the historical amount printed at issue.
     * Only the newest invoice represents cumulative outstanding debt.
     */
    public function getCollectibleMinor(): int
    {
        if ($this->trashed() || in_array($this->status, ['draft', 'cancelled'], true)) {
            return 0;
        }
        return $this->isLatestInvoice() ? $this->getTrueRemainingMinor() : $this->getRemainingMinor();
    }

    public function getCollectibleAmount(): float
    {
        return MoneyBoundary::numericMajorOutput($this->getCollectibleMinor());
    }

    /**
     * المبلغ المتبقي التراكمي (يضم هذه الفاتورة والفواتير السابقة لنفس الحساب)
     */
    public function getTrueRemainingMinor(): int
    {
        if (in_array($this->status, ['draft', 'cancelled'], true) || $this->trashed()) {
            return 0;
        }
        $remaining = $this->getRemainingMinor();
        foreach (self::where('subscriber_id', $this->subscriber_id)
            ->where('id', '<', $this->id)->whereNotIn('status', ['draft', 'cancelled'])
            ->orderBy('id')->cursor() as $invoice) {
            $remaining = SignedMinor::add($remaining, $invoice->getRemainingMinor());
        }
        return $remaining;
    }

    public function getTrueRemainingAmount(): float
    {
        return MoneyBoundary::numericMajorOutput($this->getTrueRemainingMinor());
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
     * Invoice payment status is independent of cumulative account debt.
     * Overdue takes priority over partial payment when an own debt remains.
     * The grace deadline is inclusive of the whole calendar day.
     */
    public function updateStatus(): void
    {
        if ($this->trashed() || in_array($this->status, ['draft', 'cancelled'], true)) {
            return;
        }

        $remaining = $this->getRemainingMinor();
        $gracePeriod = max(0, min(365, (int) Setting::get('grace_period', '14')));
        $strictOverdue = Setting::get('strict_overdue_on_new_invoice', '0') === '1';

        if ($remaining <= 0) {
            $status = 'paid';
        } elseif (($strictOverdue && ! $this->isLatestInvoice())
            || ($this->bill_date && today()->gt($this->bill_date->copy()->startOfDay()->addDays($gracePeriod)))) {
            $status = 'overdue';
        } elseif (AccountMoney::stored($this, 'amount_paid') > 0) {
            $status = 'partially_paid';
        } else {
            $status = 'issued';
        }

        if ($this->status !== $status) {
            $this->updateQuietly(['status' => $status]);
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
