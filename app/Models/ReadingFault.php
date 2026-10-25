<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** عطل قراءة ميداني يرفعه المحصّل من التطبيق (لا وصول، عداد مسدود، ...). */
class ReadingFault extends Model
{
    protected $fillable = [
        'meter_id',
        'subscriber_id',
        'square_id',
        'reading_date',
        'fault_type',
        'notes',
        'sync_uuid',
        'recorded_at',
        'device_uuid',
        'user_id',
    ];

    protected $casts = [
        'reading_date' => 'date',
        'recorded_at' => 'datetime',
    ];

    public function meter(): BelongsTo
    {
        return $this->belongsTo(Meter::class);
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function square(): BelongsTo
    {
        return $this->belongsTo(Square::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
