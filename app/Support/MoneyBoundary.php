<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use OverflowException;

/** Isolated boundary contract. No database, framework or endpoint policy changes. */
final class MoneyBoundary
{
    public const JS_SAFE_MINOR = 9007199254740991;

    /** Raw DECIMAL major units. Only SQLite may expose an already stored float. */
    public static function readDecimal(mixed $raw, DecimalDomain $domain, string $driver,
        bool $nullable = false, bool $signed = false): ?int
    {
        if (! in_array($driver, ['sqlite', 'mysql'], true)) {
            throw new InvalidArgumentException('Unsupported DECIMAL driver.');
        }
        if ($raw === null && $nullable) {
            return null;
        }
        if (is_float($raw) && $driver !== 'sqlite') {
            throw new InvalidArgumentException('Unexpected floating DECIMAL value.');
        }
        // Float acceptance establishes only the observed driver's cents, never
        // the precision of the original pre-storage value.
        $text = self::observedText($raw);
        $minor = self::canonicalMinor($text, $domain);

        return self::bound($minor, $domain, $signed);
    }

    /** Existing bounded digit grammar: leading zeros allowed, no exponent/space. */
    public static function regexInput(mixed $raw, DecimalDomain $domain, bool $signed = false,
        int $minimum = 0, ?int $maximum = null): int
    {
        $text = self::observedText($raw);
        $whole = $domain->value - 2;
        if (preg_match('/\A'.($signed ? '-?' : '').'[0-9]{1,'.$whole.'}(?:\.[0-9]{1,2})?\z/', $text) !== 1) {
            throw new InvalidArgumentException('Amount does not match legacy digit grammar.');
        }

        return self::inputBound(self::numericMinor($text), $domain, $signed, $minimum, $maximum);
    }

    /** Existing numeric input family; parse before narrowing to the endpoint cap. */
    public static function numericInput(mixed $raw, DecimalDomain $domain, bool $signed = false,
        int $minimum = 0, ?int $maximum = null): int
    {
        return self::inputBound(self::numericMinor(self::observedText($raw)), $domain, $signed, $minimum, $maximum);
    }

    /** Canonical string for an existing DECIMAL destination; native cents only. */
    public static function writeDecimal(mixed $minor, DecimalDomain $domain, bool $signed = false): string
    {
        return SignedMinor::decimal(self::bound(SignedMinor::requireInt($minor), $domain, $signed));
    }

    /** BIGINT storage uses cents, not major units; narrower source cap optional. */
    public static function readMinor(mixed $raw, bool $unsigned, ?DecimalDomain $source = null,
        bool $nullable = false): ?int
    {
        if ($raw === null && $nullable) {
            return null;
        }
        $minor = $unsigned ? SignedMinor::parseUnsigned($raw) : SignedMinor::parse($raw);

        return $source === null ? $minor : self::bound($minor, $source, ! $unsigned);
    }

    public static function writeMinor(mixed $minor, bool $unsigned, ?DecimalDomain $source = null): int
    {
        return self::readMinor(SignedMinor::requireInt($minor), $unsigned, $source);
    }

    /** Existing numeric minor-unit output: fail closed outside JS exact integers. */
    public static function numericMinorOutput(mixed $minor): int
    {
        $value = SignedMinor::requireInt($minor);
        if ($value < -self::JS_SAFE_MINOR || $value > self::JS_SAFE_MINOR) {
            throw new OverflowException('Numeric minor output is not portable.');
        }

        return $value;
    }

    /** Existing major-unit numeric presentation only, never authoritative input. */
    public static function numericMajorOutput(mixed $minor): float
    {
        $value = self::numericMinorOutput($minor);
        $output = (float) SignedMinor::decimal($value);
        if (self::numericMinor(self::observedText($output)) !== $value) {
            throw new OverflowException('Numeric major output loses cents.');
        }

        return $output;
    }

    private static function canonicalMinor(string $text, DecimalDomain $domain): int
    {
        return $domain === DecimalDomain::D14 ? CashMoney::parse($text)->minor : ExactMoney::parse($text)->minor;
    }

    private static function bound(int $minor, DecimalDomain $domain, bool $signed): int
    {
        if ($minor < -$domain->maxMinor() || $minor > $domain->maxMinor()) {
            throw new OverflowException('Amount exceeds source or destination DECIMAL range.');
        }
        if (! $signed && $minor < 0) {
            throw new InvalidArgumentException('Nonnegative amount required.');
        }

        return $minor;
    }

    private static function inputBound(int $minor, DecimalDomain $domain, bool $signed,
        int $minimum, ?int $maximum): int
    {
        $minor = self::bound($minor, $domain, $signed);
        $maximum ??= $domain->maxMinor();
        if ($minimum < ($signed ? -$domain->maxMinor() : 0) || $maximum > $domain->maxMinor() || $minimum > $maximum) {
            throw new InvalidArgumentException('Invalid endpoint range configuration.');
        }
        if ($minor < $minimum || $minor > $maximum) {
            throw new OverflowException('Amount exceeds endpoint range.');
        }

        return $minor;
    }

    private static function observedText(mixed $raw): string
    {
        if (is_string($raw)) {
            return $raw;
        }
        if (is_int($raw)) {
            return (string) $raw;
        }
        if (! is_float($raw) || ! is_finite($raw) || ini_get('serialize_precision') !== '-1') {
            throw new InvalidArgumentException('Exact observed decimal representation unavailable.');
        }
        $text = json_encode($raw, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        if ((float) $text !== $raw) {
            throw new InvalidArgumentException('Observed float cannot round-trip.');
        }

        return $text;
    }

    /** Normalize legacy lexical syntax into canonical cents, not float arithmetic. */
    private static function numericMinor(string $text): int
    {
        if (strlen($text) > 256 || preg_match('/\A([+-]?)(?:(\d+)(?:\.(\d*))?|\.(\d+))(?:[eE]([+-]?\d+))?\z/',
            trim($text, " \t\n\r\v\f"), $parts, PREG_UNMATCHED_AS_NULL) !== 1) {
            throw new InvalidArgumentException('Finite decimal amount required.');
        }
        $fraction = $parts[4] ?? $parts[3] ?? '';
        $exponent = $parts[5] ?? '0';
        if (strlen(ltrim(ltrim($exponent, '+-'), '0')) > 3 || abs((int) $exponent) > 256) {
            throw new OverflowException('Exponent exceeds supported range.');
        }
        $digits = ltrim(($parts[2] ?? '').$fraction, '0');
        if ($digits === '') {
            return 0;
        }
        $shift = (int) $exponent - strlen($fraction) + 2;
        if ($shift < 0) {
            $remove = -$shift;
            if ($remove >= strlen($digits) || substr($digits, -$remove) !== str_repeat('0', $remove)) {
                throw new InvalidArgumentException('Amount is not an exact number of cents.');
            }
            $digits = substr($digits, 0, -$remove);
        } elseif ($shift > 0) {
            if (strlen($digits) + $shift > 19) {
                throw new OverflowException('Amount exceeds portable signed range.');
            }
            $digits .= str_repeat('0', $shift);
        }

        return SignedMinor::parse(($parts[1] === '-' ? '-' : '').$digits);
    }
}
