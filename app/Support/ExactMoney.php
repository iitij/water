<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use OverflowException;

/** Pure bounded DECIMAL(12,2) value; no floating point or implicit coercion. */
final readonly class ExactMoney
{
    public const MAX_MINOR = 999999999999;
    private const MAX_RATE_MINOR = 9999999999;

    private function __construct(public int $minor) {}

    public static function fromMinor(mixed $minor): self
    {
        if (! is_int($minor)) {
            throw new InvalidArgumentException('Integer minor units required.');
        }
        if (PHP_INT_SIZE < 8 || $minor < -self::MAX_MINOR || $minor > self::MAX_MINOR) {
            throw new OverflowException('Money exceeds storage range.');
        }

        return new self($minor);
    }

    public static function parse(mixed $decimal): self
    {
        return self::fromMinor(self::parseMinor($decimal, self::MAX_MINOR));
    }

    private static function parseMinor(mixed $decimal, int $max): int
    {
        if (! is_string($decimal) || ! preg_match('/\A(-?)(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?\z/', $decimal, $parts)) {
            throw new InvalidArgumentException('ASCII decimal string required.');
        }
        $digits = ltrim($parts[2].str_pad($parts[3] ?? '', 2, '0'), '0');
        if (PHP_INT_SIZE < 8 || strlen($digits) > 12 || (int) $digits > $max) {
            throw new OverflowException('Decimal exceeds storage range.');
        }

        return ($parts[1] === '-' ? -1 : 1) * (int) $digits;
    }

    public function decimal(): string
    {
        $digits = str_pad((string) abs($this->minor), 3, '0', STR_PAD_LEFT);

        return ($this->minor < 0 ? '-' : '').substr($digits, 0, -2).'.'.substr($digits, -2);
    }

    public function plus(self $other): self
    {
        return self::fromMinor($this->minor + $other->minor);
    }

    public function minus(self $other): self
    {
        return self::fromMinor($this->minor - $other->minor);
    }

    public function negate(): self
    {
        return self::fromMinor(-$this->minor);
    }

    public function compare(self $other): int
    {
        return $this->minor <=> $other->minor;
    }

    /** Nonnegative quantity (12,2) and rate (10,2), rounded once HALF_UP. */
    public static function tariffLine(mixed $quantity, mixed $rate): self
    {
        $q = self::parseMinor($quantity, self::MAX_MINOR);
        $r = self::parseMinor($rate, self::MAX_RATE_MINOR);
        if ($q < 0 || $r < 0) {
            throw new InvalidArgumentException('Negative tariff input.');
        }
        if ($r === 0) {
            return self::fromMinor(0);
        }
        // Split before multiplying: intermediate products remain within int64.
        $whole = intdiv($q, 100);
        if ($whole > intdiv(self::MAX_MINOR, $r)) {
            throw new OverflowException('Tariff line exceeds storage range.');
        }

        return self::fromMinor($whole * $r + intdiv(($q % 100) * $r + 50, 100));
    }

    /**
     * Allocate in caller-supplied order, without accounting-policy/DB side effects.
     * @param list<self> $caps
     * @return array{allocations: list<self>, surplus: self}
     */
    public function allocate(array $caps): array
    {
        if ($this->minor <= 0 || ! array_is_list($caps)) {
            throw new InvalidArgumentException('Positive payment and ordered caps required.');
        }
        $remaining = $this->minor;
        $allocations = [];
        foreach ($caps as $cap) {
            if (! $cap instanceof self || $cap->minor < 0) {
                throw new InvalidArgumentException('Nonnegative money cap required.');
            }
            $part = min($remaining, $cap->minor);
            $allocations[] = self::fromMinor($part);
            $remaining -= $part;
        }

        return ['allocations' => $allocations, 'surplus' => self::fromMinor($remaining)];
    }
}
