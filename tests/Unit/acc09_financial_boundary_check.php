<?php

declare(strict_types=1);

// Standalone regression: php tests/Unit/acc09_financial_boundary_check.php
require __DIR__.'/../../app/Support/ExactMoney.php';
require __DIR__.'/../../app/Support/FinancialAmount.php';

use App\Support\ExactMoney;
use App\Support\FinancialAmount;

$count = 0;
$equal = static function (mixed $actual, mixed $expected) use (&$count): void {
    if ($actual !== $expected) {
        throw new RuntimeException(var_export([$actual, $expected], true));
    }
    ++$count;
};
$reject = static function (callable $action) use (&$count): void {
    try {
        $action();
    } catch (InvalidArgumentException|OverflowException) {
        ++$count;
        return;
    }
    throw new RuntimeException('Expected rejection.');
};

foreach ([
    ['0.01', 1], [0.01, 1], [0.29, 29], [100, 10000],
    ['001.2000', 120], [' +1.20 ', 120], ['.01', 1],
    ['1e2', 10000], ['1.2300e0', 123], ['123e-2', 123],
    ['1000e-5', 1], ['-0.000', 0], ['-0.01', -1],
    ['9999999999.99', ExactMoney::MAX_MINOR],
    ['-9999999999.99', -ExactMoney::MAX_MINOR],
    [9999999999.99, ExactMoney::MAX_MINOR],
] as [$input, $expected]) {
    $equal(FinancialAmount::parse($input)->minor, $expected);
}
foreach ([true, false, null, [], INF, NAN, -INF, 'NaN', '1.001', '0.009',
    '1.000000000000001', '1e-3', '1e9999', '10000000000.00',
    '-10000000000.00', 0.1 + 0.2, "1\0", '1,00', '١.٢', '1 2'] as $bad) {
    $reject(static fn () => FinancialAmount::parse($bad));
}
$equal(FinancialAmount::add(PHP_INT_MAX - 1, 1), PHP_INT_MAX);
$equal(FinancialAmount::add(-PHP_INT_MAX + 1, -1), -PHP_INT_MAX);
$equal(FinancialAmount::add(PHP_INT_MAX, -PHP_INT_MAX), 0);
$reject(static fn () => FinancialAmount::add(PHP_INT_MAX, 1));
$reject(static fn () => FinancialAmount::add(-PHP_INT_MAX, -1));
$reject(static fn () => FinancialAmount::add(PHP_INT_MIN, 0));
$equal(FinancialAmount::storageDecimal(FinancialAmount::METER_BALANCE_MAX, 'meter_balance', FinancialAmount::METER_BALANCE_MAX), '99999999.99');
$equal(FinancialAmount::storageDecimal(-FinancialAmount::METER_BALANCE_MAX, 'meter_balance', FinancialAmount::METER_BALANCE_MAX), '-99999999.99');
$equal(FinancialAmount::storageDecimal(ExactMoney::MAX_MINOR, 'amount'), '9999999999.99');

// Exact half-cent line rounding, then sum, not rounding aggregate products.
$equal(ExactMoney::tariffLine('0.01', '0.50')->plus(ExactMoney::tariffLine('0.01', '0.50'))->decimal(), '0.02');
$equal(ExactMoney::tariffLine('9999999999.99', '1.00')->minor, ExactMoney::MAX_MINOR);
$reject(static fn () => ExactMoney::tariffLine('9999999999.99', '99999999.99'));
for ($i = 0; $i <= 9999; ++$i) {
    $decimal = ExactMoney::fromMinor($i)->decimal();
    $equal(FinancialAmount::parse($decimal)->minor, $i);
    $equal(FinancialAmount::parse($decimal.'00')->minor, $i);
    $equal(FinancialAmount::parse($i.'e-2')->minor, $i);
}
echo "ACC09_FINANCIAL_BOUNDARY_PASS {$count} checks\n";
