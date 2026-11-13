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
        'initial_subscription_fee',
        'subscription_fee_paid',
        'is_subscription_fee_fully_paid',
        'status',
        'subscription_start_date',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'initial_subscription_fee' => 'decimal:2',
        'subscription_fee_paid' => 'decimal:2',
        'is_subscription_fee_fully_paid' => 'boolean',
        'subscription_start_date' => 'date',
    ];

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

    public function subscriptionFees()
    {
        return $this->hasMany(SubscriptionFee::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function serviceRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }


    /**
     * حساب إجمالي المتأخرات (الفواتير غير المسددة بالكامل)
     */
    public function getOutstandingBalance(): float
    {
        // A negative balance means they owe money.
        return max(0, -1 * (float) $this->balance);
    }

    /**
     * الباقي من رسوم الاشتراك (غير المفوترة)
     */
    public function getRemainingSubscriptionFee(): float
    {
        return (float) $this->subscriptionFees()->where('status', 'uninvoiced')->sum('amount');
    }

    /**
     * الحصول على حالة رسوم الاشتراك بدقة
     * @return string (uninvoiced, invoiced, partially_paid, paid)
     */
    public function getSubscriptionFeeStatus(): string
    {
        if ($this->subscriptionFees()->where('status', 'uninvoiced')->exists()) {
            return 'uninvoiced';
        }
        if ($this->subscriptionFees()->where('status', 'partially_paid')->exists()) {
            return 'partially_paid';
        }
        if ($this->subscriptionFees()->where('status', 'invoiced')->exists()) {
            return 'invoiced';
        }
        if ($this->subscriptionFees()->where('status', 'paid')->exists()) {
            return 'paid';
        }
        
        return 'paid';
    }
}
