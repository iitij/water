<?php

namespace App\Services;

use App\Models\Meter;
use App\Models\Reading;
use App\Models\Setting;
use Carbon\Carbon;

/**
 * Water consumption is measured between consecutive observations:
 * (previous observation date, current observation date].
 * Scheduled field visits are not financial-period boundaries.
 */
class ReadingService
{
    public function latestReading(int $meterId): ?Reading
    {
        return Reading::query()->where('meter_id', $meterId)
            ->orderByRaw('COALESCE(actual_reading_date, reading_date) DESC')
            ->orderByDesc('id')->first();
    }

    public function calculateReadingPeriod(int $meterId, mixed $currentReadingDate = null): array
    {
        $meter = Meter::with('subscriber')->find($meterId);
        if (! $meter) {
            return [
                'period_start' => null,
                'period_end' => null,
                'previous_reading_date' => null,
                'expected_reading_date' => null,
            ];
        }

        $previous = $this->latestReading($meterId);
        $previousDate = $previous
            ? ($previous->actual_reading_date ?? $previous->reading_date)
            : ($meter->installed_at ?? $meter->subscriber?->subscription_start_date);
        $start = $previousDate ? Carbon::parse($previousDate)->startOfDay() : null;
        $current = $currentReadingDate
            ? Carbon::parse($currentReadingDate)->startOfDay() : Carbon::today();

        $expected = $start
            ? $this->nextScheduledVisit(
                $start, (string) Setting::get('reading_period', 'monthly'),
                (string) Setting::get('reading_day', 'last_day')
            )
            : null;

        return [
            'period_start' => $start?->toDateString(),
            'period_end' => $current->toDateString(),
            'previous_reading_date' => $start?->toDateString(),
            'expected_reading_date' => $expected?->toDateString(),
        ];
    }

    private function nextScheduledVisit(Carbon $start, string $period, string $readingDay): Carbon
    {
        $date = $start->copy();
        if ($period === 'monthly') {
            $target = $readingDay === 'last_day'
                ? $date->daysInMonth : max(1, min(31, (int) $readingDay ?: 28));
            $candidate = $date->copy()->day(min($target, $date->daysInMonth));
            if ($candidate->greaterThan($date)) {
                return $candidate;
            }
            $next = $date->copy()->startOfMonth()->addMonth();
            return $readingDay === 'last_day'
                ? $next->endOfMonth()
                : $next->day(min($target, $next->daysInMonth));
        }
        return match ($period) {
            'weekly' => $date->addDays(7),
            'ten_days' => $date->addDays(10),
            'half_monthly' => $date->addDays(15),
            default => $date->addMonthNoOverflow(),
        };
    }
}
