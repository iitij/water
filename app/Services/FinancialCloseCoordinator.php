<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\FinancialClosePolicy;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Durable state-machine adapter. Inactive until a real trusted proof source,
 * complete writer integration and database protection have been reviewed.
 */
final class FinancialCloseCoordinator
{
    public function __construct(
        private readonly FinancialClosePolicy $policy,
        private readonly FinancialCloseProofSource $proof,
    ) {}

    /** Execute only from a trusted server-side caller, never request facts. */
    public function execute(string $scope, string $month, string $action, int $actorId, array $command, DateTimeImmutable $now): array
    {
        $this->need(DB::connection()->transactionLevel() === 0, 'close_requires_outer_gate');
        $this->need(preg_match('/\A[a-z0-9_-]{1,64}\z/', $scope) === 1 && $actorId > 0, 'close_identity');
        $allowed = match ($action) {
            'freeze' => ['reason', 'uuid', 'version'],
            'prepare' => ['attempt_uuid', 'uuid', 'version'],
            'approve' => ['attempt_uuid', 'snapshot_hash', 'uuid', 'version'],
            'abort' => ['attempt_uuid', 'reason', 'uuid', 'version'],
            default => throw new DomainException('close_action'),
        };
        ksort($command, SORT_STRING);
        $this->need(array_keys($command) === $allowed && is_int($command['version']) && $command['version'] >= 0
            && is_string($command['uuid'])
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $command['uuid']) === 1, 'close_command');
        $bytes = $this->encode(['format' => 1, 'scope' => $scope, 'month' => $month,
            'action' => $action, 'actor' => $actorId, 'command' => $command]);
        $hash = hash('sha256', $bytes);

        return DB::transaction(function () use ($scope, $month, $action, $actorId, $command, $now, $bytes, $hash): array {
            $gate = DB::table('financial_scopes')->where('scope_key', $scope)->lockForUpdate()->first();
            $this->need($gate && $gate->scope_key === $scope && $gate->mode === 'ACTIVE'
                && $gate->currency === 'YER' && $gate->timezone === 'Asia/Aden'
                && (string) $gate->calendar_version === '1', 'close_scope_inactive');
            $epoch = $this->counter($gate->fence_epoch);
            $base = $this->policy->open($scope, $month, $gate->timezone);
            $period = DB::table('financial_periods')->where('scope_key', $scope)
                ->where('month', $month)->lockForUpdate()->first();
            $this->need($period && $period->scope_key === $scope && $period->month === $month
                && $period->start_on === $base['start'] && $period->next_on === $base['next'], 'close_period_missing_or_invalid');
            $capability = $action === 'freeze' ? 'request' : $action;
            $this->need($this->proof->authorize($scope, $capability, $actorId), 'close_unauthorized');

            [$state, $previousHash, $history] = $this->load($period, $base);
            if ($state['state'] === 'FROZEN') {
                $this->need($gate->active_freeze_uuid === $state['attempt']['uuid']
                    && $epoch === $state['attempt']['epoch'], 'close_fence_corrupt');
            }
            $existing = DB::table('financial_close_commands')->where('command_uuid', $command['uuid'])->lockForUpdate()->first();
            if ($existing) {
                $this->need($existing->command_uuid === $command['uuid']
                    && (string) $existing->period_id === (string) $period->id
                    && $existing->command_bytes === $bytes && hash_equals($hash, $existing->command_hash)
                    && isset($history[$command['uuid']]), 'close_replay_conflict');
                if ($action === 'approve') {
                    $evidence = $this->proof->evidence('approve_replay', $state, $actorId);
                    $this->need(($evidence['facts']['persisted_effects_verified'] ?? null) === true
                        && $state['state'] === 'CLOSED', 'close_replay_effects_unverified');
                }
                return ['replayed' => true, 'result' => $history[$command['uuid']], 'current_state' => $state['state']];
            }

            $evidence = $this->proof->evidence($action, $state, $actorId);
            $this->need(is_array($evidence['facts'] ?? null), 'close_proof_missing');
            $facts = array_replace($evidence['facts'], [
                'scope' => $scope, 'authorized' => $capability, 'actor' => $actorId,
                'actor_active' => true, 'gate_held' => true, 'state_verified' => true,
                'scope_fenced' => $gate->active_freeze_uuid !== null,
                'epoch' => $epoch, 'request_unused' => true,
            ]);
            $next = match ($action) {
                'freeze' => $this->policy->freeze($state, $command, $facts, $now),
                'prepare' => $this->policy->prepare($state, $command, $facts, $evidence['snapshot'] ?? [], $now),
                'approve' => $this->policy->approve($state, $command, $facts, $now),
                'abort' => $this->policy->abort($state, $command, $facts, $now),
            };
            $this->need($next['version'] === $state['version'] + 1, 'close_version_did_not_advance');
            $resultBytes = $this->encode($next);
            DB::table('financial_close_commands')->insert([
                'command_uuid' => $command['uuid'], 'period_id' => $period->id,
                'action' => $action, 'actor_id' => $actorId, 'result_version' => $next['version'],
                'recorded_at_utc' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
                'previous_state_hash' => $previousHash, 'command_hash' => $hash, 'command_bytes' => $bytes,
                'result_state_hash' => hash('sha256', $resultBytes), 'result_state_bytes' => $resultBytes,
            ]);

            if ($action === 'approve') {
                $closure = $next['closure'];
                DB::table('financial_period_closures')->insert([
                    'period_id' => $period->id, 'approval_uuid' => $command['uuid'],
                    'closure_hash' => $closure['hash'], 'predecessor_hash' => $closure['snapshot']['predecessor_hash'],
                    'snapshot_hash' => $closure['approval']['snapshot_hash'], 'closure_bytes' => $this->encode($closure),
                ]);
            }

            $this->need(DB::table('financial_periods')->where('id', $period->id)
                ->where('version', $state['version'])->update(['state' => $next['state'], 'version' => $next['version']]) === 1,
                'close_period_update');
            if ($action !== 'prepare') {
                $nextEpoch = match ($action) {
                    'freeze' => $next['attempt']['epoch'],
                    'approve' => $next['closure']['release_epoch'],
                    'abort' => $next['aborted'][array_key_last($next['aborted'])]['release_epoch'],
                };
                $this->need(DB::table('financial_scopes')->where('scope_key', $scope)
                    ->where('fence_epoch', $epoch)->update([
                        'fence_epoch' => $nextEpoch,
                        'active_freeze_uuid' => $action === 'freeze' ? $next['attempt']['uuid'] : null,
                    ]) === 1, 'close_scope_update');
            }
            return ['replayed' => false, 'result' => $next, 'current_state' => $next['state']];
        }, 1);
    }

    /** Verify stored bytes, chain, version and closure projection before any transition. */
    private function load(object $period, array $base): array
    {
        $state = $base;
        $previousHash = hash('sha256', $this->encode($base));
        $history = [];
        $rows = DB::table('financial_close_commands')->where('period_id', $period->id)
            ->orderBy('result_version')->lockForUpdate()->get();
        foreach ($rows as $row) {
            $this->need(hash_equals($previousHash, $row->previous_state_hash)
                && hash_equals(hash('sha256', $row->command_bytes), $row->command_hash)
                && hash_equals(hash('sha256', $row->result_state_bytes), $row->result_state_hash), 'close_journal_hash');
            $cmd = json_decode($row->command_bytes, true, flags: JSON_THROW_ON_ERROR);
            $next = json_decode($row->result_state_bytes, true, flags: JSON_THROW_ON_ERROR);
            $this->need(is_array($cmd) && is_array($next)
                && $this->encode($cmd) === $row->command_bytes && $this->encode($next) === $row->result_state_bytes
                && ($cmd['format'] ?? null) === 1 && ($cmd['scope'] ?? null) === $base['scope']
                && ($cmd['month'] ?? null) === $base['month'] && ($cmd['action'] ?? null) === $row->action
                && (string) ($cmd['actor'] ?? '') === (string) $row->actor_id
                && ($cmd['command']['uuid'] ?? null) === $row->command_uuid
                && ($cmd['command']['version'] ?? null) === $state['version']
                && ($next['version'] ?? null) === $state['version'] + 1
                && (string) $next['version'] === (string) $row->result_version, 'close_journal_identity');
            foreach (['scope', 'month', 'timezone', 'start', 'next'] as $field) {
                $this->need(($next[$field] ?? null) === $base[$field], 'close_journal_period');
            }
            $transition = $state['state'].'>'.$next['state'];
            $this->need($transition === match ($row->action) {
                'freeze' => 'OPEN>FROZEN', 'prepare' => 'FROZEN>FROZEN',
                'approve' => 'FROZEN>CLOSED', 'abort' => 'FROZEN>OPEN',
                default => '',
            }, 'close_journal_transition');
            $state = $next;
            $previousHash = $row->result_state_hash;
            $history[$row->command_uuid] = $state;
        }
        $this->need($state['state'] === $period->state && $state['version'] === $this->counter($period->version), 'close_period_projection');
        $stored = DB::table('financial_period_closures')->where('period_id', $period->id)->lockForUpdate()->first();
        if ($state['state'] === 'CLOSED') {
            $closure = $state['closure'];
            $body = $closure;
            unset($body['hash']);
            $this->need($stored && $stored->approval_uuid === $closure['approval']['uuid']
                && $stored->closure_bytes === $this->encode($closure)
                && $stored->closure_hash === $closure['hash']
                && hash_equals(hash('sha256', $this->encode($body)), $closure['hash'])
                && $stored->predecessor_hash === $closure['snapshot']['predecessor_hash']
                && $stored->snapshot_hash === $closure['approval']['snapshot_hash']
                && hash_equals(hash('sha256', $this->encode($closure['snapshot'])), $stored->snapshot_hash), 'close_snapshot_corrupt');
        } else {
            $this->need($stored === null, 'close_unexpected_snapshot');
        }
        return [$state, $previousHash, $history];
    }

    private function counter(mixed $raw): int
    {
        $this->need(is_int($raw) || is_string($raw), 'close_counter');
        $digits = (string) $raw;
        $max = (string) PHP_INT_MAX;
        $this->need(preg_match('/\A(?:0|[1-9][0-9]*)\z/', $digits) === 1
            && (strlen($digits) < strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) <= 0)), 'close_counter');
        return (int) $digits;
    }

    private function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
