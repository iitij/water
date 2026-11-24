<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

use Illuminate\Database\Eloquent\SoftDeletes;

class Tariff extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'unit_price',
        'is_active',
        'start_date',
        'end_date',
    ];

    protected $attributes = ['is_active' => false];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'is_active' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    /**
     * All catalog mutations and invoice quotes share one database lock.
     * A write to the singleton also obtains a SQLite write reservation.
     * The caller must retain its transaction until the invoice/write commits.
     */
    public static function lockCatalog(): void
    {
        $connection = (new static)->getConnection();
        if ($connection->transactionLevel() === 0) {
            throw new \LogicException('Tariff catalog lock requires a transaction.');
        }
        $connection->table('tariff_catalog_locks')->where('id', 1)->update(['marker' => 0]);
        if (! $connection->table('tariff_catalog_locks')->where('id', 1)->lockForUpdate()->first()) {
            throw new \LogicException('Tariff catalog lock row is missing.');
        }
    }

    private function validateInterval(): void
    {
        $start = $this->start_date?->toDateString();
        $end = $this->end_date?->toDateString();
        if ($start !== null && $end !== null && $start > $end) {
            throw ValidationException::withMessages(['end_date' => 'تاريخ انتهاء التعرفة يجب ألا يسبق بدايتها.']);
        }

        if (! $this->is_active || $this->deleted_at !== null) {
            return;
        }
        $query = static::query()->where('is_active', true);
        if ($this->exists) {
            $query->where('id', '!=', $this->getKey());
        }
        if ($end !== null) {
            $query->where(fn ($q) => $q->whereNull('start_date')->orWhereDate('start_date', '<=', $end));
        }
        if ($start !== null) {
            $query->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $start));
        }
        if ($query->lockForUpdate()->first(['id'])) {
            throw ValidationException::withMessages(['is_active' => 'تتداخل فترة التعرفة مع تعرفة معتمدة أخرى.']);
        }
    }

    public function save(array $options = [])
    {
        return $this->getConnection()->transaction(function () use ($options) {
            static::lockCatalog();
            if ($this->exists) {
                $current = static::withTrashed()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
                foreach (['name', 'unit_price', 'start_date', 'end_date', 'is_active', 'deleted_at'] as $field) {
                    if ($current->getOriginal($field) != $this->getOriginal($field)) {
                        throw ValidationException::withMessages(['tariff' => 'تغيرت التعرفة منذ تحميلها؛ أعد تحميلها قبل التعديل.']);
                    }
                }
                $evidence = $this->getConnection()->table('invoice_tariff_lines')
                    ->where('tariff_id', $this->getKey());
                if ((clone $evidence)->lockForUpdate()->first(['id'])) {
                    if ($this->isDirty(['name', 'unit_price', 'start_date', 'is_active', 'deleted_at'])) {
                        throw ValidationException::withMessages(['tariff' => 'لا يمكن تعديل تعرفة مرتبطة بفواتير تاريخية.']);
                    }

                    if ($this->isDirty('end_date') && $this->end_date) {
                        $lastCoveredDay = CarbonImmutable::parse($this->end_date)->addDay()->toDateString();
                        if ((clone $evidence)->where('period_end', '>', $lastCoveredDay)->lockForUpdate()->first(['id'])) {
                            throw ValidationException::withMessages(['end_date' => 'تاريخ الانتهاء لا يغطي فترات الاستهلاك المفوترة.']);
                        }
                    }
                }
            }
            $this->validateInterval();

            return parent::save($options);
        });
    }

    public function delete()
    {
        if (! $this->exists) {
            return parent::delete();
        }

        return $this->getConnection()->transaction(function () {
            static::lockCatalog();
            static::withTrashed()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            if ($this->getConnection()->table('invoice_tariff_lines')->where('tariff_id', $this->getKey())->lockForUpdate()->first(['id'])) {
                throw ValidationException::withMessages(['tariff' => 'لا يمكن حذف تعرفة مرتبطة بفواتير تاريخية.']);
            }

            return parent::delete();
        });
    }

    public static function getActivePrice(): float
    {
        $activeTariff = self::where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('start_date')
                      ->orWhere('start_date', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('end_date')
                      ->orWhere('end_date', '>=', now());
            })
            ->orderByDesc('id')
            ->first();

        return $activeTariff ? (float) $activeTariff->unit_price : 600; // Default fallback to 600
    }
}
