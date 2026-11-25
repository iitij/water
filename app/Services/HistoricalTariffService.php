<?php

namespace App\Services;

use App\Support\FinancialAmount;
use App\Support\ExactMoney;

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
        Tariff::lockCatalog();
        $lines = [];
        $total = ExactMoney::fromMinor(0);
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
            try {
                $units = FinancialAmount::parse($reading->consumption_units)->decimal();
                $price = FinancialAmount::parse($tariff->unit_price)->decimal();
                $amount = ExactMoney::tariffLine($units, $price);
                $total = $total->plus($amount);
            } catch (\InvalidArgumentException|\OverflowException) {
                $this->reject($reading, 'وحدات الاستهلاك أو التعرفة أو مجموع الفاتورة خارج النطاق المالي.');
            }
            $lines[] = [
                'reading_id' => $reading->id, 'tariff_id' => $tariff->id,
                'tariff_name' => $tariff->name, 'period_start' => $start, 'period_end' => $end,
                'units' => $units, 'unit_price' => $price,
                'amount' => $amount->decimal(),
            ];
        }
        return ['lines' => $lines, 'total' => $total->decimal()];
    }

    private function reject(Reading $reading, string $message): never
    {
        throw ValidationException::withMessages(['tariff' => "القراءة #{$reading->id}: {$message}"]);
    }
}
