<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'subscriber_id',
        'meter_id',
        'type',
        'amount',
        'description',
        'reference_id',
        'reference_type',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        $reject = static function (): void {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'transaction' => 'حركة دفتر الأستاذ غير قابلة للتعديل أو الحذف أو الاستعادة. استخدم مستند تصحيح معتمداً.',
            ]);
        };
        static::updating($reject);
        static::deleting($reject);
        static::restoring($reject);
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function meter(): BelongsTo
    {
        return $this->belongsTo(Meter::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
