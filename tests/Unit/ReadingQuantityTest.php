<?php

namespace Tests\Unit;

use App\Support\ReadingQuantity;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReadingQuantityTest extends TestCase
{
    public function test_exact_quantities_round_trip_without_rounding(): void
    {
        foreach ([
            [0, 0, '0.00'],
            ['0.01', 1, '0.01'],
            ['100.2', 10020, '100.20'],
            ['100.29', 10029, '100.29'],
            [100.30, 10030, '100.30'],
            ['9999999999.99', 999999999999, '9999999999.99'],
        ] as [$input, $expected, $decimal]) {
            $this->assertSame($expected, ReadingQuantity::hundredths($input));
            $this->assertSame($decimal, ReadingQuantity::decimal($expected));
        }
        $this->assertSame('0.01', ReadingQuantity::decimal(
            ReadingQuantity::hundredths('9999999999.99') - ReadingQuantity::hundredths('9999999999.98')
        ));
    }

    public function test_invalid_quantities_are_not_rounded_or_coerced(): void
    {
        foreach ([-1, '-0.01', '100.000', '100.001', '10000000000.00', '1e2', 'NaN', null, true, [], INF, NAN, 0.001] as $invalid) {
            try {
                ReadingQuantity::hundredths($invalid);
                $this->fail('Invalid quantity accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
