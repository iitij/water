<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
