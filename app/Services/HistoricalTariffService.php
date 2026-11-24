<?php

namespace App\Services;

use App\Models\Reading;
use App\Models\Tariff;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class HistoricalTariffService
{
    /**
     * A reading covers [period_start, period_end), so a change effective on
     * the next reading's date applies to the following period. Tariff end
     * dates are inclusive. Never estimate the distribution across prices.
     */
    public function quote(Collection $readings): array
    {
        $lines = [];
        $total = 0;
        foreach ($readings as $reading) {
            if (! $reading->period_start || ! $reading->period_end) {
                $this->reject($reading, 'الفترة غير مكتملة.');
            }
            $start = CarbonImmutable::parse($reading->period_start)->toDateString();
            $end = CarbonImmutable::parse($reading->period_end)->toDateString();
            if ($start >= $end) {
                $this->reject($reading, 'نهاية الفترة يجب أن تكون بعد بدايتها.');
            }
            $lastDay = CarbonImmutable::parse($end)->subDay()->toDateString();
            $tariffs = Tariff::query()->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('start_date')->orWhereDate('start_date', '<=', $lastDay))
                ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $start))
                ->orderBy('id')->lockForUpdate()->get();
            if ($tariffs->count() !== 1) {
                $this->reject($reading, $tariffs->isEmpty()
                    ? 'لا توجد تعرفة معتمدة للفترة.'
                    : 'توجد تعرفات متداخلة أو تغير سعر داخل الفترة؛ يلزم فصل الاستهلاك بقراءة موثقة.');
            }
            $tariff = $tariffs->first();
            if (($tariff->start_date && $tariff->start_date->toDateString() > $start)
                || ($tariff->end_date && $tariff->end_date->toDateString() < $lastDay)) {
                $this->reject($reading, 'التعرفة لا تغطي كامل الفترة.');
            }
            $units = $this->minor($reading->consumption_units);
            $price = $this->minor($tariff->unit_price);
            if ($units < 0 || $price < 0 || ($price > 0 && $units > intdiv(PHP_INT_MAX - 50, $price))) {
                $this->reject($reading, 'وحدات الاستهلاك أو التعرفة خارج النطاق المالي.');
            }
            $amount = intdiv($units * $price + 50, 100);
            if ($amount > 999999999999 || $total > 999999999999 - $amount) {
                $this->reject($reading, 'القيمة تتجاوز الحد المالي للفاتورة.');
            }
            $total += $amount;
            $lines[] = [
                'reading_id' => $reading->id, 'tariff_id' => $tariff->id,
                'tariff_name' => $tariff->name, 'period_start' => $start, 'period_end' => $end,
                'units' => $this->decimal($units), 'unit_price' => $this->decimal($price),
                'amount' => $this->decimal($amount),
            ];
        }
        return ['lines' => $lines, 'total' => $this->decimal($total)];
    }

    private function minor($value): int
    {
        // Input fields are DECIMAL; no floating-point multiplication.
        $value = (string) $value;
        if (! preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/D', $value, $parts)) {
            throw ValidationException::withMessages(['tariff' => 'قيمة عشرية غير صالحة للتسعير.']);
        }
        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    private function decimal(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    private function reject(Reading $reading, string $message): never
    {
        throw ValidationException::withMessages(['tariff' => "القراءة #{$reading->id}: {$message}"]);
    }
}
