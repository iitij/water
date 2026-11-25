<?php

declare(strict_types=1);

require __DIR__.'/../../app/Support/ReadingQuantity.php';
require __DIR__.'/../../app/Support/ObservationBillingChain.php';

use App\Support\ObservationBillingChain as Chain;

function row(int $id, string $start, string $end, string $from, string $to, string $units, array $overrides = []): array
{
    return $overrides + ['id' => $id, 'period_basis' => 'observation', 'actual_reading_date' => $end,
        'reading_date' => $end, 'period_start' => $start, 'period_end' => $end,
        'previous_reading' => $from, 'current_reading' => $to, 'consumption_units' => $units,
        'is_invoiced' => 0, 'invoice_id' => null, 'retired_meter_id' => null];
}

$a = row(1, '2026-01-01', '2026-02-01', '100.00', '120.00', '20.00');
$b = row(2, '2026-02-01', '2026-03-01', '120.00', '130.00', '10.00');
$posted = array_replace($a, ['is_invoiced' => 1, 'invoice_id' => 99]);
$legacy = array_replace($posted, ['period_basis' => 'legacy_calendar', 'period_end' => '2026-01-31']);
$legacyPending = array_replace($legacy, ['is_invoiced' => 0, 'invoice_id' => null]);
$checks = 0;
$run = static function (array $rows, array $ids, ?string $reason = null, mixed $date = '2026-01-01', mixed $initial = '100.00') use (&$checks): void {
    try {
        Chain::assertValid($rows, $ids, $date, $initial);
    } catch (InvalidArgumentException $e) {
        if ($reason === null || ! str_contains($e->getMessage(), $reason)) {
            throw new RuntimeException('Unexpected rejection: '.$e->getMessage(), 0, $e);
        }
        ++$checks;
        return;
    }
    if ($reason !== null) {
        throw new RuntimeException('Expected rejection: '.$reason);
    }
    ++$checks;
};
$run([$a], [1]);
$run([$b, $a], [1, 2]);
$run([$posted, $b], [2]);
$run([$legacy, $b], [2]);
$run([$legacyPending], [1]);
$run([$legacyPending, $b], [1, 2], 'مختلطة');
$run([$a, array_replace($a, ['id' => 2, 'reading_date' => '2026-02-02'])], [1, 2], 'تكرار');
$run([$a, array_replace($b, ['period_start' => '2026-01-20', 'previous_reading' => '110.00', 'consumption_units' => '20.00'])], [1, 2], 'تداخل');
$run([$posted, array_replace($b, ['previous_reading' => '115.00', 'consumption_units' => '15.00'])], [2], 'القراءة السابقة');
$run([$a, array_replace($b, ['period_start' => '2026-02-02'])], [1, 2], 'انقطاع');
$run([$a, array_replace($b, ['consumption_units' => '9.99'])], [1, 2], 'انقطاع');
$run([$a, array_replace($b, ['actual_reading_date' => null])], [1, 2], 'ارتباطها المالي');
$run([$a, array_replace($b, ['invoice_id' => 99])], [1, 2], 'ارتباطها المالي');
$run([$a, array_replace($b, ['retired_meter_id' => 77])], [1, 2], 'ارتباطها المالي');
$run([$a, array_replace($b, ['period_end' => '2026-02-28'])], [1, 2], 'الفترة');
$run([$a, array_replace($b, ['is_invoiced' => 1])], [1, 2], 'ارتباطها المالي');
$run([$a, array_replace($b, ['is_invoiced' => 1, 'invoice_id' => 99])], [1], 'غير محددة');
$run([$a], [2], 'تغيرت');
$run([$a], [1], 'تاريخ', '2026-02-30');
$run([$a], [1], 'انقطاع', '2026-01-01', '99.99');
$run([$a], [1], null, '2026-01-01 00:00:00', 100);
$run([$a, array_replace($b, ['current_reading' => '130.001'])], [1, 2], 'non-negative');
$run([$posted, array_replace($posted, ['id' => 3]), $b], [2], 'تكرار');
$run([array_replace($posted, ['invoice_id' => null]), $b], [2], 'أساس');
$run([row(1, '2026-01-01', '2026-02-01', '9999999999.98', '9999999999.99', '0.01')], [1], null, '2026-01-01', '9999999999.98');
echo "G07_OBSERVATION_CHAIN_PASS {$checks} checks\n";
