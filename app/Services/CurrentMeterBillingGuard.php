<?php

namespace App\Services;

use App\Models\Meter;
use App\Models\Subscriber;
use Illuminate\Validation\ValidationException;

/**
 * One source of truth for the meter on NEW water-consumption charges.
 *
 * Historical bills, receipts and subscription services remain tied to
 * the original meter when applicable; this guard is NOT applied to payments
 * or corrections to already-issued historic invoices.
 */
final class CurrentMeterBillingGuard
{
    public function resolve(Subscriber $subscriber, ?Meter $meter = null): Meter
    {
        $meter ??= $subscriber->currentMeter()->first();

        if ($meter === null || ! $meter->isCurrent()
            || (int) $meter->subscriber_id !== (int) $subscriber->id) {
            throw ValidationException::withMessages([
                'meter_id' => ['يلزم اختيار العداد الحالي للمشترك لإصدار فاتورة استهلاك جديدة. لا يمكن استخدام عداد منزوع أو تابع لمشترك آخر.'],
            ]);
        }

        return $meter;
    }
}
