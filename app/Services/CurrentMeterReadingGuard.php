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
        mixed $periodEnd,
        mixed $readingDate = null,
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
                'period_start' => ['لا يمكن إصدار قراءة دون تاريخ مرجعي للعداد: تاريخ التركيب أو بداية الخدمة.'],
            ]);
        }

        $start = Carbon::parse($periodStart)->toDateString();
        $end = Carbon::parse($periodEnd)->toDateString();
        if ($end <= $start) {
            throw ValidationException::withMessages([
                'period_end' => ['تاريخ القراءة الحالية يجب أن يكون بعد تاريخ القراءة السابقة.'],
            ]);
        }

        if ($end > Carbon::today()->toDateString()) {
            throw ValidationException::withMessages([
                'reading_date' => ['لا يمكن تسجيل قراءة ميدانية بتاريخ مستقبلي.'],
            ]);
        }

        if ($readingDate !== null && Carbon::parse($readingDate)->toDateString() !== $end) {
            throw ValidationException::withMessages([
                'period_end' => ['نهاية الفترة لا تطابق تاريخ القراءة الفعلية.'],
            ]);
        }

        // Shared measurement boundaries are allowed; true overlap is not.
        if ($meter->readings()->where('period_start', '<', $end)
            ->where('period_end', '>', $start)->exists()) {
            throw ValidationException::withMessages([
                'period_start' => ['تتداخل فترة القراءة الجديدة مع فترة سابقة للعداد.'],
            ]);
        }
    }
}
