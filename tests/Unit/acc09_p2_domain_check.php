<?php

// Intentionally weak caller: mixed parameters must reject implicit coercion.
require __DIR__.'/../../app/Support/FinancialAmount.php';
require __DIR__.'/../../app/Support/SignedMinor.php';
require __DIR__.'/../../app/Support/CashMoney.php';

use App\Support\CashMoney;
use App\Support\SignedMinor;

$count = 0;
$equal = static function ($actual, $expected) use (&$count): void {
    if ($actual !== $expected) {
        throw new RuntimeException('Check '.($count + 1).': '.var_export([$actual, $expected], true));
    }
    ++$count;
};
$reject = static function (callable $action, string $expected) use (&$count): void {
    try {
        $action();
    } catch (Throwable $error) {
        if (get_class($error) !== $expected) throw $error;
        ++$count;
        return;
    }
    throw new RuntimeException('Expected '.$expected.' at check '.($count + 1));
};
$bad = InvalidArgumentException::class;
$overflow = OverflowException::class;
$max = PHP_INT_MAX;
$cash = CashMoney::MAX_MINOR;
$equal(PHP_INT_SIZE, 8);
$equal($cash, 99999999999999);
foreach ([['0', 0], ['1', 1], ['-1', -1], ['9007199254740993', 9007199254740993],
    ['9223372036854775807', $max], ['-9223372036854775807', -$max]] as [$text, $value]) {
    $equal(SignedMinor::parse($text), $value);
    $equal(SignedMinor::parse($value), $value);
}
foreach (['9223372036854775808', '-9223372036854775808', '18446744073709551615', str_repeat('9', 100), PHP_INT_MIN] as $v) {
    $reject(fn () => SignedMinor::parse($v), $overflow);
    $reject(fn () => SignedMinor::parseUnsigned($v), $overflow);
}
foreach ([null, false, true, [], new stdClass(), 1.0, INF, NAN, '', '-0', '01', '-01', '+1', ' 1', "1\n", "1\0", '1.0', '1e2', '١'] as $v) {
    $reject(fn () => SignedMinor::parse($v), $bad);
    $reject(fn () => SignedMinor::parseUnsigned($v), $bad);
}
foreach (['1', 1.0, null, false, [], PHP_INT_MIN] as $v) {
    $type = $v === PHP_INT_MIN ? $overflow : $bad;
    $reject(fn () => SignedMinor::add($v, 0), $type);
    $reject(fn () => SignedMinor::subtract(0, $v), $type);
    $reject(fn () => SignedMinor::negate($v), $type);
    $reject(fn () => SignedMinor::sum([0, $v]), $type);
    $reject(fn () => SignedMinor::decimal($v), $type);
    $reject(fn () => CashMoney::fromMinor($v), $type);
}
$equal(SignedMinor::parseUnsigned('9223372036854775807'), $max);
$reject(fn () => SignedMinor::parseUnsigned('-1'), $bad);
$equal(SignedMinor::sum([]), 0);
$equal(SignedMinor::sum([$max, -$max, 1]), 1);
$reject(fn () => SignedMinor::sum([$max, 1, -1]), $overflow);
$reject(fn () => SignedMinor::sum([-$max, -1, 1]), $overflow);
$equal(SignedMinor::decimal($max), '92233720368547758.07');
$equal(SignedMinor::decimal(-$max), '-92233720368547758.07');
$equal(SignedMinor::decimal(9007199254740993), '90071992547409.93');
$equal(SignedMinor::decimal(-1), '-0.01');
foreach ([['0', 0, '0.00'], ['-0.00', 0, '0.00'], ['0.1', 10, '0.10'], ['-0.01', -1, '-0.01'],
    ['10000000000.00', 1000000000000, '10000000000.00'], ['999999999999.99', $cash, '999999999999.99'],
    ['-999999999999.99', -$cash, '-999999999999.99']] as [$text, $minor, $decimal]) {
    $money = CashMoney::parse($text);
    $equal($money->minor, $minor);
    $equal($money->decimal(), $decimal);
    $equal(CashMoney::fromMinor($minor)->decimal(), $decimal);
}
foreach ([null, true, false, [], 1, 1.0, INF, NAN, '', '00', '+1', '1.', '.1', '1.001', '1.000', '1e2', ' 1', "1\n", "1\0", '١.٠٠'] as $v) {
    $reject(fn () => CashMoney::parse($v), $bad);
}
foreach (['1000000000000', '-1000000000000.00', str_repeat('9', 100)] as $v) {
    $reject(fn () => CashMoney::parse($v), $overflow);
}
$reject(fn () => CashMoney::fromMinor($cash + 1), $overflow);
$reject(fn () => CashMoney::fromMinor(-$cash - 1), $overflow);
$equal(CashMoney::parse('0')->requireNonnegative()->minor, 0);
$equal(CashMoney::parse('0.01')->requirePositive()->minor, 1);
$reject(fn () => CashMoney::parse('0')->requirePositive(), $bad);
$reject(fn () => CashMoney::parse('-0.01')->requireNonnegative(), $bad);
$reject(fn () => CashMoney::parse('-0.01')->requirePositive(), $bad);
$equal(CashMoney::parse('0.30')->minus(CashMoney::parse('0.10'))->decimal(), '0.20');
$equal(CashMoney::parse('0.01')->compare(CashMoney::parse('0.02')), -1);
$equal(CashMoney::parse('0.01')->compare(CashMoney::parse('0.01')), 0);
$equal(CashMoney::parse('0.02')->compare(CashMoney::parse('0.01')), 1);
for ($i = 0; $i <= 1000; ++$i) {
    $near = $max - $i;
    $equal(SignedMinor::parse((string) $near), $near);
    $equal(SignedMinor::parse((string) -$near), -$near);
    $equal(SignedMinor::add($near, $i), $max);
    $equal(SignedMinor::subtract(-$near, $i), -$max);
    $equal(SignedMinor::subtract($near, $near), 0);
    $equal(SignedMinor::negate(-$near), $near);
    $reject(fn () => SignedMinor::add($near, $i + 1), $overflow);
    $reject(fn () => SignedMinor::subtract(-$near, $i + 1), $overflow);
    $nearCash = CashMoney::fromMinor($cash - $i);
    $delta = CashMoney::fromMinor($i);
    $equal($nearCash->plus($delta)->minor, $cash);
    $equal($nearCash->negate()->minus($delta)->minor, -$cash);
    $equal(CashMoney::parse($nearCash->decimal())->minor, $cash - $i);
    $equal(CashMoney::parse($nearCash->negate()->decimal())->minor, -$cash + $i);
    $reject(fn () => $nearCash->plus(CashMoney::fromMinor($i + 1)), $overflow);
    $reject(fn () => $nearCash->negate()->minus(CashMoney::fromMinor($i + 1)), $overflow);
}
echo "ACC09_P2_DOMAIN_PASS {$count} checks\n";
