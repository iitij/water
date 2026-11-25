<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Physical meter quantities (DECIMAL(12,2)), not monetary amounts. */
final class ReadingQuantity
{
    public static function hundredths(mixed $value): int
    {
        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Non-finite meter quantity.');
            }
            $value = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        }
        if ((! is_string($value) && ! is_int($value))
            || ! preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/D', (string) $value, $parts)) {
            throw new InvalidArgumentException('Expected a non-negative DECIMAL(12,2) meter quantity.');
        }

        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    public static function validated(mixed $value, string $field): int
    {
        try {
            return self::hundredths($value);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                $field => 'قيمة القراءة يجب أن تكون غير سالبة وبمنزلتين عشريتين كحد أقصى وفي النطاق المسموح.',
            ]);
        }
    }

    public static function decimal(int $hundredths): string
    {
        if ($hundredths < 0 || $hundredths > 999999999999) {
            throw new InvalidArgumentException('Meter quantity outside DECIMAL(12,2).');
        }

        return intdiv($hundredths, 100).'.'.str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT);
    }
}
