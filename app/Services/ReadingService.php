<?php

namespace App\Services;

use App\Models\Meter;
use App\Models\Reading;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The consumption interval is the time between two observed meter values.
 * Visit schedules are not billing-period boundaries.
 *
 * All historical calendar-based periods are retained unchanged; new readings
 * receive period_basis='observation' and use a real observation date.
 */
class ReadingService
{
    public function latestReading(int $meterId): ?Reading
    {
        return Reading::where('meter_id', $meterId)
            ->orderByRaw('COALESCE(actual_reading_date, reading_date) DESC')
            ->orderByDesc('id')->first();
    }

    public function calculateReadingPeriod(int $meterId, ?string $readingDate = null): array
    {
        $meter = Meter::with('subscriber')->find($meterId);
        if (! $meter) {
            return ['period_start' => null, 'period_end' => null];
        }

        $lastReading = $this->latestReading($meterId);
        $baseline = $lastReading
            ? ($lastReading->actual_reading_date ?? $lastReading->reading_date)
            : ($meter->installed_at ?? $meter->subscriber?->subscription_start_date);

        if (! $baseline) {
            throw ValidationException::withMessages([
                'period_start' => ['يلزم تاريخ تركيب العداد أو بداية خدمة المشترك لتحديد الفترة الأولى.'],
            ]);
        }

        $start = Carbon::parse($baseline)->startOfDay();
        $end = Carbon::parse($readingDate ?? today()->toDateString())->startOfDay();

        if ($end->gt(today()) || $end->lessThanOrEqualTo($start)) {
            throw ValidationException::withMessages([
                'reading_date' => ['يجب أن يكون تاريخ القراءة الجديدة لاحقاً لتاريخ القراءة السابقة أو التركيب وألا يقع في المستقبل.'],
            ]);
        }

        return [
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
        ];
    }
}
