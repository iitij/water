<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Internal provenance registry, not a second ledger and not an activated writer.
 * Future trusted adapters must derive legs from actual persisted source effects.
 * This service does not certify source coverage or authorize a close.
 */
final class FinancialPostingRegistry
{
    /**
     * Record one operation inside the existing gate-first transaction.
     * Returns true for a new record; false for verified identical open-period replay.
     * No update/delete API. Database privilege/immutable protection remains a release gate.
     */
    public function record(array $context, string $uuid, array $legs, DateTimeImmutable $recordedAt): bool
    {
        if (DB::connection()->transactionLevel() === 0) {
            throw new DomainException('posting_registry_requires_outer_gate');
        }

        $this->need(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1, 'posting_uuid');
        $this->need(is_string($context['scope'] ?? null)
            && preg_match('/\A[a-z0-9_-]{1,64}\z/', $context['scope']) === 1
            && is_int($context['period_id'] ?? null) && $context['period_id'] > 0
            && is_int($context['epoch'] ?? null) && $context['epoch'] >= 0
            && is_int($context['version'] ?? null) && $context['version'] >= 0
            && is_string($context['effective_on'] ?? null), 'posting_context');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $context['effective_on'], new DateTimeZone('UTC'));
        $this->need($date !== false && $date->format('Y-m-d') === $context['effective_on'], 'posting_date');

        $this->need(app(FinancialPostingRegistryProtection::class)->active(), 'posting_registry_protection_missing');
        $canonicalLegs = $this->legs($legs);
        $payload = [
            'format' => 1, 'operation_uuid' => $uuid, 'scope' => $context['scope'],
            'period_id' => $context['period_id'], 'effective_on' => $context['effective_on'],
            'epoch' => $context['epoch'], 'version' => $context['version'],
            'legs' => $canonicalLegs,
        ];
        $bytes = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $bytes);

        // A savepoint makes the registry itself atomic if an outer caller catches an exception.
        return DB::transaction(function () use ($context, $uuid, $canonicalLegs, $bytes, $hash, $recordedAt): bool {
            $gate = DB::table('financial_scopes')->where('scope_key', $context['scope'])->lockForUpdate()->first();
            $this->need($gate && $gate->scope_key === $context['scope'] && $gate->mode === 'ACTIVE'
                && $gate->active_freeze_uuid === null && $gate->currency === 'YER'
                && $gate->timezone === 'Asia/Aden' && (string) $gate->calendar_version === '1'
                && (string) $gate->fence_epoch === (string) $context['epoch'], 'posting_scope_changed');
            $period = DB::table('financial_periods')->where('id', $context['period_id'])->lockForUpdate()->first();
            $this->need($period && $period->scope_key === $context['scope'] && $period->state === 'OPEN'
                && (string) $period->version === (string) $context['version']
                && $context['effective_on'] >= $period->start_on && $context['effective_on'] < $period->next_on
                && $context['effective_on'] <= $recordedAt->setTimezone(new DateTimeZone($gate->timezone))->format('Y-m-d'), 'posting_period_changed');

            $this->need(! DB::table('financial_period_closures')->where('period_id', $period->id)->lockForUpdate()->first()
                && ! DB::table('financial_close_commands')->where('period_id', $period->id)
                    ->where('action', 'approve')->lockForUpdate()->first(), 'posting_period_permanently_closed');
            $existing = DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->lockForUpdate()->first();
            if ($existing) {
                $this->need($existing->operation_uuid === $uuid && $existing->scope_key === $context['scope']
                    && (string) $existing->period_id === (string) $context['period_id']
                    && $existing->effective_on === $context['effective_on']
                    && (string) $existing->fence_epoch === (string) $context['epoch']
                    && (string) $existing->period_version === (string) $context['version']
                    && $existing->canonical_payload === $bytes
                    && hash_equals($hash, $existing->payload_hash), 'posting_replay_conflict');
                $stored = [];
                foreach (DB::table('financial_posting_events')->where('operation_uuid', $uuid)->lockForUpdate()->get() as $row) {
                    $this->need($row->scope_key === $context['scope'] && $row->operation_uuid === $uuid, 'posting_effect_corrupt');
                    $stored[] = ['source_type' => $row->source_type, 'source_id' => $row->source_id,
                        'leg' => $row->leg, 'amount_minor' => (string) $row->amount_minor,
                        'currency' => $row->currency, 'source_hash' => $row->source_hash];
                }
                usort($stored, fn (array $a, array $b) => strcmp($this->identity($a), $this->identity($b)));
                $this->need($stored === $canonicalLegs, 'posting_effect_corrupt');
                return false;
            }

            DB::table('financial_posting_operations')->insert([
                'operation_uuid' => $uuid, 'period_id' => $context['period_id'], 'scope_key' => $context['scope'],
                'effective_on' => $context['effective_on'], 'fence_epoch' => $context['epoch'],
                'period_version' => $context['version'], 'recorded_at_utc' => $recordedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
                'payload_hash' => $hash, 'canonical_payload' => $bytes,
            ]);
            foreach ($canonicalLegs as $leg) {
                DB::table('financial_posting_events')->insert($leg + [
                    'operation_uuid' => $uuid, 'scope_key' => $context['scope'],
                ]);
            }
            return true;
        }, 1);
    }

    /**
     * Verify persisted evidence under the existing scope-first transaction.
     * An empty or internally consistent registry is NOT proof of complete
     * business-source coverage. No authorization or close decision is made here.
     */
    public function verifyPeriod(string $scope, int $periodId): array
    {
        $this->need(DB::connection()->transactionLevel() > 0, 'posting_registry_requires_outer_gate');
        $this->need($periodId > 0 && preg_match('/\A[a-z0-9_-]{1,64}\z/', $scope) === 1, 'posting_context');
        $gate = DB::table('financial_scopes')->where('scope_key', $scope)->lockForUpdate()->first();
        $this->need($gate && $gate->scope_key === $scope && $gate->mode === 'ACTIVE'
            && $gate->currency === 'YER' && $gate->timezone === 'Asia/Aden'
            && (string) $gate->calendar_version === '1', 'posting_scope_changed');
        $period = DB::table('financial_periods')->where('id', $periodId)->lockForUpdate()->first();
        $this->need($period && $period->scope_key === $scope
            && in_array($period->state, ['OPEN', 'FROZEN', 'CLOSED'], true), 'posting_period_changed');

        $this->need(app(FinancialPostingRegistryProtection::class)->active(), 'posting_registry_protection_missing');
        $epoch = $this->exactCounter($gate->fence_epoch);
        $version = $this->exactCounter($period->version);
        $expected = app(\App\Support\FinancialClosePolicy::class)->open($scope, $period->month, $gate->timezone);
        $this->need($period->start_on === $expected['start'] && $period->next_on === $expected['next'], 'posting_period_changed');
        $digest = hash_init('sha256');
        hash_update($digest, $this->encode(['format' => 1, 'scope' => $scope, 'period_id' => $periodId, 'month' => $period->month])."\n");
        $operations = 0;
        $events = 0;
        $previous = null;
        $query = DB::table('financial_posting_operations')->where('period_id', $periodId)->lockForUpdate();
        foreach ($query->lazyById(250, 'operation_uuid') as $row) {
            $uuid = $row->operation_uuid;
            $this->need(is_string($uuid)
                && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1
                && ($previous === null || strcmp($previous, $uuid) < 0)
                && $row->scope_key === $scope
                && (string) $row->period_id === (string) $periodId, 'posting_operation_corrupt');
            $previous = $uuid;
            $postedEpoch = $this->exactCounter($row->fence_epoch);
            $postedVersion = $this->exactCounter($row->period_version);
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $row->effective_on, new DateTimeZone('UTC'));
            $recorded = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $row->recorded_at_utc, new DateTimeZone('UTC'));
            $this->need($postedEpoch <= $epoch && $postedVersion <= $version
                && $date !== false && $date->format('Y-m-d') === $row->effective_on
                && $row->effective_on >= $period->start_on && $row->effective_on < $period->next_on
                && $recorded !== false && $recorded->format('Y-m-d\TH:i:s.u\Z') === $row->recorded_at_utc
                && $row->effective_on <= $recorded->setTimezone(new DateTimeZone($gate->timezone))->format('Y-m-d'), 'posting_operation_corrupt');

            $rawLegs = [];
            foreach (DB::table('financial_posting_events')->where('operation_uuid', $uuid)->lockForUpdate()->get() as $leg) {
                $this->need($leg->scope_key === $scope && $leg->operation_uuid === $uuid, 'posting_effect_corrupt');
                $minor = (string) $leg->amount_minor;
                $this->need(preg_match('/\A(?:0|-?[1-9][0-9]*)\z/', $minor) === 1
                    && (string) (int) $minor === $minor && (int) $minor !== PHP_INT_MIN, 'posting_effect_corrupt');
                $rawLegs[] = ['source_type' => $leg->source_type, 'source_id' => $leg->source_id,
                    'leg' => $leg->leg, 'amount_minor' => (int) $minor, 'currency' => $leg->currency,
                    'source_hash' => $leg->source_hash];
            }
            $canonical = $this->legs($rawLegs);
            $bytes = $this->encode([
                'format' => 1, 'operation_uuid' => $uuid, 'scope' => $scope,
                'period_id' => $periodId, 'effective_on' => $row->effective_on,
                'epoch' => $postedEpoch, 'version' => $postedVersion, 'legs' => $canonical,
            ]);
            $this->need($row->canonical_payload === $bytes
                && hash_equals(hash('sha256', $bytes), $row->payload_hash), 'posting_evidence_corrupt');
            hash_update($digest, $this->encode([$uuid, $row->payload_hash, $row->recorded_at_utc])."\n");
            $operations++;
            $events += count($canonical);
        }
        return ['operations' => $operations, 'events' => $events,
            'registry_hash' => hash_final($digest), 'source_coverage_verified' => false];
    }

    private function exactCounter(mixed $value): int
    {
        $this->need(is_int($value) || is_string($value), 'posting_counter');
        $text = (string) $value;
        $this->need(preg_match('/\A(?:0|[1-9][0-9]*)\z/', $text) === 1
            && (string) (int) $text === $text, 'posting_counter');
        return (int) $text;
    }

    private function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function legs(array $legs): array
    {
        $this->need(array_is_list($legs) && count($legs) > 0 && count($legs) <= 10000, 'posting_legs');
        $result = [];
        foreach ($legs as $leg) {
            $this->need(is_array($leg) && count($leg) === 6, 'posting_leg');
            foreach (['source_type' => 64, 'source_id' => 100, 'leg' => 64] as $field => $max) {
                $this->need(is_string($leg[$field] ?? null)
                    && strlen($leg[$field]) <= $max
                    && preg_match('/\A[a-z0-9_-]+\z/', $leg[$field]) === 1, 'posting_source_identity');
            }
            $amount = $leg['amount_minor'] ?? null;
            // Zero invoices and zero opening balances still require identity evidence.
            // They are not nonzero movements and must be checked against actual sources.
            $zeroEvidence = ($leg['source_type'] === 'ledger_transaction' && $leg['leg'] === 'subscriber')
                || ($leg['source_type'] === 'treasury_opening' && $leg['leg'] === 'opening');
            $this->need(is_int($amount) && ($amount !== 0 || $zeroEvidence) && $amount !== PHP_INT_MIN, 'posting_minor');
            $this->need(($leg['currency'] ?? null) === 'YER' && is_string($leg['source_hash'] ?? null)
                && preg_match('/\A[0-9a-f]{64}\z/', $leg['source_hash']) === 1, 'posting_provenance');
            $key = $this->identity($leg);
            $this->need(! isset($result[$key]), 'duplicate_posting_leg');
            $result[$key] = ['source_type' => $leg['source_type'], 'source_id' => $leg['source_id'],
                'leg' => $leg['leg'], 'amount_minor' => (string) $amount, 'currency' => 'YER', 'source_hash' => $leg['source_hash']];
        }
        ksort($result, SORT_STRING);
        return array_values($result);
    }

    private function identity(array $leg): string
    {
        return $leg['source_type'].'|'.$leg['source_id'].'|'.$leg['leg'];
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) {
            throw new DomainException($reason);
        }
    }
}
