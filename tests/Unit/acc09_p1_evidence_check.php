<?php

declare(strict_types=1);

require __DIR__.'/../../app/Support/ExactMoney.php';
require __DIR__.'/../../app/Support/FinancialAmount.php';
require __DIR__.'/../../app/Support/HistoricalTariffEvidence.php';
require __DIR__.'/../../app/Support/ReceiptPostingIdentity.php';

use App\Support\HistoricalTariffEvidence;
use App\Support\ReceiptPostingIdentity;

$count = 0;
$check = static function (bool $condition) use (&$count): void {
    if (! $condition) {
        throw new RuntimeException('Evidence check failed at '.($count + 1));
    }
    ++$count;
};
$reject = static function (callable $callback) use ($check): void {
    try {
        $callback();
    } catch (DomainException|InvalidArgumentException|OverflowException) {
        $check(true);
        return;
    }
    throw new RuntimeException('Invalid evidence accepted.');
};
$r1 = (object) ['id' => 1, 'period_start' => '2026-01-01', 'period_end' => '2026-02-01', 'consumption_units' => '0.01'];
$r2 = (object) ['id' => 2, 'period_start' => '2026-02-01', 'period_end' => '2026-03-01', 'consumption_units' => '0.01'];
$l1 = (object) ['reading_id' => 1, 'period_start' => $r1->period_start, 'period_end' => $r1->period_end,
    'units' => '0.01', 'unit_price' => '0.50', 'amount' => '0.01'];
$l2 = (object) ['reading_id' => 2, 'period_start' => $r2->period_start, 'period_end' => $r2->period_end,
    'units' => '0.01', 'unit_price' => '1.50', 'amount' => '0.02'];
$with = static fn (object $row, array $changes): object => (object) array_replace((array) $row, $changes);
HistoricalTariffEvidence::verify([$r1, $r2], [$l2, $l1], '0.03');
$check(true);
$reject(fn () => HistoricalTariffEvidence::verify([$r1, $r2], [], '0.03'));
$reject(fn () => HistoricalTariffEvidence::verify([$r1, $r2], [$l1], '0.01'));
$reject(fn () => HistoricalTariffEvidence::verify([$r1, $r2], [$l1, $l1], '0.02'));
$reject(fn () => HistoricalTariffEvidence::verify([$r1, $r2], [$l1, $l2], '0.04'));
$reject(fn () => HistoricalTariffEvidence::verify([$r1, $r1], [$l1], '0.01'));
foreach ([
    ['reading_id' => 9], ['period_start' => '2026-01-02'], ['period_end' => '2026-02-02'],
    ['units' => '0.02'], ['units' => '0.011'], ['unit_price' => '-0.50'], ['unit_price' => '100000000.00'],
    ['amount' => '0.00'], ['amount' => '0.02'], ['amount' => '-0.01'], ['amount' => '0.001'],
] as $changes) {
    $reject(fn () => HistoricalTariffEvidence::verify([$r1, $r2], [$with($l1, $changes), $l2], '0.03'));
}
$reject(fn () => HistoricalTariffEvidence::verify([$with($r1, ['period_end' => $r1->period_start])], [$l1], '0.01'));
$reject(fn () => HistoricalTariffEvidence::verify([$r1], [], '0.00'));
$receipt = (object) ['subscriber_id' => 1, 'meter_id' => null, 'retired_meter_id' => 17, 'amount' => '0.03'];
$check(ReceiptPostingIdentity::matches($receipt, $with($receipt, ['subscriber_id' => '1', 'retired_meter_id' => '17', 'amount' => 0.03])));
foreach ([
    ['subscriber_id' => 2], ['meter_id' => 17], ['retired_meter_id' => null], ['retired_meter_id' => 18],
    ['amount' => '0.02'], ['amount' => '0.031'], ['amount' => '10000000000.00'], ['amount' => 'bad'],
    ['amount' => null],
] as $changes) {
    $check(! ReceiptPostingIdentity::matches($receipt, $with($receipt, $changes)));
}
$active = $with($receipt, ['meter_id' => 7, 'retired_meter_id' => null]);
$check(ReceiptPostingIdentity::matches($active, clone $active));
$check(! ReceiptPostingIdentity::matches($active, $receipt));
$check(! ReceiptPostingIdentity::matches($with($receipt, ['amount' => '0.00']), $with($receipt, ['amount' => '0.00'])));
echo "ACC09_P1_EVIDENCE_PASS {$count} checks\n";
