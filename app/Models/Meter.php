<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

use Illuminate\Database\Eloquent\SoftDeletes;

class Meter extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'unique_code',
        'path_number',
        'subscriber_id',
        'status',
        'balance',
        'installed_at',
        'removed_at',
        'initial_reading',
    ];

    protected $attributes = ['initial_reading' => '0.00'];

    protected $casts = [
        'installed_at' => 'date',
        'removed_at' => 'date',
        'initial_reading' => 'decimal:2',
        'balance' => 'decimal:2',
        'path_number' => 'integer',
    ];

    public function save(array $options = [])
    {
        if ((! $this->exists || $this->isDirty('initial_reading'))
            && ! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', (string) $this->getAttributes()['initial_reading'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['initial_reading' => 'القراءة الابتدائية يجب أن تكون غير سالبة وبمنزلتين عشريتين كحد أقصى.']);
        }
        if (! $this->exists || ! $this->isDirty(['installed_at', 'initial_reading'])) {
            return parent::save($options);
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($options) {
            $fresh = self::query()->lockForUpdate()->findOrFail($this->id);
            if ($fresh->getOriginal('installed_at') != $this->getOriginal('installed_at')
                || $fresh->getOriginal('initial_reading') != $this->getOriginal('initial_reading')) {
                throw \Illuminate\Validation\ValidationException::withMessages(['installed_at' => 'تغيرت بيانات العداد؛ حدّث البيانات أولاً.']);
            }
            if ($fresh->readings()->withTrashed()->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'installed_at' => 'لا يمكن تغيير تاريخ تركيب العداد أو قراءته الابتدائية بعد تسجيل القراءات.',
                ]);
            }

            return parent::save($options);
        }, 3);
    }

    /** Serialize ordinary Eloquent deletion with reading and replacement writers. */
    public function delete()
    {
        if (! $this->exists) {
            return parent::delete();
        }
        return \Illuminate\Support\Facades\DB::transaction(function () {
            Subscriber::query()->lockForUpdate()->findOrFail($this->subscriber_id);
            $locked = self::withTrashed()->lockForUpdate()->findOrFail($this->getKey());
            if ((int) $locked->subscriber_id !== (int) $this->subscriber_id) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'meter_id' => 'تغير مالك العداد؛ حدّث البيانات.',
                ]);
            }

            return parent::delete();
        }, 3);
    }

    protected static function booted(): void
    {
        static::deleting(function (Meter $meter): void {
            if (app(\App\Services\MeterDataPurger::class)->hasOrdinaryHistoryLocked((int) $meter->id)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'meter_id' => 'لا يجوز حذف عداد له قراءات أو سجل مالي أو مراجعة قبض معلقة مباشرةً.',
                ]);
            }
        });
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function readings(): HasMany
    {
        return $this->hasMany(Reading::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * آخر قراءة للعداد
     */
    public function isCurrent(): bool
    {
        return $this->removed_at === null && $this->deleted_at === null;
    }

    public function getLatestReading(?int $ignoreReadingId = null): ?Reading
    {
        $query = $this->readings()->orderByRaw('COALESCE(actual_reading_date, reading_date) DESC')->orderByDesc('id');
        if ($ignoreReadingId) {
            $query->where('id', '!=', $ignoreReadingId);
        }
        if (\Illuminate\Support\Facades\DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }
        return $query->first();
    }

    /**
     * آخر قيمة قراءة (لاستخدامها كقراءة سابقة)
     */
    public function getLastReadingValue(?int $ignoreReadingId = null): float
    {
        $latest = $this->getLatestReading($ignoreReadingId);
        return $latest ? (float) $latest->current_reading : (float) ($this->initial_reading ?? 0);
    }

    /**
     * حساب الرصيد المدين للعداد (إذا كان الرصيد بالسالب فهو دين)
     */
    public function getOutstandingBalance(): float
    {
        return max(0, -1 * (float) $this->balance);
    }
}
