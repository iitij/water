<?php

namespace App\Models;

use App\Services\PaymentService;
use App\Services\GatedReceiptVoidWriter;
use Illuminate\Support\Facades\DB;
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

    public function refunds(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }

    public function accountTransfer(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(PaymentAccountTransfer::class);
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
            'reason', 'sync_uuid', 'cash_session_id', 'voided_at', 'void_reason', 'voided_by', 'voided_via', 'retired_meter_id', 'retired_meter_code',
        ] as $field) {
            if ($this->isDirty($field)) {
                $errors[$field] = 'لا يجوز تعديل بيانات سند قبض معتمد؛ استخدم إجراء إلغاء السند المعتمد ثم سجل سنداً صحيحاً.';
            }
        }
        if ($errors !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
    }

    public static function receiptFenceEnabled(): bool
    {
        foreach (['cash_receipts', 'late_cash_settlements', 'noncash_settlements'] as $flag) {
            if (config('financial_candidate.'.$flag) === true) return true;
        }
        return false;
    }

    public function hasRegisteredReceipt(): bool
    {
        $db = \Illuminate\Support\Facades\DB::class;
        if ($this->sync_uuid !== null && $db::table('financial_posting_operations')
            ->where('scope_key', 'water')->where('operation_uuid', $this->sync_uuid)->exists()) return true;
        if (! $this->exists) return false;
        $ids = Transaction::withTrashed()->where('reference_type', self::class)
            ->where('reference_id', $this->id)->whereIn('type', ['payment', 'payment_reversal'])->pluck('id');
        return $db::table('financial_posting_events')->where('scope_key', 'water')
            ->where('source_type', 'ledger_transaction')->where('leg', 'subscriber')
            ->whereIn('source_id', $ids->map(fn ($id) => (string) $id)->all())->exists();
    }

    public function save(array $options = [])
    {
        $service = $options['financial_receipt_service'] ?? null;
        $voidWriter = $options['financial_receipt_void_writer'] ?? null;
        unset($options['financial_receipt_service'], $options['financial_receipt_void_writer']);
        if ($this->exists && $service === null) {
            $immutable = ['id', 'subscriber_id', 'meter_id', 'invoice_id', 'user_id', 'receipt_number',
                'amount', 'payment_date', 'payment_method', 'reference_number', 'reason', 'sync_uuid',
                'cash_session_id', 'retired_meter_id', 'retired_meter_code', 'deleted_at', 'created_at'];
            $voidFields = ['voided_at', 'void_reason', 'voided_by', 'voided_via',
                'void_requested_at', 'void_requested_by', 'void_request_reason',
                'void_decision_notes', 'void_decided_by', 'void_decided_at'];
            if (! $this->isDirty(array_merge($immutable, $voidFields)) && $voidWriter === null) {
                return parent::save($options);
            }
            return DB::transaction(function () use ($options, $voidWriter, $immutable, $voidFields) {
                $scope = DB::table('financial_scopes')->where('scope_key', 'water')->lockForUpdate()->first();
                if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                    throw new \LogicException('Payment issuance lock is missing.');
                }
                $persisted = self::withTrashed()->lockForUpdate()->findOrFail($this->getRawOriginal($this->getKeyName()));
                $gatedRequest = DB::table('audit_events')->where('entity_type', self::class)
                    ->where('entity_id', (string) $persisted->id)->where('event', 'financial.void_request_gated')->exists();
                $protected = $scope !== null || Transaction::receiptReversalFenceEnabled() || $persisted->hasRegisteredReceipt()
                    || $gatedRequest || $voidWriter !== null;
                if ($protected && ($this->isDirty($immutable)
                    || ! ($voidWriter instanceof GatedReceiptVoidWriter
                        && $this->isDirty($voidFields) && $voidWriter->allowsMutation($persisted)))) {
                    throw new \DomainException('gated_receipt_document_mutation_required');
                }
                return parent::save($options);
            });
        }
        if ($voidWriter !== null) {
            throw new \DomainException('gated_receipt_document_mutation_required');
        }
        return \Illuminate\Support\Facades\DB::transaction(function () use ($options, $service) {
            $scope = DB::table('financial_scopes')->where('scope_key', 'water')->lockForUpdate()->first();
            if (! \Illuminate\Support\Facades\DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                throw new \LogicException('Payment issuance lock is missing.');
            }
            if (($scope !== null || $service !== null || self::receiptFenceEnabled() || $this->hasRegisteredReceipt())
                && ! ($service instanceof PaymentService && $service->allowsReceiptCreation($this))) {
                throw new \DomainException('gated_receipt_model_writer_required');
            }
            return parent::save($options);
        });
    }

    protected static function booted()
    {
        static::updating(function (Payment $payment): void {
            $payment->assertFinancialFieldsUnchanged();
        });

        // ملاحظة: منطق الإنشاء يُعالَج بالكامل في PaymentService
        // هذا يضمن التوزيع الصحيح للمبالغ والرصيد الدائن

        $guard = function (): void {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'payment' => 'لا يجوز حذف أو استعادة سند قبض؛ استخدم إجراء الإلغاء المحاسبي.',
            ]);
        };
        static::deleting($guard);
        static::restoring($guard);
    }

}
