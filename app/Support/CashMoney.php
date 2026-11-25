<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use OverflowException;

/** Isolated DECIMAL(14,2) value. Not a document amount or treasury posting. */
final readonly class CashMoney
{
    public const MAX_MINOR = 99999999999999;

    private function __construct(public int $minor) {}

    public static function fromMinor(mixed $value): self
    {
        $minor = SignedMinor::requireInt($value);
        if ($minor < -self::MAX_MINOR || $minor > self::MAX_MINOR) {
            throw new OverflowException('Cash amount exceeds DECIMAL(14,2).');
        }

        return new self($minor);
    }

    /** Strict decimal text; adapters for legacy numeric callers are not part of this slice. */
    public static function parse(mixed $value): self
    {
        if (! is_string($value) || preg_match('/\A(-?)(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?\z/', $value, $parts) !== 1) {
            throw new InvalidArgumentException('ASCII decimal string required.');
        }
        $digits = ltrim($parts[2].str_pad($parts[3] ?? '', 2, '0'), '0');
        if (strlen($digits) > 14) {
            throw new OverflowException('Cash amount exceeds DECIMAL(14,2).');
        }
        $minor = SignedMinor::parse($digits === '' ? '0' : $digits);

        return self::fromMinor($parts[1] === '-' ? SignedMinor::negate($minor) : $minor);
    }

    public function decimal(): string
    {
        return SignedMinor::decimal($this->minor);
    }

    public function plus(self $other): self
    {
        return self::fromMinor(SignedMinor::add($this->minor, $other->minor));
    }

    public function minus(self $other): self
    {
        return self::fromMinor(SignedMinor::subtract($this->minor, $other->minor));
    }

    public function negate(): self
    {
        return self::fromMinor(SignedMinor::negate($this->minor));
    }

    public function compare(self $other): int
    {
        return $this->minor <=> $other->minor;
    }

    public function requireNonnegative(): self
    {
        if ($this->minor < 0) {
            throw new InvalidArgumentException('Cash amount must be nonnegative.');
        }

        return $this;
    }

    public function requirePositive(): self
    {
        if ($this->minor <= 0) {
            throw new InvalidArgumentException('Cash amount must be positive.');
        }

        return $this;
    }
}
