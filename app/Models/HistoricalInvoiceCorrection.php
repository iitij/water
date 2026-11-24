<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class HistoricalInvoiceCorrection extends Model
{
    protected $fillable = ['original_invoice_id', 'replacement_invoice_id', 'approved_by',
        'corrected_units', 'historical_unit_price', 'corrected_amount', 'reason', 'evidence'];
    protected $casts = ['corrected_units' => 'decimal:2', 'historical_unit_price' => 'decimal:2', 'corrected_amount' => 'decimal:2'];

    protected static function booted(): void
    {
        $reject = static function (): void {
            throw \Illuminate\Validation\ValidationException::withMessages(['correction' => 'مستند التصحيح التاريخي غير قابل للتعديل أو الحذف.']);
        };
        static::updating($reject);
        static::deleting($reject);
    }
}
