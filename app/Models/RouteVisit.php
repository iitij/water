<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** زيارة محطة في جولة المسار الميداني (Route Mode) — تُرفع من التطبيق. */
class RouteVisit extends Model
{
    protected $fillable = [
        'route_date',
        'meter_id',
        'square_id',
        'meter_code',
        'status',
        'collected_amount',
        'notes',
        'visit_at',
        'sync_uuid',
        'device_uuid',
        'user_id',
    ];

    protected $casts = [
        'route_date' => 'date',
        'visit_at' => 'datetime',
        'collected_amount' => 'decimal:2',
    ];

    public function meter(): BelongsTo
    {
        return $this->belongsTo(Meter::class);
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
