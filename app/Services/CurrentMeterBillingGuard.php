<?php

namespace App\Services;

use App\Models\Meter;
use App\Models\Subscriber;
use Illuminate\Validation\ValidationException;

/**
 * One source of truth for the meter on NEW water-consumption charges.
 *
 * Historical water bills and receipts retain their original accounting links.
 * This guard does not affect payments or corrections to already-issued invoices.
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
