<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use Illuminate\Database\Eloquent\SoftDeletes;

class Reading extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'meter_id',
        'reading_date',
        'actual_reading_date',
        'previous_reading',
        'current_reading',
        'consumption_units',
        'is_invoiced',
        'invoice_id',
        'created_by',
        'period_start',
        'period_end',
        'period_basis',
        'submitted_period_start',
        'submitted_period_end',
        'sync_uuid',
        'image_path',
    ];

    protected $casts = [
        'reading_date' => 'date',
        'actual_reading_date' => 'date',
        'previous_reading' => 'decimal:2',
        'current_reading' => 'decimal:2',
        'is_invoiced' => 'boolean',
    ];

    // ─── Relationships ───

    public function meter(): BelongsTo
    {
        return $this->belongsTo(Meter::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ─── Boot: Auto-calculate consumption ───

    protected static function booted(): void
    {
        static::deleting(function (Reading $reading) {
            if ($reading->is_invoiced) {
                throw new \Exception('لا يمكن حذف قراءة مفوترة. الرجاء حذف الفاتورة المرتبطة بها أولاً.');
            }
        });

        static::creating(function (Reading $reading) {
            // Auto-fill previous reading from meter's last reading
            if (empty($reading->previous_reading)) {
                $reading->previous_reading = $reading->meter->getLastReadingValue();
            }

            // Calculate consumption units
            $reading->consumption_units = max(0, (int) ($reading->current_reading - $reading->previous_reading));
        });

        static::updating(function (Reading $reading) {
            $reading->consumption_units = max(0, (int) ($reading->current_reading - $reading->previous_reading));
        });
    }
}
