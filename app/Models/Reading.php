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

    protected $attributes = ['period_basis' => 'observation', 'is_invoiced' => false];
    protected $fillable = [
        'meter_id',
        'reading_date',
        'actual_reading_date',
        'previous_reading',
        'current_reading',
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
        'consumption_units' => 'decimal:2',
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

    /** Serialize edits/deletes with insertion, replacement and invoice posting. */
    private function withLockedObservation(callable $callback)
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($callback) {
            $meterId = $this->getOriginal('meter_id');
            if ($meterId) {
                Meter::query()->lockForUpdate()->findOrFail($meterId);
            }
            $fresh = self::withTrashed()->lockForUpdate()->findOrFail($this->getKey());
            foreach (['meter_id', 'reading_date', 'actual_reading_date', 'period_start', 'period_end',
                'period_basis', 'previous_reading', 'current_reading', 'is_invoiced', 'invoice_id',
                'retired_meter_id', 'deleted_at'] as $field) {
                if ($fresh->getOriginal($field) != $this->getOriginal($field)) {
                    throw ValidationException::withMessages(['reading_date' => 'تغيرت القراءة؛ حدّث البيانات وأعد المحاولة.']);
                }
            }

            return $callback();
        }, 3);
    }

    public function save(array $options = [])
    {
        if (! $this->exists) {
            return \Illuminate\Support\Facades\DB::transaction(function () use ($options) {
                $meter = Meter::findOrFail($this->meter_id);
                Subscriber::query()->lockForUpdate()->findOrFail($meter->subscriber_id);
                $locked = Meter::query()->lockForUpdate()->findOrFail($meter->id);
                abort_unless((int) $locked->subscriber_id === (int) $meter->subscriber_id, 409);

                return parent::save($options);
            }, 3);
        }
        if ($this->exists && $this->isDirty(['meter_id', 'reading_date', 'actual_reading_date',
            'previous_reading', 'current_reading', 'period_start', 'period_end', 'period_basis'])) {
            return $this->withLockedObservation(fn () => parent::save($options));
        }

        return parent::save($options);
    }

    public function delete()
    {
        if (! $this->exists) {
            return parent::delete();
        }

        return $this->withLockedObservation(fn () => parent::delete());
    }

    /** Observation anchors must not be silently changed by another client. */
    private function hasObservationSuccessor(): bool
    {
        $date = $this->getOriginal('actual_reading_date') ?? $this->getOriginal('reading_date');
        if (! $date || ! $this->getOriginal('meter_id')) {
            return false;
        }

        return self::query()->where('meter_id', $this->getOriginal('meter_id'))
            ->whereKeyNot($this->id)
            ->whereRaw('DATE(COALESCE(actual_reading_date, reading_date)) > ?', [CarbonImmutable::parse($date)->toDateString()])->exists();
    }

    private function guardObservationUpdate(): void
    {
        $fields = ['meter_id', 'reading_date', 'actual_reading_date', 'previous_reading', 'current_reading',
            'period_start', 'period_end', 'period_basis'];
        if (! $this->isDirty($fields)) {
            return;
        }
        if ($this->getOriginal('is_invoiced') || $this->hasObservationSuccessor()
            || \Illuminate\Support\Facades\DB::table('invoice_tariff_lines')->where('reading_id', $this->id)->exists()) {
            throw ValidationException::withMessages(['reading_date' => 'لا يمكن تغيير قراءة مفوترة أو أصبحت أساساً لقراءة لاحقة.']);
        }
        if ($this->isDirty('period_basis')) {
            throw ValidationException::withMessages(['period_basis' => 'لا يمكن تغيير أساس الفترة المحفوظ.']);
        }
        if ($this->getOriginal('period_basis') !== 'observation') {
            throw ValidationException::withMessages(['period_start' => 'لا يمكن تعديل فترة غير قائمة على قراءة فعلية.']);
        }
        if ($this->isDirty(['meter_id', 'previous_reading', 'period_start'])) {
            throw ValidationException::withMessages(['period_start' => 'لا يجوز تغيير العداد أو بداية الفترة أو قيمة القراءة السابقة.']);
        }
        if ($this->isDirty('reading_date') && ! $this->isDirty('actual_reading_date')
            && ($this->getOriginal('actual_reading_date') === null
                || $this->getOriginal('actual_reading_date')->toDateString() === $this->getOriginal('reading_date')->toDateString())) {
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

    /** Meter readings have two decimal places; calculate in hundredths, not floats. */
    private function calculateConsumption(): void
    {
        foreach (['previous_reading', 'current_reading'] as $field) {
            if (! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', (string) ($this->getAttributes()[$field] ?? ''))) {
                throw ValidationException::withMessages([$field => 'القراءة يجب أن تكون غير سالبة وبمنزلتين عشريتين كحد أقصى.']);
            }
        }
        $toHundredths = static function ($value): int {
            if (! preg_match('/^\d{1,10}\.\d{2}$/D', (string) $value)) {
                throw ValidationException::withMessages(['current_reading' => 'قيمة قراءة العداد خارج النطاق المسموح.']);
            }

            return (int) str_replace('.', '', (string) $value);
        };
        $units = $toHundredths($this->current_reading) - $toHundredths($this->previous_reading);
        if ($units < 0) {
            throw ValidationException::withMessages(['current_reading' => 'القراءة الحالية أقل من القراءة السابقة.']);
        }
        $this->consumption_units = intdiv($units, 100).'.'.str_pad((string) ($units % 100), 2, '0', STR_PAD_LEFT);
    }

    // ─── Boot: Auto-calculate consumption ───

    protected static function booted(): void
    {
        static::deleting(function (Reading $reading) {
            if ($reading->hasObservationSuccessor()) {
                throw ValidationException::withMessages(['reading_date' => 'لا يمكن حذف قراءة أصبحت أساساً لقراءة لاحقة.']);
            }
            if ($reading->is_invoiced || $reading->retired_meter_id
                || \Illuminate\Support\Facades\DB::table('invoice_tariff_lines')->where('reading_id', $reading->id)->exists()) {
                throw new \Exception('لا يمكن حذف قراءة مفوترة. الرجاء حذف الفاتورة المرتبطة بها أولاً.');
            }
        });

        static::creating(function (Reading $reading) {
            if ($reading->period_basis !== 'observation') {
                throw ValidationException::withMessages(['period_basis' => 'لا يُسمح بإنشاء قراءة جديدة وفق الفترات التقويمية القديمة.']);
            }
            $meter = $reading->meter()->firstOrFail();
            if (! $reading->reading_date) {
                throw ValidationException::withMessages(['reading_date' => 'يلزم تاريخ تسجيل القراءة.']);
            }
            $reading->actual_reading_date ??= $reading->reading_date;
            $period = app(\App\Services\ReadingService::class)->calculateReadingPeriod(
                $meter->id, $reading->actual_reading_date->toDateString()
            );
            foreach (['period_start', 'period_end'] as $field) {
                if ($reading->{$field} !== null && CarbonImmutable::parse($reading->{$field})->toDateString() !== $period[$field]) {
                    throw ValidationException::withMessages([$field => 'الفترة يجب أن تطابق التاريخ الفعلي للقراءتين.']);
                }
                $reading->{$field} = $period[$field];
            }
            $previous = $meter->getLastReadingValue();
            if ($reading->previous_reading !== null && (float) $reading->previous_reading !== $previous) {
                throw ValidationException::withMessages(['previous_reading' => 'القيمة السابقة لا تطابق آخر قراءة معتمدة.']);
            }
            $reading->previous_reading = $previous;
            app(\App\Services\CurrentMeterReadingGuard::class)->assertCanCreate(
                $meter, $reading->current_reading, $reading->period_start, $reading->period_end,
                $reading->actual_reading_date->toDateString()
            );

            // Calculate consumption units
            $reading->calculateConsumption();
        });

        static::updating(function (Reading $reading) {
            if ($reading->getOriginal('retired_meter_id') || $reading->isDirty(['retired_meter_id', 'retired_meter_code'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(['reading' => 'قراءة مالية تاريخية غير قابلة للتعديل.']);
            }
            $reading->guardObservationUpdate();
            if ($reading->isDirty('consumption_units')) {
                throw ValidationException::withMessages(['consumption_units' => 'الاستهلاك قيمة محسوبة لا تُعدل مباشرة.']);
            }
            if ($reading->isDirty(['previous_reading', 'current_reading'])) {
                $reading->calculateConsumption();
            }
        });
    }
}
