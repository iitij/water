<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\SignedMinor;

/** Compare independently reconstructed cutoff accounts to the recorded movements.
 * Never infer unknown historical openings or certify the full accounting close. */
final class FinancialCutoffMovementReconciliation
{
    public function inspect(array $cash, array $bank, array $subscriber, array $movement): array
    {
        $issues = [];
        $matched = [];
        $registered = [];
        if (($movement['recorded_movements_verified'] ?? false) !== true) {
            return ['matched' => false, 'rows' => [], 'issues' => [['code' => 'cutoff_movements_unverified']]];
        }
        foreach ($movement['rows'] as $row) {
            if (! in_array($row['sector'], ['cash', 'bank', 'subscriber'], true)) continue;
            $key = $row['sector'].':'.$row['key'];
            if (isset($registered[$key])) {
                $issues[] = ['code' => 'cutoff_duplicate_movement_account', 'account' => $key];
                continue;
            }
            $registered[$key] = $row;
        }
        $sections = [
            'cash' => [$cash['all_accounts_matched'], $cash['rows']],
            'bank' => [$bank['all_accounts_matched'], $bank['rows']],
            'subscriber' => [$subscriber['all_cutoff_accounts_match'] && $subscriber['allocation_policy_verified'], $subscriber['rows']],
        ];
        $seen = [];
        foreach ($sections as $sector => [$verified, $rows]) {
            if ($verified !== true) {
                $issues[] = ['code' => 'cutoff_section_unverified', 'sector' => $sector];
                continue;
            }
            foreach ($rows as $row) {
                $key = $sector.':'.$row[$sector === 'subscriber' ? 'subscriber_id' : 'account_id'];
                if (isset($seen[$key])) {
                    $issues[] = ['code' => 'cutoff_duplicate_account', 'account' => $key];
                    continue;
                }
                $seen[$key] = true;
                $recorded = $registered[$key] ?? null;
                unset($registered[$key]);
                $document = SignedMinor::parse($recorded['recorded_opening_document_minor'] ?? 0);
                $net = SignedMinor::parse($recorded['net_movement_minor'] ?? 0);
                if ($sector === 'subscriber') {
                    // Birth-period accounts are reconstructed from their complete
                    // source history; existing covered accounts carry explicit openings.
                    $opening = SignedMinor::parse($row['opening_minor'] ?? 0);
                    $closing = SignedMinor::parse($row['recorded_balance_minor']);
                    $change = SignedMinor::parse($row['net_movement_minor'] ?? $closing);
                    $valid = $document === 0 && $change === $net
                        && SignedMinor::add($opening, $change) === $closing;
                } else {
                    $opening = SignedMinor::parse($row['opening_minor']);
                    $closing = SignedMinor::parse($row['closing_minor']);
                    $change = SignedMinor::parse($row['movement_minor']);
                    $valid = $document === SignedMinor::parse($row['registered_opening_document_minor'])
                        && $net === SignedMinor::parse($row['registered_movement_minor'])
                        && $change === SignedMinor::add($document, $net)
                        && SignedMinor::add($opening, $change) === $closing;
                }
                if (! $valid) {
                    $issues[] = ['code' => 'cutoff_account_movement_mismatch', 'account' => $key];
                    continue;
                }
                $matched[$key] = ['account' => $key, 'opening_minor' => $opening,
                    'movement_minor' => $change, 'closing_minor' => $closing, 'source_hash' => $row['source_hash']];
            }
        }
        foreach (array_keys($registered) as $key) {
            $issues[] = ['code' => 'cutoff_movement_account_missing', 'account' => $key];
        }
        ksort($matched, SORT_STRING);
        return ['matched' => $issues === [], 'rows' => array_values($matched), 'issues' => $issues];
    }
}
