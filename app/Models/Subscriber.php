<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

use Illuminate\Database\Eloquent\SoftDeletes;

class Subscriber extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'name',
        'phone',
        'address',
        'square_id',
        'balance',
        'status',
        'subscription_start_date',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'subscription_start_date' => 'date',
    ];

    public function save(array $options = [])
    {
        if (! $this->exists || ! $this->isDirty('subscription_start_date')) {
            return parent::save($options);
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($options) {
            $fresh = self::query()->lockForUpdate()->findOrFail($this->id);
            if ($fresh->getRawOriginal('subscription_start_date') != $this->getRawOriginal('subscription_start_date')) {
                throw \Illuminate\Validation\ValidationException::withMessages(['subscription_start_date' => 'تغير تاريخ الخدمة؛ حدّث البيانات أولاً.']);
            }
            $meters = $fresh->meters()->withTrashed()->orderBy('id')->lockForUpdate()->get();
            if ($meters->contains(fn (Meter $meter) => $meter->readings()->withTrashed()->exists())
                || $fresh->invoices()->withTrashed()->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'subscription_start_date' => 'لا يمكن تغيير تاريخ بدء الخدمة بعد تسجيل القراءات أو الفواتير.',
                ]);
            }

            return parent::save($options);
        }, 3);
    }

    protected static function booted(): void
    {
        static::deleting(function (Subscriber $subscriber): void {
            if ($subscriber->meters()->withTrashed()->exists()
                || $subscriber->invoices()->withTrashed()->exists()
                || $subscriber->payments()->withTrashed()->exists()
                || $subscriber->transactions()->withTrashed()->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'subscriber' => 'لا يجوز حذف مشترك له عداد أو سجل مالي. يمكن إيقاف نشاطه.',
                ]);
            }
        });
    }

    // ─── Relationships ───

    public function square(): BelongsTo
    {
        return $this->belongsTo(Square::class);
    }

    public function meters(): HasMany
    {
        return $this->hasMany(Meter::class);
    }

    /** The one physical meter belonging to this subscriber (if assigned). */
    public function currentMeter(): HasOne
    {
        return $this->hasOne(Meter::class);
    }

    public function readings(): HasManyThrough
    {
        return $this->hasManyThrough(Reading::class, Meter::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }


    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }


    /**
     * حساب إجمالي المتأخرات (الفواتير غير المسددة بالكامل)
     */
    /**
     * Canonical signed subscriber account balance. Meter IDs are provenance,
     * not separate accounts. The ledger, not legacy balance caches, is authoritative.
     */
    public function getAccountBalance(): float
    {
        return round((float) $this->transactions()->sum('amount'), 2);
    }

    public function getAccountCredit(): float
    {
        return max(0, $this->getAccountBalance());
    }

    public function getAccountDebt(): float
    {
        return max(0, -$this->getAccountBalance());
    }

    /** Legacy general-only projection; not a collectible balance. */
    public function getOutstandingBalance(): float
    {
        // A negative balance means they owe money.
        return max(0, -1 * (float) $this->balance);
    }


}
