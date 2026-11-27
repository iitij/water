<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\SignedMinor;

/** Compare independently reconstructed cutoff accounts to the recorded movements.
 * Never infer unknown historical openings or certify the full accounting close. */
final class FinancialCutoffMovementReconciliation
{
    /** Recognition is a separately verified cash-adjustment source, not a
     * liability registry leg. Compare gross reclassifications and discharges. */
    public function inspectHeld(array $held, array $movement): array
    {
        if (($held['registered_held_subledger_rollforward_verified'] ?? false) !== true
            || ($movement['recorded_movements_verified'] ?? false) !== true) {
            return ['matched' => false, 'rows' => [], 'issues' => [['code' => 'cutoff_held_movements_unverified']]];
        }
        $expected = $registered = $issues = $rows = [];
        $add = static function (string $key, int $incoming, int $outgoing) use (&$expected, &$issues): void {
            if (isset($expected[$key])) {
                $issues[] = ['code' => 'cutoff_held_duplicate_source', 'account' => $key];
                return;
            }
            $expected[$key] = ['incoming_minor' => $incoming, 'outgoing_minor' => $outgoing,
                'net_movement_minor' => SignedMinor::subtract($incoming, $outgoing)];
        };
        foreach ($held['rows'] as $lot) {
            $add('lot-'.$lot['liability_id'], 0, SignedMinor::parseUnsigned($lot['assigned_minor']));
            foreach ($lot['owners'] as $owner) {
                $assigned = SignedMinor::parseUnsigned($owner['assigned_minor']);
                $discharged = SignedMinor::add(SignedMinor::parseUnsigned($owner['paid_minor']),
                    SignedMinor::parseUnsigned($owner['credited_minor']));
                $add('assignment-'.$owner['assignment_uuid'], $assigned, $discharged);
            }
        }
        foreach ($movement['rows'] as $row) {
            if ($row['sector'] !== 'held_liability_effect') continue;
            $key = $row['key'];
            if (isset($registered[$key])) {
                $issues[] = ['code' => 'cutoff_held_duplicate_movement', 'account' => $key];
                continue;
            }
            $registered[$key] = $row;
        }
        foreach ($expected as $key => $amounts) {
            $recorded = $registered[$key] ?? null;
            unset($registered[$key]);
            $valid = SignedMinor::parse($recorded['recorded_opening_document_minor'] ?? 0) === 0;
            foreach ($amounts as $field => $value) {
                $valid = $valid && SignedMinor::parse($recorded[$field] ?? 0) === $value;
            }
            if (! $valid) {
                $issues[] = ['code' => 'cutoff_held_movement_mismatch', 'account' => $key];
                continue;
            }
            $rows[$key] = ['account' => $key] + $amounts;
        }
        foreach (array_keys($registered) as $key) {
            $issues[] = ['code' => 'cutoff_held_movement_source_missing', 'account' => $key];
        }
        ksort($rows, SORT_STRING);
        return ['matched' => $issues === [], 'rows' => array_values($rows), 'issues' => $issues];
    }

    public function inspectPhysical(array $outstanding, array $movement): array
    {
        if (($movement['recorded_movements_verified'] ?? false) !== true
            || ! $outstanding['transit']['recorded_rollforward_verified']
            || ! $outstanding['custody']['recorded_rollforward_verified']) {
            return ['matched' => false, 'rows' => [], 'issues' => [['code' => 'cutoff_physical_unverified']]];
        }
        $expected = $registered = $issues = [];
        $add = static function (array $row) use (&$expected, &$issues): void {
            $id = $row['posting_id'];
            if (! is_int($id) || $id <= 0 || isset($expected[$id])) {
                $issues[] = ['code' => 'cutoff_physical_duplicate_or_invalid_source', 'posting_id' => $id];
                return;
            }
            $expected[$id] = $row;
        };
        foreach ($outstanding['transit']['rows'] as $row) {
            $sent = SignedMinor::parseUnsigned($row['dispatched_minor']);
            $received = SignedMinor::parseUnsigned($row['received_minor']);
            if ($sent > 0) $add(['kind' => 'internal_transfer_out', 'posting_id' => $row['dispatch_posting_id'],
                'account_id' => $row['from_account_id'], 'amount_minor' => SignedMinor::negate($sent), 'source_hash' => $row['dispatch_hash']]);
            if ($received > 0) $add(['kind' => 'internal_transfer_in', 'posting_id' => $row['receipt_posting_id'],
                'account_id' => $row['to_account_id'], 'amount_minor' => $received, 'source_hash' => $row['receipt_hash']]);
            if (SignedMinor::subtract(SignedMinor::add($row['opening_minor'], $sent), $received) !== $row['closing_minor']) {
                $issues[] = ['code' => 'cutoff_transit_conservation', 'transfer_id' => $row['transfer_id']];
            }
        }
        foreach ($outstanding['custody']['rows'] as $row) {
            $received = 0;
            foreach ($row['period_handovers'] as $handover) {
                $received = SignedMinor::add($received, SignedMinor::parseUnsigned($handover['amount_minor']));
                $add($handover);
            }
            if ($received !== $row['received_minor'] || SignedMinor::subtract(
                SignedMinor::add($row['opening_minor'], $row['recognized_counted_minor']), $received) !== $row['closing_minor']) {
                $issues[] = ['code' => 'cutoff_custody_conservation', 'session_id' => $row['session_id']];
            }
        }
        foreach ($movement['physical_effects'] as $row) {
            $id = $row['posting_id'];
            if (isset($registered[$id])) {
                $issues[] = ['code' => 'cutoff_physical_duplicate_registry', 'posting_id' => $id];
                continue;
            }
            $registered[$id] = $row;
        }
        foreach ($expected as $id => $row) {
            if (($registered[$id] ?? null) !== $row) {
                $issues[] = ['code' => 'cutoff_physical_movement_mismatch', 'posting_id' => $id];
            }
            unset($registered[$id]);
        }
        foreach (array_keys($registered) as $id) $issues[] = ['code' => 'cutoff_physical_unmatched_registry', 'posting_id' => $id];
        ksort($expected, SORT_NUMERIC);
        return ['matched' => $issues === [], 'rows' => $issues === [] ? array_values($expected) : [], 'issues' => $issues];
    }

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
