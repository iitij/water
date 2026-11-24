<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PaymentRefund extends Model
{
    protected $fillable = ['payment_id', 'subscriber_id', 'recorded_by', 'amount',
        'disbursement_reference', 'method', 'disbursed_on', 'reason', 'evidence'];
    protected $casts = ['amount' => 'decimal:2', 'disbursed_on' => 'date'];

    protected static function booted(): void
    {
        $reject = static function (): void {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'refund' => 'مستند الاسترداد المالي غير قابل للتعديل أو الحذف.',
            ]);
        };
        static::updating($reject);
        static::deleting($reject);
    }
}
