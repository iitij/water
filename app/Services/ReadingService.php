<?php

namespace App\Services;

use App\Models\Meter;
use App\Models\Reading;
use App\Support\ReadingQuantity;
use Illuminate\Support\Facades\DB;
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
        $query = Reading::where('meter_id', $meterId)
            ->orderByRaw('COALESCE(actual_reading_date, reading_date) DESC')
            ->orderByDesc('id');
        if (\Illuminate\Support\Facades\DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }
        return $query->first();
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
        if ($lastReading) {
            $this->assertUnambiguousAnchor($meter, $lastReading, $start->toDateString());
        }
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

    /**
     * Do not extend a known broken or ambiguous latest observation. Existing
     * calendar rows remain historical; do not guess or rebase their periods.
     * The full-chain audit remains separate from this immediate-anchor check.
     */
    private function assertUnambiguousAnchor(Meter $meter, Reading $last, string $observed): void
    {
        $this->assertUniqueObservationDate($meter, $last, $observed);
        if ($last->period_basis === 'legacy_calendar') {
            return;
        }
        $previous = Reading::where('meter_id', $meter->id)
            ->whereRaw('DATE(COALESCE(actual_reading_date, reading_date)) < ?', [$observed])
            ->orderByRaw('COALESCE(actual_reading_date, reading_date) DESC')->orderByDesc('id');
        if (DB::transactionLevel() > 0) {
            $previous->lockForUpdate();
        }
        $prior = $previous->first();
        if ($prior) {
            $this->assertUniqueObservationDate(
                $meter, $prior, Carbon::parse($prior->actual_reading_date ?? $prior->reading_date)->toDateString()
            );
        }
        $anchor = $prior
            ? ($prior->actual_reading_date ?? $prior->reading_date)
            : ($meter->installed_at ?? $meter->subscriber?->subscription_start_date);
        $expected = ReadingQuantity::validated(
            $prior ? $prior->getRawOriginal('current_reading') : $meter->getRawOriginal('initial_reading'),
            'previous_reading'
        );
        $raw = $last->getAttributes();
        $from = ReadingQuantity::validated($raw['previous_reading'] ?? null, 'previous_reading');
        $to = ReadingQuantity::validated($raw['current_reading'] ?? null, 'current_reading');
        $units = ReadingQuantity::validated($raw['consumption_units'] ?? null, 'consumption_units');
        if ($last->period_basis !== 'observation' || ! $last->actual_reading_date || ! $anchor
            || ! $last->period_start || ! $last->period_end
            || Carbon::parse($anchor)->toDateString() !== Carbon::parse($last->period_start)->toDateString()
            || Carbon::parse($last->period_end)->toDateString() !== $observed
            || Carbon::parse($last->period_start)->toDateString() >= $observed
            || $expected !== $from || $to < $from || $units !== $to - $from) {
            throw ValidationException::withMessages([
                'period_start' => ['تسلسل القراءة السابقة أو كميتها غير متسق؛ يلزم تدقيق السجل قبل تسجيل قراءة جديدة.'],
            ]);
        }
    }

    /** A physical observation date must identify exactly one active reading. */
    private function assertUniqueObservationDate(Meter $meter, Reading $reading, string $observed): void
    {
        $siblings = Reading::where('meter_id', $meter->id)->whereKeyNot($reading->id)
            ->whereRaw('DATE(COALESCE(actual_reading_date, reading_date)) = ?', [$observed]);
        if (DB::transactionLevel() > 0) {
            $siblings->lockForUpdate();
        }
        if ($siblings->first(['id'])) {
            throw ValidationException::withMessages([
                'period_start' => ['توجد قراءات متعددة في تاريخ الملاحظة السابقة؛ يلزم تدقيق التسلسل.'],
            ]);
        }
    }
}
