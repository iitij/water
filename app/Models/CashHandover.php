<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class CashHandover extends Model
{
    protected $fillable = ['cash_session_id', 'received_by', 'amount', 'reference', 'received_on', 'evidence'];

    protected $casts = ['amount' => 'decimal:2', 'received_on' => 'date'];

    protected static function booted(): void
    {
        $reject = static function (): void {
            throw ValidationException::withMessages(['handover' => 'مستند التسليم المثبت غير قابل للتعديل أو الحذف.']);
        };
        static::updating($reject);
        static::deleting($reject);
    }
}
