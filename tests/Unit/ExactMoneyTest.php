<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ExactMoney;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;

/** Runs the actual isolated product primitives against the shared vectors. */
final class ExactMoneyTest extends TestCase
{
    private function parse(mixed $value): int
    {
        return ExactMoney::parse($value)->minor;
    }

    private function format(int $value): string
    {
        return ExactMoney::fromMinor($value)->decimal();
    }

    private function line(mixed $quantity, mixed $rate): string
    {
        return ExactMoney::tariffLine($quantity, $rate)->decimal();
    }

    private function allocation(array $case): array
    {
        $result = ExactMoney::parse($case['amount'])->allocate(array_map(ExactMoney::parse(...), $case['caps']));

        return [array_map(fn ($money) => $money->decimal(), $result['allocations']), $result['surplus']->decimal()];
    }

    private function vectors(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../../docs/accounting/ACC_09_MONEY_VECTORS.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function rejects(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected rejected financial value');
        } catch (InvalidArgumentException|OverflowException) {
            self::assertTrue(true);
        }
    }

    public function test_exact_parse_format_and_input_rejections(): void
    {
        $v = $this->vectors();
        foreach ($v['parse'] as [$input, $minor]) {
            self::assertSame($minor, $this->parse($input));
            self::assertSame($minor, $this->parse($this->format($minor)));
        }
        foreach ($v['invalidParse'] as $input) $this->rejects(fn () => $this->parse($input));
    }

    public function test_addition_and_storage_overflow(): void
    {
        $v = $this->vectors();
        foreach ($v['add'] as [$a, $b, $want]) {
            self::assertSame($want, ExactMoney::parse($a)->plus(ExactMoney::parse($b))->decimal());
        }
        foreach ($v['invalidAdd'] as [$a, $b]) {
            $this->rejects(fn () => ExactMoney::parse($a)->plus(ExactMoney::parse($b)));
        }
    }

    public function test_exact_line_multiplication_and_single_rounding(): void
    {
        $v = $this->vectors();
        foreach ($v['line'] as [$q, $r, $want]) self::assertSame($want, $this->line($q, $r));
        foreach ($v['invalidLine'] as [$q, $r]) $this->rejects(fn () => $this->line($q, $r));
    }

    public function test_allocation_conservation_and_caps(): void
    {
        $v = $this->vectors();
        foreach ($v['allocate'] as $case) {
            [$allocated, $surplus] = $this->allocation($case);
            self::assertSame($case['allocations'], $allocated);
            self::assertSame($case['surplus'], $surplus);
            self::assertSame($this->parse($case['amount']), array_sum(array_map(fn ($a) => $this->parse($a), $allocated)) + $this->parse($surplus));
            foreach ($allocated as $i => $part) {
                self::assertGreaterThanOrEqual(0, $this->parse($part));
                self::assertLessThanOrEqual($this->parse($case['caps'][$i]), $this->parse($part));
            }
        }
        foreach ($v['invalidAllocate'] as $case) $this->rejects(fn () => $this->allocation($case));
    }
    public function test_signed_operations_and_invalid_minor_inputs(): void
    {
        $max = ExactMoney::fromMinor(ExactMoney::MAX_MINOR);
        $min = $max->negate();
        self::assertSame('0.00', $max->plus($min)->decimal());
        self::assertSame('0.00', $max->minus($max)->decimal());
        self::assertSame(-1, $min->compare($max));
        self::assertSame(0, $max->compare($max));
        self::assertSame(1, $max->compare($min));
        self::assertSame('0.00', ExactMoney::parse('-0.00')->decimal());
        $this->rejects(fn () => $max->minus($min));
        $this->rejects(fn () => $min->minus($max));
        foreach ([1.0, '1', null, true, PHP_INT_MIN, PHP_INT_MAX] as $bad) {
            $this->rejects(fn () => ExactMoney::fromMinor($bad));
        }
        foreach (["1\n", "1\r", '١.٢', str_repeat('9', 1000)] as $bad) {
            $this->rejects(fn () => ExactMoney::parse($bad));
        }
        $this->rejects(fn () => ExactMoney::parse('1')->allocate(['1']));
        $this->rejects(fn () => ExactMoney::parse('1')->allocate([2 => ExactMoney::parse('1')]));
        $this->rejects(fn () => ExactMoney::parse('1')->allocate([ExactMoney::parse('1'), ExactMoney::parse('-1')]));
    }

    public function test_exhaustive_fractional_rounding_and_maximum(): void
    {
        self::assertSame('9999999999.99', ExactMoney::tariffLine('9999999999.99', '1.00')->decimal());
        self::assertSame('0.02', ExactMoney::tariffLine('0.01', '0.50')->plus(ExactMoney::tariffLine('0.01', '0.50'))->decimal());
        for ($q = 0; $q <= 99; $q++) {
            for ($r = 0; $r <= 99; $r++) {
                self::assertSame(intdiv($q * $r + 50, 100), ExactMoney::tariffLine(ExactMoney::fromMinor($q)->decimal(), ExactMoney::fromMinor($r)->decimal())->minor);
            }
        }
    }
}
