<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Subscriber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InvoiceCancellationService
{
    public function cancel(Invoice $invoice, string $reason, ?int $actorId = null, string $source = 'api', ?GatedInvoiceTransferWriter $gatedWriter = null): Invoice
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['reason' => 'سبب الإلغاء بين 10 و1000 حرف.']);
        }

        return DB::transaction(function () use ($invoice, $reason, $actorId, $source, $gatedWriter) {
            // Serialize allocation-changing corrections before taking account locks.
            if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                throw new \LogicException('Financial issuance lock is missing.');
            }
            $subscriber = Subscriber::query()->lockForUpdate()->findOrFail($invoice->subscriber_id);
            if ($invoice->meter_id) {
                \App\Models\Meter::query()->lockForUpdate()->findOrFail($invoice->meter_id);
            }
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $ids = \App\Models\Transaction::withTrashed()->where('reference_type', Invoice::class)
                ->where('reference_id', $invoice->id)->whereIn('type', ['invoice', 'invoice_reversal'])->pluck('id');
            $registered = DB::table('financial_posting_events')->where('scope_key', 'water')
                ->where('source_type', 'ledger_transaction')->where('leg', 'subscriber')
                ->whereIn('source_id', $ids->map(fn ($id) => (string) $id)->all())->exists();
            if (! ($gatedWriter !== null && $gatedWriter->allowsCancellation((int) $invoice->id, $actorId))
                && ($gatedWriter !== null || $registered || config('financial_candidate.invoices') === true)) {
                throw new \DomainException('gated_invoice_writer_required');
            }
            if ($invoice->status === 'cancelled') {
                return $invoice;
            }
            if ($invoice->status === 'draft') {
                throw ValidationException::withMessages(['invoice' => 'المسودة غير مرحلة مالياً.']);
            }
            $readings = $invoice->readings()->lockForUpdate()->get();
            if ($invoice->retired_meter_id) {
                throw ValidationException::withMessages([
                    'invoice' => 'تصحيح فاتورة قراءات عداد مستبدل يتطلب مسار تصحيح تاريخي مستقل؛ لا تُرحّل قراءاته إلى العداد الجديد.',
                ]);
            }
            $before = [
                'status' => $invoice->status,
                'amount_paid' => $invoice->amount_paid,
                'total_amount' => $invoice->total_amount,
                'reading_ids' => $readings->modelKeys(),
                'readings' => $readings->toArray(),
            ];
            app(TransactionService::class)->reverseDocument($subscriber, $invoice, 'invoice', $gatedWriter);
            $invoice->forceFill([
                'status' => 'cancelled',
                'voided_at' => now(),
                'void_reason' => $reason,
                'voided_by' => $actorId,
                'amount_paid' => 0,
            ])->saveQuietly(['financial_invoice_writer' => $gatedWriter]);

            // Original payment references remain intact. Allocation ignores
            // cancelled invoices, freeing credit for other invoices or future use.
            $invoice->readings()->update(['invoice_id' => null, 'is_invoiced' => false]);
            app(PaymentService::class)->recalculateInvoices($subscriber);
            app(AuditLogger::class)->recordRequired('invoice.cancelled', [
                'entity_type' => Invoice::class,
                'entity_id' => $invoice->id,
                'before' => $before,
                'after' => ['status' => 'cancelled', 'reason' => $reason],
                'actor_user_id' => $actorId,
                'source' => $source,
            ]);

            return $invoice->fresh();
        }, 3);
    }
}
