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
    public function getOutstandingBalance(): float
    {
        // A negative balance means they owe money.
        return max(0, -1 * (float) $this->balance);
    }


}
