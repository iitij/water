<?php

namespace App\Services;

use App\Support\ExactMoney;
use App\Support\FinancialAmount;
use App\Support\InvoiceAmounts;
use App\Support\HistoricalTariffEvidence;

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
        return $this->execute($invoice, $actor, null, $units, $price, $reason, $evidence);
    }

    /** Every original reading must be identified exactly once. Prices come from server evidence. */
    public function correctReadings(Invoice $invoice, User $actor, array $readings, string $reason, string $evidence): HistoricalInvoiceCorrection
    {
        return $this->execute($invoice, $actor, $readings, null, null, $reason, $evidence);
    }

    private function execute(Invoice $invoice, User $actor, ?array $requested, ?string $units, ?string $price, string $reason, string $evidence): HistoricalInvoiceCorrection
    {
        abort_unless($actor->hasRole('super_admin') ||
            ($actor->can('delete', $invoice) && $actor->can('create', Invoice::class)), 403);
        // No historical replacement writer is accepted by the prospective registry.
        // Fail before touching source documents, allocations or ledger projections.
        if (config('financial_candidate.invoices') === true) {
            throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException(
                'التصحيح التاريخي للفواتير خارج نطاق النظام المحاسبي الجديد.');
        }
        $reason = trim($reason);
        $evidence = trim($evidence);
        $validNumber = static fn ($v): bool => (is_string($v) || is_int($v) || is_float($v))
            && preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', (string) $v) === 1;
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 1000
            || mb_strlen($evidence) < 10 || mb_strlen($evidence) > 2000
            || ($requested === null && (! $validNumber($units) || ! $validNumber($price)))) {
            throw ValidationException::withMessages(['correction' => 'يلزم توثيق الوحدات والتعرفة التاريخية وسبب التصحيح.']);
        }
        // Keep the existing wire syntax and bounds; parse actual values without
        // binary arithmetic, including numeric SQLite DECIMAL representations.
        $minor = static fn ($v): int => FinancialAmount::validated($v, 'correction')->minor;
        $decimal = static fn (int $v): string => ExactMoney::fromMinor($v)->decimal();
        $requestedUnits = [];
        if ($requested !== null) {
            foreach ($requested as $row) {
                if (! is_array($row) || ! isset($row['reading_id'], $row['corrected_units'])
                    || ! preg_match('/^[1-9]\d*$/D', (string) $row['reading_id'])
                    || ! $validNumber($row['corrected_units'])
                    || isset($requestedUnits[(int) $row['reading_id']])) {
                    throw ValidationException::withMessages(['readings' => 'يجب تحديد كل قراءة مرة واحدة مع وحدات مصححة صالحة.']);
                }
                $requestedUnits[(int) $row['reading_id']] = $minor($row['corrected_units']);
            }
            if ($requestedUnits === []) {
                throw ValidationException::withMessages(['readings' => 'يلزم تحديد القراءات المصححة.']);
            }
            ksort($requestedUnits);
        }
        $legacyUnits = $units === null ? null : $minor($units);
        $confirmedPrice = $price === null ? null : $minor($price);

        return DB::transaction(function () use ($invoice, $actor, $reason, $evidence, $requested, $requestedUnits, $legacyUnits, $confirmedPrice, $minor, $decimal) {
            // A configured financial scope must never admit this unregistered
            // legacy correction, even when candidate switches are disabled.
            // Use a current locking read before issuance and account locks.
            if (DB::table('financial_scopes')->where('scope_key', 'water')->lockForUpdate()->first()) {
                throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException(
                    'التصحيح التاريخي للفواتير خارج نطاق النظام المحاسبي الجديد.');
            }
            // Serialize allocation-changing corrections before taking account locks.
            if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                throw new \LogicException('Financial issuance lock is missing.');
            }
            $subscriber = Subscriber::query()->lockForUpdate()->findOrFail($invoice->subscriber_id);
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            abort_unless((int) $invoice->subscriber_id === (int) $subscriber->id, 409);
            $existing = HistoricalInvoiceCorrection::where('original_invoice_id', $invoice->id)->first();
            if ($existing) {
                if ($requested === null) {
                    $same = $existing->historical_unit_price !== null
                        && $invoice->readings()->count() === 1
                        && $minor($existing->corrected_units) === $legacyUnits
                        && $minor($existing->historical_unit_price) === $confirmedPrice;
                } else {
                    $stored = DB::table('invoice_tariff_lines')->where('invoice_id', $existing->replacement_invoice_id)
                        ->orderBy('reading_id')->pluck('units', 'reading_id')
                        ->map(fn ($v) => $minor($v))->all();
                    $same = $stored === $requestedUnits;
                }
                abort_unless($same, 409, 'سبق تصحيح الفاتورة بقيم مختلفة.');
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
            $ids = $readings->modelKeys();
            sort($ids);
            if ($requested === null) {
                if (count($ids) !== 1) {
                    throw ValidationException::withMessages(['corrected_units' => 'تصحيح عدة قراءات يتطلب تحديد الوحدات المصححة لكل قراءة على حدة.']);
                }
                $requestedUnits = [(int) $ids[0] => $legacyUnits];
            } elseif (array_keys($requestedUnits) !== array_map('intval', $ids)) {
                throw ValidationException::withMessages(['readings' => 'يجب أن تطابق القراءات المحددة جميع قراءات الفاتورة الأصلية.']);
            }

            \App\Models\Tariff::lockCatalog();
            $originalLines = DB::table('invoice_tariff_lines')->where('invoice_id', $invoice->id)
                ->orderBy('reading_id')->lockForUpdate()->get();
            // Never reconstruct a posted invoice's tariff from today's catalog.
            // Even a coincidentally equal total does not establish line-level provenance.
            try {
                HistoricalTariffEvidence::verify(
                    $readings->map(fn ($reading) => (object) [
                        'id' => $reading->id,
                        'period_start' => $reading->period_start ? \Carbon\CarbonImmutable::parse($reading->period_start)->toDateString() : null,
                        'period_end' => $reading->period_end ? \Carbon\CarbonImmutable::parse($reading->period_end)->toDateString() : null,
                        'consumption_units' => $reading->getRawOriginal('consumption_units'),
                    ]),
                    $originalLines,
                    $invoice->getRawOriginal('consumption_fee'),
                );
            } catch (\DomainException|\InvalidArgumentException|\OverflowException) {
                throw ValidationException::withMessages(['invoice' => 'أدلة التعرفة الأصلية مفقودة أو غير متطابقة؛ يلزم مراجعة محاسبية موثقة.']);
            }
            $source = 'original_invoice_snapshot';
            $lines = [];
            $prices = [];
            $u = 0;
            $amount = 0;
            foreach ($originalLines as $original) {
                $p = $minor($original->unit_price);
                if ($confirmedPrice !== null && $confirmedPrice !== $p) {
                    throw ValidationException::withMessages(['historical_unit_price' => 'السعر المدخل لا يطابق التعرفة التاريخية الموثقة.']);
                }
                $quantity = $requestedUnits[(int) $original->reading_id];
                try {
                    $lineAmount = ExactMoney::tariffLine($decimal($quantity), $decimal($p))->minor;
                    $u = ExactMoney::fromMinor(FinancialAmount::add($u, $quantity))->minor;
                    $amount = ExactMoney::fromMinor(FinancialAmount::add($amount, $lineAmount))->minor;
                } catch (\InvalidArgumentException|\OverflowException) {
                    throw ValidationException::withMessages(['readings' => 'المبلغ أو الوحدات أو التعرفة المصححة تتجاوز الحد المالي.']);
                }
                $prices[$p] = true;
                $line = array_intersect_key((array) $original, array_flip([
                    'reading_id', 'tariff_id', 'tariff_name', 'period_start', 'period_end',
                    'units', 'unit_price', 'amount',
                ]));
                $line['units'] = $decimal($quantity);
                $line['unit_price'] = $decimal($p);
                $line['amount'] = $decimal($lineAmount);
                $lines[] = $line;
            }
            $unitPrice = count($prices) === 1 ? $decimal((int) array_key_first($prices)) : null;

            $ledger = app(TransactionService::class);
            $before = $ledger->accountBalanceMinor($subscriber);
            $oldAmount = $minor($invoice->total_amount);
            $out = $ledger->reverseDocument($subscriber, $invoice, 'invoice');
            $invoice->forceFill(['status' => 'cancelled', 'voided_at' => now(), 'void_reason' => $reason,
                'voided_by' => $actor->id, 'amount_paid' => 0])->saveQuietly();

            // Do not move or duplicate historical readings. Replacement provenance
            // and the correction document reference the unchanged original.
            $allocator = app(PaymentService::class);
            $allocator->recalculateInvoices($subscriber);
            $previousMinor = InvoiceAmounts::outstandingMinor(Invoice::where('subscriber_id', $subscriber->id)
                ->whereNotIn('status', ['draft', 'cancelled'])->toBase()->get(['total_amount', 'amount_paid']));
            $previous = FinancialAmount::storageDecimal($previousMinor, 'previous_balance');
            $credit = $decimal(min($amount, max(0, $ledger->accountBalanceMinor($subscriber))));
            $issuedAt = now();
            $replacement = new Invoice([
                'subscriber_id' => $subscriber->id, 'meter_id' => null, 'invoice_type' => 'meter',
                'bill_number' => app(DocumentSequenceService::class)->reserveForIssue('INV', $issuedAt->format('Ym')), 'bill_date' => $issuedAt->toDateString(),
                'billing_period_start' => $invoice->billing_period_start, 'billing_period_end' => $invoice->billing_period_end,
                'consumption_fee' => $decimal($amount), 'total_amount' => $decimal($amount),
                'previous_balance' => $previous, 'credit_applied' => $credit,
                'amount_paid' => 0, 'status' => 'issued', 'notes' => 'تصحيح تاريخي للفاتورة '.$invoice->bill_number,
            ]);
            $replacement->forceFill(['retired_meter_id' => $invoice->retired_meter_id, 'retired_meter_code' => $invoice->retired_meter_code])->saveQuietly();
            DB::table('invoice_tariff_lines')->insert(array_map(fn ($line) => $line + ['invoice_id' => $replacement->id], $lines));
            InvoiceItem::create(['invoice_id' => $replacement->id, 'type' => 'consumption',
                'description' => 'استهلاك مصحح وفق تفاصيل التعرفات التاريخية', 'amount' => $decimal($amount)]);
            if ($previousMinor > 0) {
                InvoiceItem::create(['invoice_id' => $replacement->id, 'type' => 'previous_balance',
                    'description' => 'متأخرات سابقة', 'amount' => $previous]);
            }
            $correction = HistoricalInvoiceCorrection::create(['original_invoice_id' => $invoice->id,
                'replacement_invoice_id' => $replacement->id, 'approved_by' => $actor->id,
                'corrected_units' => $decimal($u), 'historical_unit_price' => $unitPrice,
                'corrected_amount' => $decimal($amount), 'reason' => $reason, 'evidence' => $evidence]);
            $in = $ledger->recordTransaction($subscriber, 'invoice', $decimal(-$amount),
                'فاتورة تاريخية مصححة '.$replacement->bill_number, $replacement);
            $allocator->recalculateInvoices($subscriber);
            $after = $ledger->accountBalanceMinor($subscriber);
            if ($after !== FinancialAmount::add($before, $oldAmount - $amount)) {
                throw new \RuntimeException('Historical correction does not conserve the account.');
            }
            app(AuditLogger::class)->recordRequired('invoice.historical_corrected', [
                'entity_type' => HistoricalInvoiceCorrection::class, 'entity_id' => $correction->id,
                'before' => ['balance_minor' => $before, 'invoice_id' => $invoice->id, 'amount_minor' => $oldAmount],
                'after' => ['balance_minor' => $after, 'replacement_invoice_id' => $replacement->id,
                    'reading_ids' => $readings->modelKeys(), 'pricing_source' => $source, 'pricing_lines' => $lines, 'reversal_id' => $out->id, 'posting_id' => $in->id,
                    'reason' => $reason, 'evidence' => $evidence, 'corrected_units' => $decimal($u), 'historical_unit_price' => $unitPrice],
                'actor_user_id' => $actor->id,
            ]);
            return $correction;
        }, 3);
    }
}
