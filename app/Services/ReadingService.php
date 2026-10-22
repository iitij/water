<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Reading;
use App\Models\Setting;
use App\Models\Meter;

class ReadingService
{
    public function calculateReadingPeriod(int $meterId): array
    {
        $meter = Meter::with('subscriber')->find($meterId);
        if (!$meter) {
            return ['period_start' => null, 'period_end' => null];
        }

        $lastReading = Reading::where('meter_id', $meterId)->orderBy('id', 'desc')->first();

        $readingPeriod = Setting::get('reading_period', 'monthly');
        $readingDay = Setting::get('reading_day', 'last_day');

        if ($lastReading) {
            // إذا كانت القراءة القديمة ليس لها period_end (نظام قديم) نعتمد على reading_date
            $lastPeriodEnd = $lastReading->period_end ? Carbon::parse($lastReading->period_end) : Carbon::parse($lastReading->reading_date)->endOfMonth();
            $periodStart = $lastPeriodEnd->addDay();
        } else {
            $subStart = $meter->subscriber->subscription_start_date ? Carbon::parse($meter->subscriber->subscription_start_date) : now()->startOfMonth();
            $periodStart = $subStart;
        }

        $periodEnd = $this->calculateNextPeriodEnd($periodStart, $readingPeriod, $readingDay);

        return [
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
        ];
    }

    private function calculateNextPeriodEnd(Carbon $start, string $period, string $readingDay): Carbon
    {
        $startCopy = $start->copy();
        $day = $startCopy->day;

        if ($period === 'monthly') {
            if ($readingDay === 'last_day') {
                return $startCopy->endOfMonth();
            } else {
                $targetDay = (int) $readingDay;
                if ($day <= $targetDay) {
                    return $startCopy->setDay($targetDay);
                } else {
                    return $startCopy->addMonth()->setDay($targetDay);
                }
            }
        } elseif ($period === 'half_monthly') {
            if ($day <= 15) {
                return $startCopy->setDay(15);
            } else {
                return $startCopy->endOfMonth();
            }
        } elseif ($period === 'ten_days') {
            if ($day <= 10) return $startCopy->setDay(10);
            if ($day <= 20) return $startCopy->setDay(20);
            return $startCopy->endOfMonth();
        } elseif ($period === 'weekly') {
            return $startCopy->addDays(6);
        }

        return $startCopy->endOfMonth();
    }
}
