<?php

declare(strict_types=1);

require __DIR__.'/../../app/Support/ExactMoney.php';
require __DIR__.'/../../app/Support/FinancialAmount.php';
require __DIR__.'/../../app/Support/InvoiceAmounts.php';

use App\Support\ExactMoney;
use App\Support\FinancialAmount;
use App\Support\InvoiceAmounts;

$count = 0;
$equal = static function (mixed $actual, mixed $expected) use (&$count): void {
    if ($actual !== $expected) {
        throw new RuntimeException(var_export([$actual, $expected], true));
    }
    ++$count;
};
$reject = static function (callable $action, string $exception) use (&$count): void {
    try {
        $action();
    } catch (Throwable $error) {
        if (! $error instanceof $exception) {
            throw $error;
        }
        ++$count;
        return;
    }
    throw new RuntimeException('Expected rejection: '.$exception);
};

foreach ([
    ['0.30', '0.29', 1], ['0.29', '0.30', 0], ['0.00', '0.00', 0],
    ['9999999999.99', '9999999999.98', 1],
    [0.30, 0.29, 1], ['0.30', '0.10', 20],
] as [$total, $paid, $remaining]) {
    $equal(InvoiceAmounts::remainingMinor($total, $paid), $remaining);
}
$reject(static fn () => InvoiceAmounts::remainingMinor('-0.01', '0.00'), LogicException::class);
$reject(static fn () => InvoiceAmounts::remainingMinor('1.00', '-0.01'), LogicException::class);
$reject(static fn () => InvoiceAmounts::remainingMinor('1.001', '0'), InvalidArgumentException::class);
$reject(static fn () => InvoiceAmounts::remainingMinor('10000000000.00', '0'), OverflowException::class);
$equal(InvoiceAmounts::outstandingMinor([]), 0);
$equal(InvoiceAmounts::outstandingMinor([(object) ['total_amount' => '0.30', 'amount_paid' => '0.29'],
    (object) ['total_amount' => '0.30', 'amount_paid' => '0.10']]), 21);
$equal(InvoiceAmounts::outstandingMinor([(object) ['total_amount' => '9999999999.99', 'amount_paid' => '0'],
    (object) ['total_amount' => '9999999999.99', 'amount_paid' => '0']]), 1999999999998);
for ($i = 0; $i <= 1000; ++$i) {
    $total = ExactMoney::fromMinor(ExactMoney::MAX_MINOR - $i)->decimal();
    $paid = ExactMoney::fromMinor(ExactMoney::MAX_MINOR - $i - 1)->decimal();
    $equal(InvoiceAmounts::remainingMinor($total, $paid), 1);
    $equal(InvoiceAmounts::remainingMinor($paid, $total), 0);
}
$equal(FinancialAmount::add(9007199254740993, -1), 9007199254740992);
$equal(FinancialAmount::add(-9007199254740993, 1), -9007199254740992);
$reject(static fn () => FinancialAmount::add(PHP_INT_MAX, 1), OverflowException::class);

echo "ACC09_P1_INVOICE_AMOUNTS_PASS {$count} checks\n";
