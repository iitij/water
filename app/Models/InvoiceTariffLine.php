<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Read-only representation of the evidence written by the posting transaction. */
final class InvoiceTariffLine extends Model
{
    public $timestamps = false;
    protected $guarded = ['*'];
    protected $casts = ['units' => 'decimal:2', 'unit_price' => 'decimal:2', 'amount' => 'decimal:2'];

    protected static function booted(): void
    {
        $reject = static function (): void {
            throw ValidationException::withMessages(['tariff_lines' => 'أدلة التسعير التاريخية غير قابلة للتعديل أو الحذف.']);
        };
        static::creating($reject);
        static::updating($reject);
        static::deleting($reject);
    }
}
