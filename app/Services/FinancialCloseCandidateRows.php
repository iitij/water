<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\SignedMinor;
use DomainException;

/** Diagnostic snapshot-shaped rows from reconciled recorded evidence.
 * No root certification, recoverable recognition, carry due dates or close authority.
 */
final class FinancialCloseCandidateRows
{
    public function inspect(array $accounts, array $outstanding, array $physical, array $held, array $subscriber): array
    {
        $base = ['covered_sectors' => ['allocation', 'bank', 'cash', 'custody', 'liability', 'subscriber', 'transit'],
            'uncovered_sectors' => ['recoverable'], 'independent_opening_verified' => false,
            'carry_evidence_verified' => false, 'closure_authorized' => false];
        if ($accounts['matched'] !== true || $physical['matched'] !== true || $held['matched'] !== true
            || $outstanding['transit']['recorded_rollforward_verified'] !== true
            || $outstanding['custody']['recorded_rollforward_verified'] !== true
            || $outstanding['liability']['recorded_rollforward_verified'] !== true
            || $subscriber['all_cutoff_accounts_match'] !== true || $subscriber['allocation_policy_verified'] !== true) {
            return $base + ['matched' => false, 'rows' => [], 'rows_hash' => null,
                'issues' => [['code' => 'candidate_rows_source_unverified']]];
        }
        $rows = [];
        $issues = [];
        $hash = static fn (array $source): string => hash('sha256', json_encode($source, JSON_THROW_ON_ERROR));
        $add = static function (string $sector, string $key, mixed $opening, mixed $movement, mixed $closing,
            string $sourceHash, array $extra = []) use (&$rows, &$issues): void {
            $id = $sector.':'.$key;
            $opening = SignedMinor::parse($opening);
            $movement = SignedMinor::parse($movement);
            $closing = SignedMinor::parse($closing);
            if (isset($rows[$id]) || ! preg_match('/\A[A-Za-z0-9_-]{1,100}\z/', $key)
                || ! preg_match('/\A[0-9a-f]{64}\z/', $sourceHash)) {
                $issues[] = ['code' => 'candidate_row_identity_invalid', 'row' => $id];
                return;
            }
            if (SignedMinor::add($opening, $movement) !== $closing
                || (! in_array($sector, ['subscriber', 'bank'], true) && min($opening, $closing) < 0)) {
                $issues[] = ['code' => 'candidate_row_conservation', 'row' => $id];
                return;
            }
            $rows[$id] = ['sector' => $sector, 'key' => $key, 'opening' => $opening, 'movement' => $movement,
                'closing' => $closing, 'source_hash' => $sourceHash] + $extra;
        };
        foreach ($accounts['rows'] as $row) {
            [$sector, $key] = explode(':', $row['account'], 2);
            if (! in_array($sector, ['cash', 'bank', 'subscriber'], true)) {
                $issues[] = ['code' => 'candidate_account_sector_invalid', 'row' => $row['account']];
                continue;
            }
            $add($sector, $key, $row['opening_minor'], $row['movement_minor'], $row['closing_minor'], $row['source_hash']);
        }
        foreach ($subscriber['rows'] as $row) {
            $key = (string) $row['subscriber_id'];
            $opening = SignedMinor::parseUnsigned($row['allocation_opening_available_minor']);
            $closing = SignedMinor::parseUnsigned($row['available_minor']);
            $allocated = SignedMinor::parseUnsigned($row['allocated_minor']);
            $surplus = SignedMinor::parseUnsigned($row['unallocated_minor']);
            if (SignedMinor::add($allocated, $surplus) !== $closing
                || SignedMinor::add(SignedMinor::parseUnsigned($row['allocation_opening_allocated_minor']),
                    SignedMinor::parseUnsigned($row['allocation_opening_unallocated_minor'])) !== $opening
                || ! isset($rows['subscriber:'.$key])
                || $rows['subscriber:'.$key]['opening'] !== $row['opening_minor']
                || $rows['subscriber:'.$key]['closing'] !== $row['closing_minor']
                || $rows['subscriber:'.$key]['source_hash'] !== $row['source_hash']) {
                $issues[] = ['code' => 'candidate_allocation_source_mismatch', 'row' => 'allocation:'.$key];
                continue;
            }
            $add('allocation', $key, $opening, SignedMinor::subtract($closing, $opening), $closing, $row['source_hash'],
                ['allocated' => $allocated, 'surplus' => $surplus]);
        }
        foreach ($rows as $row) {
            if ($row['sector'] === 'subscriber' && ! isset($rows['allocation:'.$row['key']])) {
                $issues[] = ['code' => 'candidate_allocation_row_missing', 'row' => 'allocation:'.$row['key']];
            }
        }
        foreach ($outstanding['transit']['rows'] as $row) {
            $add('transit', (string) $row['transfer_id'], $row['opening_minor'],
                SignedMinor::subtract($row['dispatched_minor'], $row['received_minor']), $row['closing_minor'], $hash($row));
        }
        foreach ($outstanding['custody']['rows'] as $row) {
            $add('custody', (string) $row['session_id'], $row['opening_minor'],
                SignedMinor::subtract($row['recognized_counted_minor'], $row['received_minor']), $row['closing_minor'], $hash($row));
        }
        foreach ($outstanding['liability']['rows'] as $lot) {
            $add('liability', 'lot-'.$lot['liability_id'], $lot['recorded_opening_unidentified_minor'],
                SignedMinor::subtract($lot['recognized_minor'], $lot['assigned_minor']),
                $lot['recorded_closing_unidentified_minor'], $hash($lot));
            foreach ($lot['owners'] as $owner) {
                $add('liability', 'assignment-'.$owner['assignment_uuid'], $owner['recorded_opening_minor'],
                    SignedMinor::subtract($owner['assigned_minor'], SignedMinor::add($owner['paid_minor'], $owner['credited_minor'])),
                    $owner['recorded_closing_minor'], $hash(['liability_id' => $lot['liability_id'], 'account_id' => $lot['account_id'], 'owner' => $owner]));
            }
        }
        ksort($rows, SORT_STRING);
        $rows = $issues === [] ? array_values($rows) : [];
        return $base + ['matched' => $issues === [], 'rows' => $rows, 'rows_hash' => $issues === [] ? $hash($rows) : null,
            'issues' => $issues];
    }
}
