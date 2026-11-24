<?php

namespace App\Services;

use App\Models\HistoricalInvoiceCorrection;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** One evidenced correction of a retired meter's posted invoice, not a new reading. */
final class HistoricalInvoiceCorrectionService
{
    public function correct(Invoice $invoice, User $actor, string $units, string $price, string $reason, string $evidence): HistoricalInvoiceCorrection
    {
        abort_unless($actor->hasRole('super_admin') ||
            ($actor->can('delete', $invoice) && $actor->can('create', Invoice::class)), 403);
        $reason = trim($reason);
        $evidence = trim($evidence);
        if (! preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', $units)
            || ! preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', $price)
            || mb_strlen($reason) < 10 || mb_strlen($reason) > 1000
            || mb_strlen($evidence) < 10 || mb_strlen($evidence) > 2000) {
            throw ValidationException::withMessages(['correction' => 'يلزم توثيق الوحدات والتعرفة التاريخية وسبب التصحيح.']);
        }
        // Inputs bounded to seven whole digits; their minor-unit product fits int64.
        $minor = static fn ($v): int => (int) round((float) $v * 100);
        $u = $minor($units);
        $p = $minor($price);
        $amount = intdiv($u * $p + 50, 100);
        if ($amount > 999999999999) {
            throw ValidationException::withMessages(['corrected_units' => 'المبلغ المصحح يتجاوز الحد المالي.']);
        }
        $decimal = static fn (int $v): string => intdiv($v, 100).'.'.str_pad((string) ($v % 100), 2, '0', STR_PAD_LEFT);

        return DB::transaction(function () use ($invoice, $actor, $reason, $evidence, $u, $p, $amount, $minor, $decimal) {
            $subscriber = Subscriber::query()->lockForUpdate()->findOrFail($invoice->subscriber_id);
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            abort_unless((int) $invoice->subscriber_id === (int) $subscriber->id, 409);
            $existing = HistoricalInvoiceCorrection::where('original_invoice_id', $invoice->id)->first();
            if ($existing) {
                abort_unless($minor($existing->corrected_units) === $u && $minor($existing->historical_unit_price) === $p, 409,
                    'سبق تصحيح الفاتورة بقيم مختلفة.');
                return $existing;
            }
            if (! $invoice->retired_meter_id || $invoice->meter_id !== null
                || ! $invoice->billing_period_start || ! $invoice->billing_period_end
                || in_array($invoice->status, ['draft', 'cancelled'], true)
                || HistoricalInvoiceCorrection::where('replacement_invoice_id', $invoice->id)->exists()) {
                throw ValidationException::withMessages(['invoice' => 'هذه الفاتورة غير مؤهلة للتصحيح التاريخي.']);
            }
            $readings = $invoice->readings()->lockForUpdate()->get();
            if ($readings->isEmpty() || $readings->contains(fn ($r) => ! $r->is_invoiced
                || $r->meter_id !== null || (int) $r->retired_meter_id !== (int) $invoice->retired_meter_id)) {
                throw ValidationException::withMessages(['invoice' => 'لا توجد قراءات تاريخية مطابقة ومفوترة.']);
            }
            $before = $minor($subscriber->getAccountBalance());
            $oldAmount = $minor($invoice->total_amount);
            $ledger = app(TransactionService::class);
            $out = $ledger->reverseDocument($subscriber, $invoice, 'invoice');
            $invoice->forceFill(['status' => 'cancelled', 'voided_at' => now(), 'void_reason' => $reason,
                'voided_by' => $actor->id, 'amount_paid' => 0])->saveQuietly();

            // Do not move or duplicate historical readings. Replacement provenance
            // and the correction document reference the unchanged original.
            $allocator = app(PaymentService::class);
            $allocator->recalculateInvoices($subscriber);
            $previous = Invoice::where('subscriber_id', $subscriber->id)->whereNotIn('status', ['draft', 'cancelled'])
                ->get()->sum(fn ($i) => $i->getRemainingAmount());
            $replacement = new Invoice([
                'subscriber_id' => $subscriber->id, 'meter_id' => null, 'invoice_type' => 'meter',
                'bill_number' => Invoice::generateBillNumber(), 'bill_date' => now()->toDateString(),
                'billing_period_start' => $invoice->billing_period_start, 'billing_period_end' => $invoice->billing_period_end,
                'consumption_fee' => $decimal($amount), 'total_amount' => $decimal($amount),
                'previous_balance' => $previous, 'credit_applied' => min($amount / 100, $subscriber->getAccountCredit()),
                'amount_paid' => 0, 'status' => 'issued', 'notes' => 'تصحيح تاريخي للفاتورة '.$invoice->bill_number,
            ]);
            $replacement->forceFill(['retired_meter_id' => $invoice->retired_meter_id, 'retired_meter_code' => $invoice->retired_meter_code])->saveQuietly();
            InvoiceItem::create(['invoice_id' => $replacement->id, 'type' => 'consumption',
                'description' => 'استهلاك مصحح: '.$decimal($u).' × '.$decimal($p), 'amount' => $decimal($amount)]);
            if ($previous > 0) {
                InvoiceItem::create(['invoice_id' => $replacement->id, 'type' => 'previous_balance',
                    'description' => 'متأخرات سابقة', 'amount' => $previous]);
            }
            $correction = HistoricalInvoiceCorrection::create(['original_invoice_id' => $invoice->id,
                'replacement_invoice_id' => $replacement->id, 'approved_by' => $actor->id,
                'corrected_units' => $decimal($u), 'historical_unit_price' => $decimal($p),
                'corrected_amount' => $decimal($amount), 'reason' => $reason, 'evidence' => $evidence]);
            $in = $ledger->recordTransaction($subscriber, 'invoice', -($amount / 100),
                'فاتورة تاريخية مصححة '.$replacement->bill_number, $replacement);
            $in->forceFill(['retired_meter_id' => $invoice->retired_meter_id, 'retired_meter_code' => $invoice->retired_meter_code])->saveQuietly();
            $allocator->recalculateInvoices($subscriber);
            $after = $minor($subscriber->getAccountBalance());
            if ($after !== $before + $oldAmount - $amount) {
                throw new \RuntimeException('Historical correction does not conserve the account.');
            }
            app(AuditLogger::class)->recordRequired('invoice.historical_corrected', [
                'entity_type' => HistoricalInvoiceCorrection::class, 'entity_id' => $correction->id,
                'before' => ['balance_minor' => $before, 'invoice_id' => $invoice->id, 'amount_minor' => $oldAmount],
                'after' => ['balance_minor' => $after, 'replacement_invoice_id' => $replacement->id,
                    'reading_ids' => $readings->modelKeys(), 'reversal_id' => $out->id, 'posting_id' => $in->id,
                    'reason' => $reason, 'evidence' => $evidence, 'corrected_units' => $decimal($u), 'historical_unit_price' => $decimal($p)],
                'actor_user_id' => $actor->id,
            ]);
            return $correction;
        }, 3);
    }
}
