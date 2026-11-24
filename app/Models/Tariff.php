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

    protected $casts = [
        'unit_price' => 'decimal:2',
        'is_active' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    /**
     * Protect historical pricing evidence, including soft deletion. The row
     * lock is shared with the invoice pricing query and held until the write.
     */
    public function save(array $options = [])
    {
        if (! $this->exists) {
            return parent::save($options);
        }

        return $this->getConnection()->transaction(function () use ($options) {
            static::withTrashed()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
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

            return parent::save($options);
        });
    }

    public function delete()
    {
        if (! $this->exists) {
            return parent::delete();
        }

        return $this->getConnection()->transaction(function () {
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
