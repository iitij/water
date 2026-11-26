<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use App\Support\AccountMoney;
use App\Support\SignedMinor;

final class AllocationSnapshotIntegrity
{
    public function fingerprint(int $subscriberId, int $available, int $allocated, int $unallocated, array $rows): string
    {
        return hash('sha256', json_encode([$subscriberId, $available, $allocated, $unallocated, $rows], JSON_THROW_ON_ERROR));
    }

    public function valid(object $revision): bool
    {
        try {
            return $this->validExact($revision);
        } catch (\InvalidArgumentException|\OverflowException) {
            return false;
        }
    }

    private function validExact(object $revision): bool
    {
        $rows = DB::table('payment_allocations')->where('revision_id', $revision->id)
            ->orderBy('position')->get(['payment_id', 'account_transfer_id', 'invoice_id', 'amount_minor', 'position', 'held_cash_credit_id']);
        $canonical = [];
        $allocated = 0;
        $unallocated = 0;
        foreach ($rows as $index => $row) {
            $amount = AccountMoney::positiveAllocation($row->amount_minor);
            if ($amount <= 0 || (int) $row->position !== $index + 1
                || count(array_filter([$row->payment_id, $row->account_transfer_id, $row->held_cash_credit_id], fn ($id) => $id !== null)) !== 1) {
                return false;
            }
            $item = [
                'payment_id' => $row->payment_id === null ? null : (int) $row->payment_id,
                'account_transfer_id' => $row->account_transfer_id === null ? null : (int) $row->account_transfer_id,
            ];
            if ($row->held_cash_credit_id !== null) $item['held_cash_credit_id'] = (int) $row->held_cash_credit_id;
            $canonical[] = $item + ['invoice_id' => $row->invoice_id === null ? null : (int) $row->invoice_id,
                'amount_minor' => $amount];
            if ($row->invoice_id === null) {
                $unallocated = SignedMinor::add($unallocated, $amount);
            } else {
                $allocated = SignedMinor::add($allocated, $amount);
            }
        }
        return $allocated === AccountMoney::unsigned($revision->allocated_minor)
            && $unallocated === AccountMoney::unsigned($revision->unallocated_minor)
            && SignedMinor::add($allocated, $unallocated) === AccountMoney::unsigned($revision->available_minor)
            && hash_equals($revision->allocation_hash, $this->fingerprint(
                (int) $revision->subscriber_id, AccountMoney::unsigned($revision->available_minor), $allocated, $unallocated, $canonical
            ));
    }
}
