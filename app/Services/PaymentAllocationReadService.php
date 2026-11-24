<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAccountTransfer;
use App\Models\Subscriber;
use Illuminate\Support\Facades\DB;

/** Read-only projections of immutable revisions. Never backfill on a GET. */
final class PaymentAllocationReadService
{
    private function latest(int $subscriberId): ?object
    {
        return DB::table('payment_allocation_revisions')->where('subscriber_id', $subscriberId)
            ->orderByDesc('revision')->first();
    }

    private function projection(int $subscriberId, ?object $revision, array $rows): array
    {
        return [
            'available' => $revision !== null,
            'subscriber_id' => $subscriberId,
            'revision' => $revision ? (int) $revision->revision : null,
            'recorded_at' => $revision?->created_at,
            'rows' => $rows,
            'allocated_minor' => $revision ? array_sum(array_column(array_filter($rows, fn ($row) => $row['invoice_id'] !== null), 'amount_minor')) : null,
            'unallocated_minor' => $revision ? array_sum(array_column(array_filter($rows, fn ($row) => $row['invoice_id'] === null), 'amount_minor')) : null,
        ];
    }

    private function rows(int $revisionId, string $column, int $value): array
    {
        return DB::table('payment_allocations as a')
            ->leftJoin('invoices as i', 'i.id', '=', 'a.invoice_id')
            ->leftJoin('payment_account_transfers as t', 't.id', '=', 'a.account_transfer_id')
            ->leftJoin('payments as p', fn ($join) => $join->on('p.id', '=', DB::raw('COALESCE(a.payment_id, t.payment_id)')))
            ->where('a.revision_id', $revisionId)->where('a.'.$column, $value)
            ->orderBy('a.position')
            ->get(['a.payment_id', 'a.account_transfer_id', 'a.invoice_id', 'a.amount_minor', 'i.bill_number', 'p.receipt_number'])
            ->map(fn ($row) => [
                'payment_id' => $row->payment_id === null ? null : (int) $row->payment_id,
                'account_transfer_id' => $row->account_transfer_id === null ? null : (int) $row->account_transfer_id,
                'invoice_id' => $row->invoice_id === null ? null : (int) $row->invoice_id,
                'bill_number' => $row->bill_number,
                'receipt_number' => $row->receipt_number,
                'amount_minor' => (int) $row->amount_minor,
            ])->all();
    }

    public function forPayment(Payment $payment): array
    {
        // Transfers are distinct sources belonging to the receiving account.
        $transfer = PaymentAccountTransfer::where('payment_id', $payment->id)->first();
        $subscriberId = (int) ($transfer?->to_subscriber_id ?? $payment->subscriber_id);
        $revision = $this->latest($subscriberId);
        $rows = $revision ? $this->rows((int) $revision->id,
            $transfer ? 'account_transfer_id' : 'payment_id', (int) ($transfer?->id ?? $payment->id)) : [];
        return $this->projection($subscriberId, $revision, $rows);
    }

    public function forInvoice(Invoice $invoice): array
    {
        $subscriberId = (int) $invoice->subscriber_id;
        $revision = $this->latest($subscriberId);
        return $this->projection($subscriberId, $revision,
            $revision ? $this->rows((int) $revision->id, 'invoice_id', (int) $invoice->id) : []);
    }

    public function reconcile(Subscriber $subscriber): array
    {
        $minor = static fn ($v): int => (int) round((float) $v * 100);
        // A single subscriber lock makes the ledger, invoices and revision coherent.
        return DB::transaction(function () use ($subscriber, $minor) {
            Subscriber::query()->lockForUpdate()->findOrFail($subscriber->id);
            $revision = $this->latest((int) $subscriber->id);
            $invoices = Invoice::where('subscriber_id', $subscriber->id)
                ->whereNotIn('status', ['draft', 'cancelled'])->get(['total_amount', 'amount_paid']);
            $billed = $invoices->sum(fn ($i) => $minor($i->total_amount));
            $paid = $invoices->sum(fn ($i) => $minor($i->amount_paid));
            $ledger = $minor($subscriber->getAccountBalance());
            $expected = $revision ? (int) $revision->available_minor - $billed : null;
            return [
                'available' => $revision !== null, 'revision' => $revision ? (int) $revision->revision : null,
                'billed_minor' => $billed, 'paid_minor' => $paid, 'ledger_minor' => $ledger,
                'allocated_minor' => $revision ? (int) $revision->allocated_minor : null,
                'unallocated_minor' => $revision ? (int) $revision->unallocated_minor : null,
                'expected_ledger_minor' => $expected,
                'difference_minor' => $expected === null ? null : $ledger - $expected,
                'matches' => $revision === null ? null : $ledger === $expected && $paid === (int) $revision->allocated_minor,
            ];
        }, 3);
    }
}
