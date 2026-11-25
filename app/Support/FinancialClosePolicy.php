<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/** Pure closing policy. All state and facts MUST originate from the future trusted adapter. */
final class FinancialClosePolicy
{
    private const SECTORS = ['allocation', 'bank', 'cash', 'custody', 'liability', 'recoverable', 'subscriber', 'transit'];
    private const CARRY = ['custody', 'liability', 'recoverable', 'transit'];

    public function open(string $scope, string $month, string $timezone): array
    {
        $this->need(PHP_INT_SIZE === 8, 'integer_platform');
        $this->need(preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $scope) === 1, 'scope');
        $this->need(preg_match('/\A[1-9][0-9]{3}-(0[1-9]|1[0-2])\z/', $month) === 1 && $month !== '9999-12', 'month');
        $this->need(in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL), true) || $timezone === 'UTC', 'timezone');
        $start = new DateTimeImmutable($month.'-01', new DateTimeZone($timezone));
        return ['scope' => $scope, 'month' => $month, 'timezone' => $timezone,
            'start' => $start->format('Y-m-d'), 'next' => $start->modify('+1 month')->format('Y-m-d'),
            'state' => 'OPEN', 'version' => 0, 'attempt' => null, 'closure' => null, 'aborted' => []];
    }

    /** Check a single destination, not the aggregate report range. */
    public function assertStorageMinor(string $field, mixed $minor): void
    {
        $this->need(PHP_INT_SIZE === 8, 'integer_platform');
        $limit = match ($field) {
            'meter_cache', 'tariff_rate' => 9999999999,
            'document', 'subscriber_cache' => 999999999999,
            'cash_session' => 99999999999999,
            'treasury_posting' => PHP_INT_MAX,
            default => throw new DomainException('unknown_field'),
        };
        $minor = $this->minor($minor);
        $this->need($minor >= -$limit && $minor <= $limit && ($field !== 'tariff_rate' || $minor >= 0), 'storage_bound');
    }

    /** A missing period, stale state, or scope-wide freeze is never treated as open. */
    public function assertCanPost(?array $period, string $effectiveOn, array $facts, DateTimeImmutable $now): void
    {
        $this->need($period !== null, 'missing_period');
        $this->context($period, $facts, 'post');
        $this->need($period['state'] === 'OPEN' && ($facts['scope_fenced'] ?? null) === false, 'period_not_open');
        $this->date($effectiveOn);
        $this->need($effectiveOn >= $period['start'] && $effectiveOn < $period['next']
            && $effectiveOn <= $now->setTimezone(new DateTimeZone($period['timezone']))->format('Y-m-d'), 'effective_date');
    }

    public function freeze(array $period, array $command, array $facts, DateTimeImmutable $now): array
    {
        $this->context($period, $facts, 'request');
        $this->version($period, $command);
        $this->need($period['state'] === 'OPEN' && ($facts['scope_fenced'] ?? null) === false, 'period_not_open');
        $this->need($now->setTimezone(new DateTimeZone($period['timezone']))->format('Y-m-d') >= $period['next'], 'period_not_ended');
        $this->need(($facts['predecessor_verified'] ?? null) === true && ($facts['writers_drained'] ?? null) === true, 'predecessor_or_drain');
        $this->need(($facts['request_unused'] ?? null) === true, 'request_used');
        $uuid = $this->uuid($command['uuid'] ?? null);
        $actor = $this->actor($facts);
        $epoch = $this->increment($this->counter($facts['epoch'] ?? null));
        $period['attempt'] = ['uuid' => $uuid, 'requester' => $actor, 'reason' => $this->reason($command['reason'] ?? null),
            'frozen_at' => $this->instant($now), 'epoch' => $epoch, 'predecessor_hash' => $this->digest($facts['predecessor_hash'] ?? null),
            'prepared' => null];
        $period['state'] = 'FROZEN';
        $period['version'] = $this->increment($period['version']);
        return $period;
    }

    public function prepare(array $period, array $command, array $facts, array $snapshot, DateTimeImmutable $now): array
    {
        $this->frozen($period, $command, $facts, 'prepare');
        $this->need($period['attempt']['prepared'] === null, 'already_prepared');
        $this->need($this->instant($now) >= $period['attempt']['frozen_at'], 'event_time');
        $canonical = $this->snapshot($period, $snapshot, $facts);
        $period['attempt']['prepared'] = ['actor' => $this->actor($facts), 'at' => $this->instant($now),
            'snapshot' => $canonical, 'hash' => $this->hash($canonical)];
        $period['version'] = $this->increment($period['version']);
        return $period;
    }

    public function approve(array $period, array $command, array $facts, DateTimeImmutable $now): array
    {
        $this->context($period, $facts, 'approve');
        $actor = $this->actor($facts);
        $payload = ['uuid' => $this->uuid($command['uuid'] ?? null), 'attempt_uuid' => $this->uuid($command['attempt_uuid'] ?? null),
            'snapshot_hash' => $this->digest($command['snapshot_hash'] ?? null), 'version' => $this->counter($command['version'] ?? null), 'actor' => $actor];
        if ($period['state'] === 'CLOSED') {
            $closure = $period['closure'];
            $this->need(($facts['persisted_effects_verified'] ?? null) === true && $closure['approval'] === $payload
                && $closure['snapshot']['scope'] === $period['scope'] && $closure['snapshot']['month'] === $period['month']
                && $closure['snapshot']['timezone'] === $period['timezone'] && $this->hash($closure['snapshot']) === $payload['snapshot_hash']
                && $this->hash($this->closureBody($closure)) === $closure['hash'], 'close_replay_conflict');
            return $period;
        }
        $this->frozen($period, $command, $facts, 'approve');
        $attempt = $period['attempt'];
        $prepared = $attempt['prepared'];
        $this->need(is_array($prepared), 'not_prepared');
        $this->need($actor !== $attempt['requester'] && $actor !== $prepared['actor'], 'self_approval');
        $this->need(($facts['request_unused'] ?? null) === true && $payload['uuid'] !== $attempt['uuid'], 'request_used');
        $this->need($this->instant($now) >= $prepared['at'], 'event_time');
        $this->need($payload['snapshot_hash'] === $prepared['hash'] && $this->hash($prepared['snapshot']) === $prepared['hash']
            && ($facts['snapshot_verified'] ?? null) === true && ($facts['snapshot_hash'] ?? null) === $prepared['hash']
            && ($facts['manifest_hash'] ?? null) === $prepared['snapshot']['manifest_hash'], 'snapshot_changed');
        $this->need(($facts['predecessor_verified'] ?? null) === true && ($facts['predecessor_hash'] ?? null) === $attempt['predecessor_hash'], 'predecessor_changed');
        $closure = ['approval' => $payload, 'snapshot' => $prepared['snapshot'], 'closed_at' => $this->instant($now),
            'requester' => $attempt['requester'], 'reason' => $attempt['reason'], 'frozen_at' => $attempt['frozen_at'],
            'prepared_by' => $prepared['actor'], 'prepared_at' => $prepared['at'], 'release_epoch' => $this->increment($attempt['epoch'])];
        $closure['hash'] = $this->hash($closure);
        $period['closure'] = $closure;
        $period['attempt'] = null;
        $period['state'] = 'CLOSED';
        $period['version'] = $this->increment($period['version']);
        return $period;
    }

    public function abort(array $period, array $command, array $facts, DateTimeImmutable $now): array
    {
        $this->frozen($period, $command, $facts, 'abort');
        $attempt = $period['attempt'];
        $actor = $this->actor($facts);
        $this->need($actor !== $attempt['requester'] && $actor !== ($attempt['prepared']['actor'] ?? null), 'self_approval');
        $this->need(($facts['request_unused'] ?? null) === true, 'request_used');
        $uuid = $this->uuid($command['uuid'] ?? null);
        $this->need($uuid !== $attempt['uuid'], 'request_used');
        $this->need($this->instant($now) >= ($attempt['prepared']['at'] ?? $attempt['frozen_at']), 'event_time');
        $period['aborted'][] = ['attempt' => $attempt, 'uuid' => $uuid, 'actor' => $actor,
            'reason' => $this->reason($command['reason'] ?? null), 'at' => $this->instant($now), 'release_epoch' => $this->increment($attempt['epoch'])];
        $period['attempt'] = null;
        $period['state'] = 'OPEN';
        $period['version'] = $this->increment($period['version']);
        return $period;
    }

    private function snapshot(array $period, array $input, array $facts): array
    {
        $this->need(($facts['coverage_verified'] ?? null) === true && ($facts['no_unexplained_variance'] ?? null) === true
            && ($facts['carry_evidence_verified'] ?? null) === true, 'incomplete_coverage');
        $manifest = $this->digest($input['manifest_hash'] ?? null);
        $this->need($manifest === ($facts['manifest_hash'] ?? null), 'manifest_changed');
        $coverage = $input['coverage'] ?? null;
        $this->need(is_array($coverage), 'coverage');
        ksort($coverage, SORT_STRING);
        $this->need(array_keys($coverage) === self::SECTORS, 'coverage');
        foreach ($coverage as $verified) $this->need($verified === true, 'coverage');
        $this->need(is_array($input['rows'] ?? null) && array_is_list($input['rows'])
            && is_array($input['carries'] ?? null) && array_is_list($input['carries']), 'snapshot_rows');
        $rows = $totals = $carrySums = $carries = [];
        $debt = $credit = 0;
        foreach (self::SECTORS as $sector) $totals[$sector] = ['opening' => 0, 'movement' => 0, 'closing' => 0];
        foreach ($input['rows'] as $row) {
            $this->need(is_array($row) && in_array($row['sector'] ?? null, self::SECTORS, true), 'sector');
            $sector = $row['sector'];
            $id = $this->key($row['key'] ?? null);
            $key = $sector.':'.$id;
            $this->need(!isset($rows[$key]), 'duplicate_row');
            $opening = $this->minor($row['opening'] ?? null);
            $movement = $this->minor($row['movement'] ?? null);
            $closing = $this->minor($row['closing'] ?? null);
            $this->need($this->add($opening, $movement) === $closing, 'conservation');
            if (!in_array($sector, ['subscriber', 'bank'], true)) $this->need($opening >= 0 && $closing >= 0, 'negative_balance');
            $rows[$key] = ['sector' => $sector, 'key' => $id, 'opening' => (string) $opening, 'movement' => (string) $movement,
                'closing' => (string) $closing, 'source_hash' => $this->digest($row['source_hash'] ?? null)];
            if ($sector === 'allocation') {
                $allocated = $this->minor($row['allocated'] ?? null);
                $surplus = $this->minor($row['surplus'] ?? null);
                $this->need($allocated >= 0 && $surplus >= 0 && $this->add($allocated, $surplus) === $closing, 'allocation_conservation');
                $rows[$key]['allocated'] = (string) $allocated;
                $rows[$key]['surplus'] = (string) $surplus;
            }
        }
        ksort($rows, SORT_STRING);
        foreach ($rows as $row) {
            if ($row['sector'] === 'subscriber') {
                $debt = $this->add($debt, max(0, -(int) $row['closing']));
                $credit = $this->add($credit, max(0, (int) $row['closing']));
            }
            foreach (['opening', 'movement', 'closing'] as $field) {
                $sector = $row['sector'];
                $totals[$sector][$field] = $this->add($totals[$sector][$field], (int) $row[$field]);
            }
        }
        foreach ($input['carries'] as $carry) {
            $this->need(is_array($carry) && in_array($carry['sector'] ?? null, self::CARRY, true), 'carry_sector');
            $id = $this->key($carry['id'] ?? null);
            $rowKey = $carry['sector'].':'.$this->key($carry['row_key'] ?? null);
            $amount = $this->minor($carry['amount'] ?? null);
            $this->need(!isset($carries[$id]) && isset($rows[$rowKey]) && $amount > 0, 'carry_identity_or_amount');
            $due = $this->date($carry['due_on'] ?? null);
            $this->need($due >= $period['next'], 'carry_overdue');
            $carrySums[$rowKey] = $this->add($carrySums[$rowKey] ?? 0, $amount);
            $carries[$id] = ['id' => $id, 'row' => $rowKey, 'amount' => (string) $amount,
                'owner' => $this->positive($carry['owner'] ?? null), 'due_on' => $due, 'evidence_hash' => $this->digest($carry['evidence_hash'] ?? null)];
        }
        foreach ($rows as $key => $row) {
            if (in_array($row['sector'], self::CARRY, true)) $this->need(($carrySums[$key] ?? 0) === (int) $row['closing'], 'carry_not_conserved');
        }
        ksort($carries, SORT_STRING);
        foreach ($totals as &$total) foreach ($total as &$value) $value = (string) $value;
        unset($total, $value);
        return ['format' => 1, 'scope' => $period['scope'], 'month' => $period['month'], 'timezone' => $period['timezone'],
            'attempt_uuid' => $period['attempt']['uuid'], 'epoch' => $period['attempt']['epoch'],
            'predecessor_hash' => $period['attempt']['predecessor_hash'], 'manifest_hash' => $manifest,
            'coverage' => $coverage, 'rows' => array_values($rows), 'carries' => array_values($carries), 'totals' => $totals,
            'subscriber_debt' => (string) $debt, 'subscriber_credit' => (string) $credit];
    }

    private function context(array $p, array $facts, string $capability): void
    {
        $this->need(is_string($p['scope'] ?? null) && is_string($p['month'] ?? null) && is_string($p['timezone'] ?? null)
            && array_key_exists('attempt', $p) && array_key_exists('closure', $p), 'invalid_state');
        $base = $this->open($p['scope'], $p['month'], $p['timezone']);
        $this->need(($p['start'] ?? null) === $base['start'] && ($p['next'] ?? null) === $base['next'] && in_array($p['state'] ?? null, ['OPEN', 'FROZEN', 'CLOSED'], true)
            && is_array($p['aborted'] ?? null) && ($p['state'] === 'FROZEN' ? is_array($p['attempt']) : $p['attempt'] === null)
            && ($p['state'] === 'CLOSED' ? is_array($p['closure']) : $p['closure'] === null), 'invalid_state');
        $this->counter($p['version'] ?? null);
        $this->need(($facts['gate_held'] ?? null) === true && ($facts['state_verified'] ?? null) === true
            && ($facts['scope'] ?? null) === $p['scope'] && ($facts['authorized'] ?? null) === $capability
            && ($facts['actor_active'] ?? null) === true, 'untrusted_context');
        $this->actor($facts);
    }

    private function frozen(array $p, array $command, array $facts, string $capability): void
    {
        $this->context($p, $facts, $capability);
        $this->version($p, $command);
        $this->need($p['state'] === 'FROZEN' && ($facts['scope_fenced'] ?? null) === true, 'not_frozen');
        $this->need(($command['attempt_uuid'] ?? null) === $p['attempt']['uuid'] && ($facts['epoch'] ?? null) === $p['attempt']['epoch'], 'stale_attempt');
    }

    private function version(array $p, array $c): void { $this->need(($c['version'] ?? null) === $p['version'], 'stale_version'); }
    private function actor(array $f): int { return $this->positive($f['actor'] ?? null); }
    private function positive(mixed $v): int { $this->need(is_int($v) && $v > 0, 'positive_integer'); return $v; }
    private function counter(mixed $v): int { $this->need(is_int($v) && $v >= 0, 'counter'); return $v; }
    private function increment(int $v): int { $this->need($v < PHP_INT_MAX, 'counter_overflow'); return $v + 1; }
    private function minor(mixed $v): int { $this->need(is_int($v) && $v !== PHP_INT_MIN, 'minor_integer'); return $v; }
    private function add(int $a, int $b): int
    {
        $this->need(!($b > 0 && $a > PHP_INT_MAX - $b) && !($b < 0 && $a < -PHP_INT_MAX - $b), 'aggregate_overflow');
        return $a + $b;
    }
    private function date(mixed $v): string
    {
        $this->need(is_string($v) && preg_match('/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}\z/', $v) === 1
            && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4)), 'date');
        return $v;
    }
    private function key(mixed $v): string { $this->need(is_string($v) && preg_match('/\A[A-Za-z0-9_-]{1,100}\z/', $v) === 1, 'key'); return $v; }
    private function uuid(mixed $v): string { $this->need(is_string($v) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $v) === 1, 'uuid'); return $v; }
    private function digest(mixed $v): string { $this->need(is_string($v) && preg_match('/\A[0-9a-f]{64}\z/', $v) === 1, 'digest'); return $v; }
    private function reason(mixed $v): string { $this->need(is_string($v) && trim($v) === $v && strlen($v) >= 10 && strlen($v) <= 2000 && preg_match('//u', $v) === 1, 'reason'); return $v; }
    private function instant(DateTimeImmutable $v): string { return $v->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'); }
    private function hash(array $v): string { return hash('sha256', json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)); }
    private function closureBody(array $v): array { unset($v['hash']); return $v; }
    private function need(bool $ok, string $code): void { if (!$ok) throw new DomainException($code); }
}
