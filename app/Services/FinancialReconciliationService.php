<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAccountTransfer;
use App\Models\PaymentRefund;
use App\Models\Subscriber;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/** Read-only account audit. Never repair, repost or recreate allocations. */
final class FinancialReconciliationService
{
    private function minor($value): int
    {
        return (int) round((float) $value * 100);
    }

    public function inspect(Subscriber $subscriber): array
    {
        return DB::transaction(function () use ($subscriber): array {
            $id = (int) Subscriber::query()->lockForUpdate()->findOrFail($subscriber->id)->id;
            $issues = [];
            $issue = static function (string $code, array $context = []) use (&$issues): void {
                $issues[] = ['code' => $code] + $context;
            };
            $expectedKeys = [];
            $expectedLedger = 0;
            $posting = function (string $model, int $referenceId, string $type, int $owner, int $amount,
                ?int $meter = null, ?int $retired = null) use (&$expectedKeys, &$expectedLedger, $id, $issue): void {
                $key = $model.':'.$referenceId.':'.$type;
                $expectedKeys[$key] = true;
                if ($owner === $id) {
                    $expectedLedger += $amount;
                }
                $rows = Transaction::withTrashed()->where('reference_type', $model)
                    ->where('reference_id', $referenceId)->where('type', $type)->get();
                $context = ['reference_type' => $model, 'reference_id' => $referenceId, 'posting_type' => $type];
                if ($rows->count() !== 1) {
                    $issue($rows->isEmpty() ? 'missing_posting' : 'duplicate_posting', $context + ['count' => $rows->count()]);
                    return;
                }
                $row = $rows->first();
                if ($row->trashed() || (int) $row->subscriber_id !== $owner || $this->minor($row->amount) !== $amount
                    || (int) ($row->meter_id ?? 0) !== (int) ($meter ?? 0)
                    || (int) ($row->retired_meter_id ?? 0) !== (int) ($retired ?? 0)) {
                    $issue('posting_mismatch', $context + ['transaction_id' => $row->id, 'expected_minor' => $amount,
                        'actual_minor' => $this->minor($row->amount), 'expected_owner' => $owner]);
                }
            };

            $invoices = Invoice::where('subscriber_id', $id)->get()->keyBy('id');
            $payments = Payment::where('subscriber_id', $id)->get()->keyBy('id');
            $transfers = PaymentAccountTransfer::where(fn ($q) => $q->where('from_subscriber_id', $id)
                ->orWhere('to_subscriber_id', $id))->get();
            $refunds = PaymentRefund::where('subscriber_id', $id)
                ->orWhereIn('payment_id', $payments->modelKeys())->get();
            $outgoing = $transfers->where('from_subscriber_id', $id)->groupBy('payment_id');
            $refundGroups = $refunds->groupBy('payment_id');
            $activeInvoices = [];
            $sources = [];
            $billed = 0;
            $paid = 0;

            foreach ($invoices as $invoice) {
                $total = $this->minor($invoice->total_amount);
                $applied = $this->minor($invoice->amount_paid);
                if ($invoice->status === 'draft') {
                    if ($applied !== 0) $issue('draft_invoice_paid', ['invoice_id' => $invoice->id]);
                    continue;
                }
                if ($total < 0) $issue('negative_invoice', ['invoice_id' => $invoice->id]);
                $posting(Invoice::class, $invoice->id, 'invoice', $id, -$total, $invoice->meter_id, $invoice->retired_meter_id);
                if ($invoice->status === 'cancelled') {
                    $posting(Invoice::class, $invoice->id, 'invoice_reversal', $id, $total, $invoice->meter_id, $invoice->retired_meter_id);
                    if ($applied !== 0) $issue('cancelled_invoice_paid', ['invoice_id' => $invoice->id]);
                    continue;
                }
                if ($applied < 0 || $applied > $total) $issue('invoice_paid_out_of_range', ['invoice_id' => $invoice->id]);
                $activeInvoices[$invoice->id] = $applied;
                $billed += $total;
                $paid += $applied;
            }
            foreach ($payments as $payment) {
                $amount = $this->minor($payment->amount);
                if ($amount <= 0) $issue('invalid_receipt_amount', ['payment_id' => $payment->id]);
                $posting(Payment::class, $payment->id, 'payment', $id, $amount, $payment->meter_id, $payment->retired_meter_id);
                $moved = $outgoing->get($payment->id, collect());
                $returned = $refundGroups->get($payment->id, collect())->sum(fn ($r) => $this->minor($r->amount));
                if ($moved->count() > 1 || $returned > $amount || ($returned && ($payment->isVoided() || $moved->isNotEmpty()))
                    || ($payment->isVoided() && $moved->isNotEmpty())) {
                    $issue('receipt_correction_conflict', ['payment_id' => $payment->id]);
                }
                if ($payment->isVoided() || $moved->isNotEmpty()) {
                    $posting(Payment::class, $payment->id, 'payment_reversal', $id, -$amount, $payment->meter_id, $payment->retired_meter_id);
                } else {
                    $sources['payment:'.$payment->id] = $amount - $returned;
                }
            }
            foreach ($transfers as $transfer) {
                $receipt = Payment::find($transfer->payment_id);
                $amount = $this->minor($transfer->amount);
                if (! $receipt || (int) $receipt->subscriber_id !== (int) $transfer->from_subscriber_id
                    || $amount <= 0 || $this->minor($receipt->amount) !== $amount || $receipt->isVoided()
                    || (int) $transfer->from_subscriber_id === (int) $transfer->to_subscriber_id) {
                    $issue('transfer_document_mismatch', ['transfer_id' => $transfer->id]);
                }
                $posting(PaymentAccountTransfer::class, $transfer->id, 'payment_transfer_in', (int) $transfer->to_subscriber_id, $amount);
                if ((int) $transfer->to_subscriber_id === $id) {
                    $sources['transfer:'.$transfer->id] = $amount;
                    if ($receipt && (int) $receipt->subscriber_id !== $id) {
                        $posting(Payment::class, $receipt->id, 'payment_reversal', (int) $receipt->subscriber_id,
                            -$this->minor($receipt->amount), $receipt->meter_id, $receipt->retired_meter_id);
                    }
                }
            }
            foreach ($refunds as $refund) {
                $receipt = Payment::find($refund->payment_id);
                $amount = $this->minor($refund->amount);
                if (! $receipt || (int) $receipt->subscriber_id !== (int) $refund->subscriber_id || $amount <= 0
                    || $receipt->isVoided() || PaymentAccountTransfer::where('payment_id', $refund->payment_id)->exists()) {
                    $issue('refund_document_mismatch', ['refund_id' => $refund->id]);
                }
                $posting(PaymentRefund::class, $refund->id, 'payment_refund', (int) $refund->subscriber_id, -$amount);
            }

            // Also detect extra or orphan postings, including unsupported legacy adjustments.
            $actual = 0;
            foreach (Transaction::withTrashed()->where('subscriber_id', $id)->get() as $row) {
                if (! $row->trashed()) $actual += $this->minor($row->amount);
                $key = $row->reference_type.':'.$row->reference_id.':'.$row->type;
                if (! isset($expectedKeys[$key])) {
                    $issue('unexpected_posting', ['transaction_id' => $row->id, 'posting_type' => $row->type]);
                }
            }
            if ($actual !== $expectedLedger) {
                $issue('ledger_total_mismatch', ['actual_minor' => $actual, 'expected_minor' => $expectedLedger]);
            }

            $revision = DB::table('payment_allocation_revisions')->where('subscriber_id', $id)->orderByDesc('revision')->first();
            $available = array_sum($sources);
            if ($actual !== $available - $billed) {
                $issue('document_balance_mismatch', ['ledger_minor' => $actual, 'document_balance_minor' => $available - $billed]);
            }
            if (! $revision) {
                if ($activeInvoices || $sources) $issue('allocation_revision_missing');
            } else {
                if (! app(AllocationSnapshotIntegrity::class)->valid($revision)) $issue('allocation_integrity_invalid');
                $byInvoice = array_fill_keys(array_keys($activeInvoices), 0);
                $bySource = array_fill_keys(array_keys($sources), 0);
                $unallocated = 0;
                foreach (DB::table('payment_allocations')->where('revision_id', $revision->id)->get() as $row) {
                    $key = $row->payment_id !== null ? 'payment:'.$row->payment_id : 'transfer:'.$row->account_transfer_id;
                    if (! array_key_exists($key, $bySource)) {
                        $issue('allocation_source_invalid', ['source' => $key]);
                    } else {
                        $bySource[$key] += (int) $row->amount_minor;
                    }
                    if ($row->invoice_id === null) {
                        $unallocated += (int) $row->amount_minor;
                    } elseif (! array_key_exists($row->invoice_id, $byInvoice)) {
                        $issue('allocation_invoice_invalid', ['invoice_id' => $row->invoice_id]);
                    } else {
                        $byInvoice[$row->invoice_id] += (int) $row->amount_minor;
                    }
                }
                foreach ($sources as $key => $amount) {
                    if ($bySource[$key] !== $amount) $issue('allocation_source_mismatch', ['source' => $key,
                        'expected_minor' => $amount, 'actual_minor' => $bySource[$key]]);
                }
                foreach ($activeInvoices as $invoiceId => $amount) {
                    if ($byInvoice[$invoiceId] !== $amount) $issue('allocation_invoice_mismatch', ['invoice_id' => $invoiceId,
                        'expected_minor' => $amount, 'actual_minor' => $byInvoice[$invoiceId]]);
                }
                if ((int) $revision->available_minor !== $available || (int) $revision->allocated_minor !== $paid
                    || $unallocated !== max(0, $available - $billed) || $paid !== min($available, $billed)) {
                    $issue('allocation_totals_mismatch');
                }
            }
            return ['subscriber_id' => $id, 'matches' => $issues === [], 'issue_count' => count($issues),
                'issues' => $issues, 'ledger_minor' => $actual, 'expected_ledger_minor' => $expectedLedger,
                'document_balance_minor' => $available - $billed, 'billed_minor' => $billed,
                'available_minor' => $available, 'paid_minor' => $paid, 'revision' => $revision ? (int) $revision->revision : null];
        }, 3);
    }
}
