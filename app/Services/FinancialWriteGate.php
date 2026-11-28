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
    private static ?array $current = null;

    public static function currentContext(): array
    {
        if (self::$current === null || DB::transactionLevel() <= 0
            || self::$current['pdo'] !== spl_object_id(DB::connection()->getPdo())) {
            throw new DomainException('active_held_release_requires_gate');
        }
        return self::$current['context'];
    }

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

        app(FinancialConnectionIntegrity::class)->requireActive();
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
            // Even if the mutable projection were reopened by a privileged
            // bypass, terminal evidence must never be treated as an open period.
            if (DB::table('financial_period_closures')->where('period_id', $period->id)->lockForUpdate()->first()
                || DB::table('financial_close_commands')->where('period_id', $period->id)
                    ->where('action', 'approve')->lockForUpdate()->first()) {
                throw new DomainException('financial_period_permanently_closed');
            }
            $version = $this->counter($period->version);
            $id = $this->counter($period->id);
            if ($id === 0 || $effectiveOn > $asOf->setTimezone(new DateTimeZone($gate->timezone))->format('Y-m-d')) {
                throw new DomainException('invalid_financial_destination');
            }

            $context = ['period_id' => $id, 'scope' => $scope, 'effective_on' => $effectiveOn,
                'epoch' => $epoch, 'version' => $version];
            if (self::$current !== null) throw new DomainException('financial_gate_context_conflict');
            self::$current = ['context' => $context, 'pdo' => spl_object_id(DB::connection()->getPdo())];
            try {
                $result = $writer($context);
                app(FinancialConnectionIntegrity::class)->requireActive();
                $this->assertUnchanged($context, $expected);
                return $result;
            } finally {
                self::$current = null;
            }
        }, 1);
    }

    /** The callback must not mutate the financial control plane, including
     * via an internal service on the same locked connection. Recheck before
     * commit, not only when recording an individual posting.
     */
    private function assertUnchanged(array $context, array $expected): void
    {
        if (DB::transactionLevel() !== 1 || self::$current === null
            || self::$current['pdo'] !== spl_object_id(DB::connection()->getPdo())) {
            throw new DomainException('financial_gate_transaction_changed');
        }
        $gate = DB::table('financial_scopes')->where('scope_key', $context['scope'])->lockForUpdate()->first();
        if (! $gate || $gate->mode !== 'ACTIVE' || $gate->active_freeze_uuid !== null
            || $gate->currency !== 'YER' || $gate->timezone !== 'Asia/Aden'
            || $this->counter($gate->calendar_version) !== 1
            || $this->counter($gate->fence_epoch) !== $context['epoch']) {
            throw new DomainException('financial_gate_scope_changed');
        }
        $period = DB::table('financial_periods')->where('id', $context['period_id'])->lockForUpdate()->first();
        if (! $period || $period->scope_key !== $context['scope'] || $period->month !== substr($context['effective_on'], 0, 7)
            || $period->state !== 'OPEN' || $period->start_on !== $expected['start']
            || $period->next_on !== $expected['next'] || $this->counter($period->version) !== $context['version']
            || DB::table('financial_period_closures')->where('period_id', $context['period_id'])->lockForUpdate()->first()
            || DB::table('financial_close_commands')->where('period_id', $context['period_id'])
                ->where('action', 'approve')->lockForUpdate()->first()) {
            throw new DomainException('financial_gate_period_changed');
        }
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
