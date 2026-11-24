<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PaymentAccountTransfer extends Model
{
    protected $fillable = ['payment_id', 'from_subscriber_id', 'to_subscriber_id', 'approved_by', 'amount', 'reason', 'evidence'];
    protected $casts = ['amount' => 'decimal:2'];

    protected static function booted(): void
    {
        $reject = static function (): void {
            throw \Illuminate\Validation\ValidationException::withMessages(['transfer' => 'مستند التحويل المالي غير قابل للتعديل أو الحذف.']);
        };
        static::updating($reject);
        static::deleting($reject);
    }
}
