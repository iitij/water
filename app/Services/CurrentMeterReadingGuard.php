<?php

namespace App\Services;

use App\Models\Meter;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Unified validation for NEW readings from API, sync and Filament.
 * Corrections to an already recorded historical reading are a separate flow.
 */
final class CurrentMeterReadingGuard
{
    public function assertCanCreate(
        Meter $meter,
        mixed $currentReading,
        mixed $periodStart,
        mixed $periodEnd
    ): void {
        if (! $meter->isCurrent()) {
            throw ValidationException::withMessages([
                'meter_id' => ['لا يمكن تسجيل قراءة جديدة على عداد منزوع أو مستبدل.'],
            ]);
        }

        $previous = (float) $meter->getLastReadingValue();
        if ((float) $currentReading < $previous) {
            throw ValidationException::withMessages([
                'current_reading' => ['القراءة الحالية يجب أن تكون أكبر من أو تساوي السابقة ('.$previous.').'],
            ]);
        }

        if (! $periodStart || ! $periodEnd) {
            throw ValidationException::withMessages([
                'period_start' => ['يجب تحديد فترة قراءة صحيحة.'],
            ]);
        }

        $start = Carbon::parse($periodStart)->toDateString();
        $end = Carbon::parse($periodEnd)->toDateString();
        if ($end < $start) {
            throw ValidationException::withMessages([
                'period_end' => ['نهاية فترة القراءة يجب ألا تسبق بدايتها.'],
            ]);
        }

        if ($meter->readings()->where('period_start', '<=', $end)
            ->where('period_end', '>=', $start)->exists()) {
            throw ValidationException::withMessages([
                'period_start' => ['تتداخل فترة القراءة الجديدة مع فترة سابقة للعداد.'],
            ]);
        }
    }
}
