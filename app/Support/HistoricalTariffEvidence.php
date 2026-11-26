<?php

declare(strict_types=1);

namespace App\Support;

use DomainException;

/** Validate persisted original pricing evidence, never consult the mutable tariff catalog. */
final class HistoricalTariffEvidence
{
    public static function verify(iterable $readings, iterable $lines, mixed $consumptionFee): void
    {
        $remaining = [];
        foreach ($readings as $reading) {
            $id = (string) $reading->id;
            if (isset($remaining[$id]) || ! $reading->period_start || ! $reading->period_end
                || $reading->period_start >= $reading->period_end) {
                throw new DomainException('Invalid original reading identity or period.');
            }
            $remaining[$id] = $reading;
        }
        if ($remaining === []) {
            throw new DomainException('Original readings missing.');
        }

        $total = ExactMoney::fromMinor(0);
        foreach ($lines as $line) {
            $id = (string) $line->reading_id;
            $reading = $remaining[$id] ?? null;
            if ($reading === null || $line->period_start !== $reading->period_start
                || $line->period_end !== $reading->period_end) {
                throw new DomainException('Original tariff line has wrong reading or period.');
            }
            $units = FinancialAmount::parse($line->units);
            if ($units->minor !== FinancialAmount::parse($reading->consumption_units)->minor) {
                throw new DomainException('Original tariff quantity does not match the reading.');
            }
            $price = FinancialAmount::parse($line->unit_price);
            $amount = FinancialAmount::parse($line->amount);
            if (ExactMoney::tariffLine($units->decimal(), $price->decimal())->minor !== $amount->minor) {
                throw new DomainException('Original tariff line amount is inconsistent.');
            }
            $total = $total->plus($amount);
            unset($remaining[$id]);
        }

        if ($remaining !== [] || $total->minor !== FinancialAmount::parse($consumptionFee)->minor) {
            throw new DomainException('Incomplete original tariff evidence or inconsistent invoice consumption.');
        }
    }
}
