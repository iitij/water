<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

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

    /** Observation anchors must not be silently changed by another client. */
    private function hasObservationSuccessor(): bool
    {
        $date = $this->getOriginal('actual_reading_date') ?? $this->getOriginal('reading_date');
        if (! $date || ! $this->getOriginal('meter_id')) {
            return false;
        }

        return self::query()->where('meter_id', $this->getOriginal('meter_id'))
            ->whereKeyNot($this->id)->where('period_basis', 'observation')
            ->whereDate('period_start', CarbonImmutable::parse($date)->toDateString())->exists();
    }

    private function guardObservationUpdate(): void
    {
        $fields = ['meter_id', 'reading_date', 'actual_reading_date', 'previous_reading', 'current_reading',
            'period_start', 'period_end', 'period_basis'];
        if (! $this->isDirty($fields)) {
            return;
        }
        if ($this->getOriginal('is_invoiced') || $this->hasObservationSuccessor()) {
            throw ValidationException::withMessages(['reading_date' => 'لا يمكن تغيير قراءة مفوترة أو أصبحت أساساً لقراءة لاحقة.']);
        }
        if ($this->isDirty('period_basis')) {
            throw ValidationException::withMessages(['period_basis' => 'لا يمكن تغيير أساس الفترة المحفوظ.']);
        }
        if ($this->getOriginal('period_basis') !== 'observation') {
            return;
        }
        if ($this->isDirty(['meter_id', 'previous_reading', 'period_start'])) {
            throw ValidationException::withMessages(['period_start' => 'لا يجوز تغيير العداد أو بداية الفترة أو قيمة القراءة السابقة.']);
        }
        if ($this->isDirty('reading_date') && ! $this->isDirty('actual_reading_date')) {
            $this->actual_reading_date = $this->reading_date;
        }
        $observed = $this->actual_reading_date ?? $this->reading_date;
        $end = $observed ? CarbonImmutable::parse($observed)->toDateString() : null;
        if (! $end || $end <= CarbonImmutable::parse($this->period_start)->toDateString()
            || $end > today()->toDateString()
            || (float) $this->current_reading < (float) $this->previous_reading) {
            throw ValidationException::withMessages(['reading_date' => 'تاريخ أو قيمة القراءة المصححة غير صالح.']);
        }
        $this->period_end = $end;
    }

    // ─── Boot: Auto-calculate consumption ───

    protected static function booted(): void
    {
        static::deleting(function (Reading $reading) {
            if ($reading->hasObservationSuccessor()) {
                throw ValidationException::withMessages(['reading_date' => 'لا يمكن حذف قراءة أصبحت أساساً لقراءة لاحقة.']);
            }
            if ($reading->is_invoiced || $reading->retired_meter_id) {
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
            if ($reading->getOriginal('retired_meter_id') || $reading->isDirty(['retired_meter_id', 'retired_meter_code'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(['reading' => 'قراءة مالية تاريخية غير قابلة للتعديل.']);
            }
            $reading->guardObservationUpdate();
            $reading->consumption_units = max(0, (int) ($reading->current_reading - $reading->previous_reading));
        });
    }
}
