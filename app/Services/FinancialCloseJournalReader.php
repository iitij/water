<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\FinancialClosePolicy;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Shared durable journal projection reader. Integrity is not proof of source
 * coverage or independent opening balances. Scope-first lock is mandatory.
 */
final class FinancialCloseJournalReader
{
    public function inspect(string $scope, int $periodId): array
    {
        $this->need(DB::transactionLevel() > 0, 'close_read_requires_gate');
        $gate = DB::table('financial_scopes')->where('scope_key', $scope)->lockForUpdate()->first();
        $this->need($gate && $gate->scope_key === $scope && $gate->mode === 'ACTIVE'
            && $gate->currency === 'YER' && $gate->timezone === 'Asia/Aden'
            && (string) $gate->calendar_version === '1', 'close_scope_inactive');
        $period = DB::table('financial_periods')->where('scope_key', $scope)->where('id', $periodId)->lockForUpdate()->first();
        $this->need($period !== null, 'close_period_missing_or_invalid');
        $base = app(FinancialClosePolicy::class)->open($scope, $period->month, $gate->timezone);
        $this->need($period->start_on === $base['start'] && $period->next_on === $base['next'], 'close_period_missing_or_invalid');
        [$state, $hash, $history] = $this->load($period, $base);
        if ($state['state'] === 'FROZEN') {
            $this->need($gate->active_freeze_uuid === $state['attempt']['uuid']
                && $this->counter($gate->fence_epoch) === $state['attempt']['epoch'], 'close_fence_corrupt');
        }
        return ['state' => $state, 'journal_hash' => $hash, 'commands' => count($history)];
    }

    /** Verify stored bytes, chain, version and closure projection before any transition. */
    public function load(object $period, array $base): array
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
