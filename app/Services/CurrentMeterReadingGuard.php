<?php

namespace App\Services;

use App\Models\Meter;
use App\Support\ReadingQuantity;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * A new consumption interval closes when the next meter observation is taken.
 * Legacy calendar periods remain immutable and are intentionally not rebased.
 */
final class CurrentMeterReadingGuard
{
    public function assertCanCreate(
        Meter $meter,
        mixed $currentReading,
        mixed $periodStart,
        mixed $periodEnd,
        ?string $observationDate = null
    ): void {
        if (! $meter->isCurrent()) {
            throw ValidationException::withMessages([
                'meter_id' => ['لا يمكن تسجيل قراءة جديدة على عداد منزوع أو مستبدل.'],
            ]);
        }

        $last = $meter->getLatestReading();
        $previous = ReadingQuantity::validated(
            $last ? $last->getRawOriginal('current_reading') : $meter->getRawOriginal('initial_reading'),
            'previous_reading'
        );
        $current = ReadingQuantity::validated($currentReading, 'current_reading');
        if ($current < $previous) {
            throw ValidationException::withMessages([
                'current_reading' => ['القراءة الحالية يجب أن تكون أكبر من أو تساوي السابقة ('.ReadingQuantity::decimal($previous).').'],
            ]);
        }

        if (! $periodStart || ! $periodEnd) {
            throw ValidationException::withMessages([
                'period_start' => ['يجب تحديد تاريخَي القراءتين لتكوين الفترة.'],
            ]);
        }

        $start = Carbon::parse($periodStart)->toDateString();
        $end = Carbon::parse($periodEnd)->toDateString();
        if ($end <= $start) {
            throw ValidationException::withMessages([
                'period_end' => ['يجب أن يكون تاريخ القراءة الحالية لاحقاً لتاريخ القراءة السابقة.'],
            ]);
        }

        $observation = Carbon::parse($observationDate ?? $end)->toDateString();
        if ($observation !== $end) {
            throw ValidationException::withMessages([
                'period_end' => ['نهاية الفترة يجب أن تطابق تاريخ أخذ القراءة الحالية.'],
            ]);
        }
        if ($end > today()->toDateString()) {
            throw ValidationException::withMessages([
                'reading_date' => ['لا يمكن اعتماد قراءة مؤرخة في المستقبل.'],
            ]);
        }

        $expected = app(ReadingService::class)->calculateReadingPeriod($meter->id, $end);
        if ($start !== $expected['period_start'] || $end !== $expected['period_end']) {
            throw ValidationException::withMessages([
                'period_start' => ['فترة الاستهلاك يجب أن تبدأ من تاريخ القراءة السابقة وتنتهي بتاريخ القراءة الحالية.'],
            ]);
        }

        // Endpoints are shared: the previous observation closes one interval
        // and opens the next. Only new observation-basis periods participate
        // in this strict-overlap check; legacy calendar records are immutable.
        if ($meter->readings()->where('period_basis', 'observation')
            ->where('period_start', '<', $end)
            ->where('period_end', '>', $start)->exists()) {
            throw ValidationException::withMessages([
                'period_start' => ['تتداخل الفترة الجديدة مع فترة قراءة أخرى للعداد.'],
            ]);
        }
    }
}
