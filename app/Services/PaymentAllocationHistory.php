<?php

namespace App\Services;

use App\Support\FinancialAmount;

use App\Models\Subscriber;
use Illuminate\Support\Facades\DB;

/**
 * Immutable, versioned allocation snapshots. A null invoice means unapplied
 * credit, not a receipt or another ledger posting. Called inside the existing
 * subscriber-locked replay transaction. No historical backfill in migration.
 */
class PaymentAllocationHistory
{
    public function append(Subscriber $subscriber, array $rows, array $paid, int $available, array $sourceAmounts = []): int
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Allocation snapshots require an account transaction.');
        }
        Subscriber::query()->lockForUpdate()->findOrFail($subscriber->id);
        $allocated = 0;
        $unallocated = 0;
        $byInvoice = array_fill_keys(array_keys($paid), 0);
        $bySource = array_fill_keys(array_keys($sourceAmounts), 0);
        foreach ($rows as $row) {
            if (($row['payment_id'] === null) === ($row['account_transfer_id'] === null)
                || ! is_int($row['amount_minor']) || $row['amount_minor'] <= 0) {
                throw new \LogicException('Invalid allocation source or amount.');
            }
            $sourceKey = $row['payment_id'] !== null ? 'payment:'.$row['payment_id'] : 'transfer:'.$row['account_transfer_id'];
            if (! array_key_exists($sourceKey, $bySource)) {
                throw new \LogicException('Unknown allocation credit source.');
            }
            $bySource[$sourceKey] = FinancialAmount::add($bySource[$sourceKey], $row['amount_minor']);
            if ($row['invoice_id'] === null) {
                $unallocated = FinancialAmount::add($unallocated, $row['amount_minor']);
            } else {
                if (! array_key_exists($row['invoice_id'], $byInvoice)) {
                    throw new \LogicException('Allocation outside the account invoice set.');
                }
                $allocated = FinancialAmount::add($allocated, $row['amount_minor']);
                $byInvoice[$row['invoice_id']] = FinancialAmount::add($byInvoice[$row['invoice_id']], $row['amount_minor']);
            }
        }
        if ($byInvoice !== $paid || $bySource !== $sourceAmounts || FinancialAmount::add($allocated, $unallocated) !== $available) {
            throw new \LogicException('Allocation snapshot does not conserve money.');
        }
        $integrity = app(AllocationSnapshotIntegrity::class);
        $hash = $integrity->fingerprint((int) $subscriber->id, $available, $allocated, $unallocated, $rows);
        $latest = DB::table('payment_allocation_revisions')->where('subscriber_id', $subscriber->id)
            ->orderByDesc('revision')->lockForUpdate()->first();
        if ($latest && ! $integrity->valid($latest)) {
            throw new \RuntimeException('Allocation history integrity failure; financial replay blocked.');
        }
        if ($latest && hash_equals($latest->allocation_hash, $hash)) {
            return (int) $latest->id;
        }
        $id = DB::table('payment_allocation_revisions')->insertGetId([
            'subscriber_id' => $subscriber->id,
            'revision' => ((int) ($latest->revision ?? 0)) + 1,
            'allocation_hash' => $hash, 'available_minor' => $available,
            'allocated_minor' => $allocated, 'unallocated_minor' => $unallocated,
            'created_at' => now(),
        ]);
        foreach (array_chunk($rows, 300, true) as $chunk) {
            DB::table('payment_allocations')->insert(array_map(
                fn ($row, $position) => $row + ['revision_id' => $id, 'position' => $position + 1],
                $chunk, array_keys($chunk)
            ));
        }
        return $id;
    }
}
