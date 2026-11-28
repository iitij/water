<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\SignedMinor;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Verify adjacent persisted snapshots, not the correctness of the root
 * opening or the underlying source evidence. Never a close proof source.
 * A missing predecessor is not an implied zero opening. New zero-opening
 * keys are only structurally consistent; their actual birth still needs proof.
 */
final class FinancialOpeningContinuityDiagnostic
{
    public function __construct(private FinancialCloseJournalReader $journal)
    {
    }

    public function inspect(int $periodId): array
    {
        if (DB::transactionLevel() === 0) throw new DomainException('opening_continuity_requires_gate');
        $current = $this->journal->inspect('water', $periodId)['state'];
        $snapshot = $this->snapshot($current);
        $issues = [];
        $carryIssues = [];
        $unchangedCarries = 0;
        $currentCarries = $this->carries($snapshot, $current['next']);
        $prior = DB::table('financial_periods')->where('scope_key', 'water')
            ->where('next_on', $current['start'])->orderBy('id')->lockForUpdate()->get();
        $matched = 0;
        $priorHash = null;
        if ($prior->count() !== 1) {
            $issues[] = ['code' => 'independent_predecessor_required'];
            $carryIssues[] = ['code' => 'carry_predecessor_required'];
        } else {
            $previous = $this->journal->inspect('water', (int) $prior[0]->id)['state'];
            if ($previous['state'] !== 'CLOSED') {
                $issues[] = ['code' => 'predecessor_not_closed'];
                $carryIssues[] = ['code' => 'carry_predecessor_not_closed'];
            } else {
                $previousSnapshot = $this->snapshot($previous);
                $priorHash = $previous['closure']['hash'];
                if ($snapshot['predecessor_hash'] !== $priorHash) {
                    $issues[] = ['code' => 'predecessor_hash_mismatch'];
                }
                $priorCarries = $this->carries($previousSnapshot, $previous['next']);
                foreach ($priorCarries as $id => $carry) {
                    if (! isset($currentCarries[$id])) {
                        $carryIssues[] = ['code' => 'carry_settlement_or_removal_requires_proof', 'id' => $id];
                        continue;
                    }
                    $changes = [];
                    foreach ($carry as $field => $value) {
                        if ($currentCarries[$id][$field] !== $value) $changes[] = $field;
                    }
                    if ($changes !== []) {
                        $carryIssues[] = ['code' => 'carry_change_requires_proof', 'id' => $id, 'fields' => $changes];
                        continue;
                    }
                    $unchangedCarries++;
                }
                foreach ($currentCarries as $id => $carry) {
                    if (! isset($priorCarries[$id])) {
                        $carryIssues[] = ['code' => 'new_carry_requires_proof', 'id' => $id];
                    }
                }
                $before = $this->rows($previousSnapshot);
                $after = $this->rows($snapshot);
                foreach ($before as $key => $row) {
                    // No disappearing keys, even when their balance is zero:
                    // retirement requires a separate, explicit source policy.
                    if (! isset($after[$key])) {
                        $issues[] = ['code' => 'predecessor_row_missing', 'row' => $key];
                        continue;
                    }
                    if ($row['closing'] !== $after[$key]['opening']) {
                        $issues[] = ['code' => 'opening_balance_mismatch', 'row' => $key,
                            'expected_minor' => $row['closing'], 'actual_minor' => $after[$key]['opening']];
                        continue;
                    }
                    $matched++;
                }
                foreach ($after as $key => $row) {
                    if (! isset($before[$key]) && $row['opening'] !== 0) {
                        $issues[] = ['code' => 'new_row_nonzero_opening', 'row' => $key,
                            'actual_minor' => $row['opening']];
                    }
                }
            }
        }
        return ['period_id' => $periodId, 'month' => $current['month'], 'predecessor_hash' => $priorHash,
            'matched_predecessor_rows' => $matched, 'issues' => $issues,
            'adjacent_snapshots_match' => $issues === [], 'root_opening_verified' => false,
            'opening_source_verified' => false,
            'carry_snapshot_issues' => $carryIssues, 'unchanged_carries' => $unchangedCarries,
            'carry_snapshots_unchanged' => $issues === [] && $carryIssues === [],
            'carry_continuity_verified' => false, 'closure_authorized' => false];
    }

    /** Read predecessor rows before preparation, without inventing a root opening.
     * Journal integrity establishes the saved balance, not its source certification.
     */
    public function predecessor(int $periodId): array
    {
        if (DB::transactionLevel() === 0) throw new DomainException('opening_continuity_requires_gate');
        DB::table('financial_scopes')->where('scope_key', 'water')->lockForUpdate()->sole();
        $current = DB::table('financial_periods')->where('scope_key', 'water')->where('id', $periodId)->lockForUpdate()->sole();
        $prior = DB::table('financial_periods')->where('scope_key', 'water')
            ->where('next_on', $current->start_on)->orderBy('id')->lockForUpdate()->get();
        $base = ['period_id' => $periodId, 'opening_source_verified' => false, 'closure_authorized' => false];
        if ($prior->count() !== 1 || $prior[0]->state !== 'CLOSED') {
            return $base + ['predecessor_journal_verified' => false, 'predecessor_hash' => null,
                'rows' => [], 'carries' => [], 'issues' => [['code' => $prior->count() !== 1
                    ? 'independent_predecessor_required' : 'predecessor_not_closed']]];
        }
        $previous = $this->journal->inspect('water', (int) $prior[0]->id)['state'];
        $snapshot = $this->snapshot($previous);
        return $base + ['predecessor_journal_verified' => true, 'predecessor_hash' => $previous['closure']['hash'],
            'rows' => $this->rows($snapshot), 'carries' => $this->carries($snapshot, $previous['next']), 'issues' => []];
    }

    private function snapshot(array $state): array
    {
        $snapshot = $state['state'] === 'CLOSED' ? ($state['closure']['snapshot'] ?? null)
            : ($state['attempt']['prepared']['snapshot'] ?? null);
        $hash = $state['state'] === 'CLOSED' ? ($state['closure']['approval']['snapshot_hash'] ?? null)
            : ($state['attempt']['prepared']['hash'] ?? null);
        if (! is_array($snapshot) || ! is_string($hash)
            || ! hash_equals(hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), $hash)
            || ($snapshot['scope'] ?? null) !== $state['scope']
            || ($snapshot['month'] ?? null) !== $state['month']
            || ($snapshot['timezone'] ?? null) !== $state['timezone']
            || ! is_string($snapshot['predecessor_hash'] ?? null)
            || preg_match('/\A[0-9a-f]{64}\z/', $snapshot['predecessor_hash']) !== 1) {
            throw new DomainException('opening_snapshot_missing_or_invalid');
        }
        $this->rows($snapshot);
        return $snapshot;
    }

    /** Verify carry identity, amount, dates and exact per-row conservation.
     * Structural validity does not prove payment, renewal or new custody.
     */
    private function carries(array $snapshot, string $next): array
    {
        if (! is_array($snapshot['carries'] ?? null) || ! array_is_list($snapshot['carries'])) {
            throw new DomainException('carry_snapshot_invalid');
        }
        $rows = $this->rows($snapshot);
        $carries = [];
        $totals = [];
        $sectors = ['custody', 'liability', 'recoverable', 'transit'];
        foreach ($snapshot['carries'] as $carry) {
            if (! is_array($carry) || ! is_string($carry['id'] ?? null) || $carry['id'] === ''
                || isset($carries[$carry['id']]) || ! is_string($carry['row'] ?? null)
                || ! isset($rows[$carry['row']]) || ! in_array(explode(':', $carry['row'], 2)[0], $sectors, true)
                || ! is_string($carry['amount'] ?? null) || ! is_int($carry['owner'] ?? null) || $carry['owner'] <= 0
                || ! is_string($carry['due_on'] ?? null) || ! is_string($carry['evidence_hash'] ?? null)
                || preg_match('/\A[0-9a-f]{64}\z/', $carry['evidence_hash']) !== 1) {
                throw new DomainException('carry_snapshot_invalid');
            }
            $due = \DateTimeImmutable::createFromFormat('!Y-m-d', $carry['due_on'], new \DateTimeZone('Asia/Aden'));
            $amount = SignedMinor::parse($carry['amount']);
            if ($amount <= 0 || ! $due || $due->format('Y-m-d') !== $carry['due_on'] || $carry['due_on'] < $next) {
                throw new DomainException('carry_snapshot_invalid');
            }
            $carries[$carry['id']] = ['row' => $carry['row'], 'amount' => $amount,
                'owner' => $carry['owner'], 'due_on' => $carry['due_on'], 'evidence_hash' => $carry['evidence_hash']];
            $totals[$carry['row']] = SignedMinor::add($totals[$carry['row']] ?? 0, $amount);
        }
        foreach ($rows as $key => $row) {
            if (in_array(explode(':', $key, 2)[0], $sectors, true) && ($totals[$key] ?? 0) !== $row['closing']) {
                throw new DomainException('carry_snapshot_conservation');
            }
        }
        ksort($carries, SORT_STRING);
        return $carries;
    }

    private function rows(array $snapshot): array
    {
        if (! is_array($snapshot['rows'] ?? null) || ! array_is_list($snapshot['rows'])) {
            throw new DomainException('opening_snapshot_rows_invalid');
        }
        $rows = [];
        foreach ($snapshot['rows'] as $row) {
            if (! is_array($row) || ! in_array($row['sector'] ?? null,
                ['allocation', 'bank', 'cash', 'custody', 'liability', 'recoverable', 'subscriber', 'transit'], true)
                || ! is_string($row['key'] ?? null) || $row['key'] === ''
                || ! is_string($row['opening'] ?? null) || ! is_string($row['movement'] ?? null)
                || ! is_string($row['closing'] ?? null)) {
                throw new DomainException('opening_snapshot_rows_invalid');
            }
            $key = $row['sector'].':'.$row['key'];
            if (isset($rows[$key])) throw new DomainException('opening_snapshot_rows_invalid');
            $opening = SignedMinor::parse($row['opening']);
            $movement = SignedMinor::parse($row['movement']);
            $closing = SignedMinor::parse($row['closing']);
            if (SignedMinor::add($opening, $movement) !== $closing) {
                throw new DomainException('opening_snapshot_conservation');
            }
            $rows[$key] = ['opening' => $opening, 'closing' => $closing];
            if ($row['sector'] === 'allocation') {
                if (! is_string($row['allocated'] ?? null) || ! is_string($row['surplus'] ?? null)) {
                    throw new DomainException('opening_snapshot_allocation_invalid');
                }
                $allocated = SignedMinor::parseUnsigned($row['allocated']);
                $surplus = SignedMinor::parseUnsigned($row['surplus']);
                if ($opening < 0 || SignedMinor::add($allocated, $surplus) !== $closing) {
                    throw new DomainException('opening_snapshot_allocation_conservation');
                }
                $rows[$key] += ['allocated' => $allocated, 'surplus' => $surplus];
            }
        }
        ksort($rows, SORT_STRING);
        return $rows;
    }
}
