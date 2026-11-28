<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\SignedMinor;

/** Compare source-derived openings to a structurally verified predecessor.
 * No root balance adoption, no carry due-date proof, no close authorization.
 */
final class FinancialPredecessorOpeningReconciliation
{
    private const SECTORS = ['allocation', 'bank', 'cash', 'custody', 'liability', 'subscriber', 'transit'];

    public function inspect(array $predecessor, array $accounts, array $outstanding,
        array $physical, array $held, array $subscriber): array
    {
        $base = ['predecessor_hash' => $predecessor['predecessor_hash'],
            'covered_sectors' => self::SECTORS, 'uncovered_sectors' => ['recoverable'],
            'matched' => false, 'matched_rows' => [], 'new_zero_opening_rows' => [],
            'issues' => [], 'independent_opening_verified' => false, 'closure_authorized' => false];
        if ($predecessor['predecessor_journal_verified'] !== true) {
            $base['issues'][] = ['code' => 'opening_predecessor_journal_unverified'];
            return $base;
        }

        if ($accounts['matched'] !== true || $physical['matched'] !== true || $held['matched'] !== true
            || $outstanding['liability']['recorded_rollforward_verified'] !== true
            || $subscriber['all_cutoff_accounts_match'] !== true || $subscriber['allocation_policy_verified'] !== true) {
            $base['issues'][] = ['code' => 'opening_current_source_unverified'];
            return $base;
        }

        $openings = $allocationSplits = [];
        $add = static function (string $key, mixed $minor) use (&$openings, &$base): void {
            if (array_key_exists($key, $openings)) {
                $base['issues'][] = ['code' => 'opening_duplicate_source', 'row' => $key];
                return;
            }
            $openings[$key] = SignedMinor::parse($minor);
        };
        foreach ($accounts['rows'] as $row) {
            $add($row['account'], $row['opening_minor']);
        }
        foreach ($outstanding['transit']['rows'] as $row) {
            $add('transit:'.$row['transfer_id'], $row['opening_minor']);
        }
        foreach ($outstanding['custody']['rows'] as $row) {
            $add('custody:'.$row['session_id'], $row['opening_minor']);
        }
        foreach ($outstanding['liability']['rows'] as $row) {
            $add('liability:lot-'.$row['liability_id'], $row['recorded_opening_unidentified_minor']);
            foreach ($row['owners'] as $owner) {
                $add('liability:assignment-'.$owner['assignment_uuid'], $owner['recorded_opening_minor']);
            }
        }
        foreach ($subscriber['rows'] as $row) {
            $key = 'allocation:'.$row['subscriber_id'];
            $available = SignedMinor::parseUnsigned($row['allocation_opening_available_minor']);
            $allocated = SignedMinor::parseUnsigned($row['allocation_opening_allocated_minor']);
            $surplus = SignedMinor::parseUnsigned($row['allocation_opening_unallocated_minor']);
            if (SignedMinor::add($allocated, $surplus) !== $available) {
                $base['issues'][] = ['code' => 'opening_allocation_split_invalid', 'row' => $key];
                continue;
            }
            $add($key, $available);
            $allocationSplits[$key] = ['allocated' => $allocated, 'surplus' => $surplus];
        }
        // Pending payout authority is a subset, not a second liability row.
        $saved = array_filter($predecessor['rows'], static fn ($key) =>
            in_array(explode(':', $key, 2)[0], self::SECTORS, true), ARRAY_FILTER_USE_KEY);
        ksort($saved, SORT_STRING);
        ksort($openings, SORT_STRING);
        foreach ($saved as $key => $row) {
            if (! array_key_exists($key, $openings)) {
                $base['issues'][] = ['code' => 'opening_predecessor_row_missing', 'row' => $key];
                continue;
            }
            $closing = SignedMinor::parse($row['closing']);
            if ($closing !== $openings[$key]) {
                $base['issues'][] = ['code' => 'opening_predecessor_balance_mismatch', 'row' => $key,
                    'expected_minor' => $closing, 'actual_minor' => $openings[$key]];
                continue;
            }
            if (isset($allocationSplits[$key])) {
                $split = $allocationSplits[$key];
                if (! isset($row['allocated'], $row['surplus'])
                    || SignedMinor::parseUnsigned($row['allocated']) !== $split['allocated']
                    || SignedMinor::parseUnsigned($row['surplus']) !== $split['surplus']) {
                    $base['issues'][] = ['code' => 'opening_predecessor_allocation_split_mismatch', 'row' => $key];
                    continue;
                }
            }
            $base['matched_rows'][] = ['row' => $key, 'opening_minor' => $closing];
        }
        foreach (array_diff_key($openings, $saved) as $key => $minor) {
            if ($minor !== 0) {
                $base['issues'][] = ['code' => 'opening_new_row_nonzero', 'row' => $key, 'actual_minor' => $minor];
            } else {
                $base['new_zero_opening_rows'][] = $key;
            }
        }
        $base['matched'] = $base['issues'] === [];
        return $base;
    }
}
