<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\FinancialClosePolicy;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Foundation for future all-writer integration. No public activation or caller.
 * Authorization and posting provenance remain the responsibility of a reviewed
 * writer adapter; this gate alone is not a complete financial close.
 */
final class FinancialWriteGate
{
    public function __construct(private readonly FinancialClosePolicy $policy)
    {
    }

    /**
     * The outer transaction must begin here, before any business-resource lock.
     * The callback may only perform work for the verified scope/date/period.
     */
    public function withinOpenPeriod(
        string $scope,
        string $effectiveOn,
        DateTimeImmutable $asOf,
        callable $writer,
    ): mixed {
        if (DB::connection()->transactionLevel() !== 0) {
            throw new DomainException('financial_gate_must_be_first');
        }
        if (! preg_match('/\A[a-z0-9_-]{1,64}\z/', $scope)
            || ! preg_match('/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}\z/', $effectiveOn)) {
            throw new DomainException('invalid_financial_destination');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveOn, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $effectiveOn) {
            throw new DomainException('invalid_financial_destination');
        }

        return DB::transaction(function () use ($scope, $effectiveOn, $asOf, $writer): mixed {
            $gate = DB::table('financial_scopes')->where('scope_key', $scope)->lockForUpdate()->first();
            if (! $gate || $gate->scope_key !== $scope || $gate->mode !== 'ACTIVE'
                || $gate->active_freeze_uuid !== null || $gate->currency !== 'YER'
                || $gate->timezone !== 'Asia/Aden' || $this->counter($gate->calendar_version) !== 1) {
                throw new DomainException('financial_scope_not_active');
            }
            $epoch = $this->counter($gate->fence_epoch);
            $month = substr($effectiveOn, 0, 7);
            $expected = $this->policy->open($scope, $month, $gate->timezone);
            $period = DB::table('financial_periods')->where('scope_key', $scope)
                ->where('month', $month)->lockForUpdate()->first();
            if (! $period || $period->scope_key !== $scope || $period->month !== $month
                || $period->state !== 'OPEN' || $period->start_on !== $expected['start']
                || $period->next_on !== $expected['next']) {
                throw new DomainException('financial_period_not_open');
            }
            $version = $this->counter($period->version);
            $id = $this->counter($period->id);
            if ($id === 0 || $effectiveOn > $asOf->setTimezone(new DateTimeZone($gate->timezone))->format('Y-m-d')) {
                throw new DomainException('invalid_financial_destination');
            }

            return $writer(['period_id' => $id, 'scope' => $scope, 'effective_on' => $effectiveOn,
                'epoch' => $epoch, 'version' => $version]);
        }, 1);
    }

    /**
     * Inactive two-date foundation: acquire the scope and every affected
     * period before touching business resources. One outer transaction;
     * never call the single-period gate from inside this callback.
     *
     * Contexts are keyed by exact effective date, even in the same month.
     * This is NOT a multi-date posting registry, receipt writer, historical
     * adjustment policy or authorization to reopen closed periods.
     */
    public function withinOpenPeriods(
        string $scope,
        array $effectiveDates,
        DateTimeImmutable $asOf,
        callable $writer,
    ): mixed {
        if (DB::connection()->transactionLevel() !== 0) {
            throw new DomainException('financial_gate_must_be_first');
        }
        if (! preg_match('/\A[a-z0-9_-]{1,64}\z/', $scope)
            || ! array_is_list($effectiveDates) || count($effectiveDates) < 1 || count($effectiveDates) > 2) {
            throw new DomainException('invalid_financial_destination');
        }
        foreach ($effectiveDates as $value) {
            if (! is_string($value) || ! preg_match('/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}\z/', $value)) {
                throw new DomainException('invalid_financial_destination');
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
            if ($date === false || $date->format('Y-m-d') !== $value) {
                throw new DomainException('invalid_financial_destination');
            }
        }
        $dates = array_values(array_unique($effectiveDates));
        sort($dates, SORT_STRING);

        return DB::transaction(function () use ($scope, $dates, $asOf, $writer): mixed {
            $gate = DB::table('financial_scopes')->where('scope_key', $scope)->lockForUpdate()->first();
            if (! $gate || $gate->scope_key !== $scope || $gate->mode !== 'ACTIVE'
                || $gate->active_freeze_uuid !== null || $gate->currency !== 'YER'
                || $gate->timezone !== 'Asia/Aden' || $this->counter($gate->calendar_version) !== 1) {
                throw new DomainException('financial_scope_not_active');
            }
            $epoch = $this->counter($gate->fence_epoch);
            $today = $asOf->setTimezone(new DateTimeZone($gate->timezone))->format('Y-m-d');
            $periods = [];
            $contexts = [];
            foreach ($dates as $effectiveOn) {
                if ($effectiveOn > $today) {
                    throw new DomainException('invalid_financial_destination');
                }
                $month = substr($effectiveOn, 0, 7);
                if (! isset($periods[$month])) {
                    $expected = $this->policy->open($scope, $month, $gate->timezone);
                    $period = DB::table('financial_periods')->where('scope_key', $scope)
                        ->where('month', $month)->lockForUpdate()->first();
                    if (! $period || $period->scope_key !== $scope || $period->month !== $month
                        || $period->state !== 'OPEN' || $period->start_on !== $expected['start']
                        || $period->next_on !== $expected['next']) {
                        throw new DomainException('financial_period_not_open');
                    }
                    $periods[$month] = $period;
                }
                $period = $periods[$month];
                $id = $this->counter($period->id);
                if ($id === 0 || $effectiveOn < $period->start_on || $effectiveOn >= $period->next_on) {
                    throw new DomainException('invalid_financial_destination');
                }
                $contexts[$effectiveOn] = ['period_id' => $id, 'scope' => $scope,
                    'effective_on' => $effectiveOn, 'epoch' => $epoch, 'version' => $this->counter($period->version)];
            }
            return $writer($contexts);
        }, 1);
    }

    private function counter(mixed $raw): int
    {
        if (! is_int($raw) && ! is_string($raw)) {
            throw new DomainException('invalid_financial_counter');
        }
        $digits = (string) $raw;
        $max = (string) PHP_INT_MAX;
        if (! preg_match('/\A(?:0|[1-9][0-9]*)\z/', $digits)
            || strlen($digits) > strlen($max)
            || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            throw new DomainException('invalid_financial_counter');
        }
        return (int) $digits;
    }
}
