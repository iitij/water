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
        $prior = DB::table('financial_periods')->where('scope_key', 'water')
            ->where('next_on', $current['start'])->orderBy('id')->lockForUpdate()->get();
        $matched = 0;
        $priorHash = null;
        if ($prior->count() !== 1) {
            $issues[] = ['code' => 'independent_predecessor_required'];
        } else {
            $previous = $this->journal->inspect('water', (int) $prior[0]->id)['state'];
            if ($previous['state'] !== 'CLOSED') {
                $issues[] = ['code' => 'predecessor_not_closed'];
            } else {
                $previousSnapshot = $this->snapshot($previous);
                $priorHash = $previous['closure']['hash'];
                if ($snapshot['predecessor_hash'] !== $priorHash) {
                    $issues[] = ['code' => 'predecessor_hash_mismatch'];
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
            'opening_source_verified' => false, 'carry_continuity_verified' => false, 'closure_authorized' => false];
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
        }
        ksort($rows, SORT_STRING);
        return $rows;
    }
}
