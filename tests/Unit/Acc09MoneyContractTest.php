<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;

/** Pure test oracle only: this is not the production money implementation. */
final class Acc09MoneyContractTest extends TestCase
{
    private const MAX = 999999999999;

    private function parse(mixed $value, int $max = self::MAX): int
    {
        if (! is_string($value) || ! preg_match('/\A(-?)(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?\z/', $value, $m)) {
            throw new InvalidArgumentException('Invalid decimal string');
        }
        $digits = ltrim($m[2].str_pad($m[3] ?? '', 2, '0'), '0');
        if (strlen($digits) > 12 || (int) $digits > $max) {
            throw new OverflowException('Storage range');
        }

        return ($m[1] === '-' ? -1 : 1) * (int) $digits;
    }

    private function format(int $value): string
    {
        $digits = str_pad((string) abs($value), 3, '0', STR_PAD_LEFT);

        return ($value < 0 ? '-' : '').substr($digits, 0, -2).'.'.substr($digits, -2);
    }

    private function checked(int $value): int
    {
        if (abs($value) > self::MAX) throw new OverflowException('Storage range');
        return $value;
    }

    private function line(mixed $quantity, mixed $rate): string
    {
        $q = $this->parse($quantity);
        $r = $this->parse($rate, 9999999999);
        if ($q < 0 || $r < 0) throw new InvalidArgumentException('Negative line input');
        if ($r === 0) return '0.00';
        // Avoid any intermediate 64-bit overflow: split quantity into whole and fractional parts.
        $whole = intdiv($q, 100);
        if ($whole > intdiv(self::MAX, $r)) throw new OverflowException('Line range');
        $fraction = intdiv(($q % 100) * $r + 50, 100);
        return $this->format($this->checked($whole * $r + $fraction));
    }

    private function allocation(array $case): array
    {
        $amount = $this->parse($case['amount']);
        if ($amount <= 0) throw new InvalidArgumentException('Payment must be positive');
        $remaining = $amount;
        $allocated = [];
        foreach ($case['caps'] as $cap) {
            $limit = $this->parse($cap);
            if ($limit < 0) throw new InvalidArgumentException('Negative cap');
            $part = min($remaining, $limit);
            $allocated[] = $this->format($part);
            $remaining -= $part;
        }
        return [$allocated, $this->format($remaining)];
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
            self::assertSame($want, $this->format($this->checked($this->parse($a) + $this->parse($b))));
        }
        foreach ($v['invalidAdd'] as [$a, $b]) {
            $this->rejects(fn () => $this->checked($this->parse($a) + $this->parse($b)));
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
}
