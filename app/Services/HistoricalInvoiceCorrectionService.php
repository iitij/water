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
        $reason = trim($reason);
        $evidence = trim($evidence);
        $validNumber = static fn ($v): bool => (is_string($v) || is_int($v) || is_float($v))
            && preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', (string) $v) === 1;
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 1000
            || mb_strlen($evidence) < 10 || mb_strlen($evidence) > 2000
            || ($requested === null && (! $validNumber($units) || ! $validNumber($price)))) {
            throw ValidationException::withMessages(['correction' => 'يلزم توثيق الوحدات والتعرفة التاريخية وسبب التصحيح.']);
        }
        // DECIMAL values from the database may be numeric on SQLite.
        $minor = static fn ($v): int => (int) round((float) $v * 100);
        $decimal = static fn (int $v): string => intdiv($v, 100).'.'.str_pad((string) ($v % 100), 2, '0', STR_PAD_LEFT);
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
            if ($originalLines->isEmpty()) {
                $originalLines = collect(app(HistoricalTariffService::class)->quote($readings)['lines'])
                    ->map(fn ($line) => (object) $line);
                $source = 'approved_historical_catalog';
            } else {
                $lineIds = $originalLines->pluck('reading_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
                if ($lineIds !== array_map('intval', $ids)
                    || $originalLines->sum(fn ($line) => $minor($line->amount)) !== $minor($invoice->consumption_fee)) {
                    throw ValidationException::withMessages(['invoice' => 'أدلة تسعير الفاتورة لا تطابق القراءات أو المبلغ التاريخي.']);
                }
                $source = 'original_invoice_snapshot';
            }
            $readingById = $readings->keyBy('id');
            $lines = [];
            $prices = [];
            $u = 0;
            $amount = 0;
            foreach ($originalLines as $original) {
                $reading = $readingById->get($original->reading_id);
                if (! $reading || ! $reading->period_start || ! $reading->period_end
                    || $original->period_start !== \Carbon\CarbonImmutable::parse($reading->period_start)->toDateString()
                    || $original->period_end !== \Carbon\CarbonImmutable::parse($reading->period_end)->toDateString()) {
                    throw ValidationException::withMessages(['invoice' => 'توجد أدلة تاريخية غير متطابقة؛ يلزم مراجعة محاسبية.']);
                }
                $p = $minor($original->unit_price);
                if ($confirmedPrice !== null && $confirmedPrice !== $p) {
                    throw ValidationException::withMessages(['historical_unit_price' => 'السعر المدخل لا يطابق التعرفة التاريخية الموثقة.']);
                }
                $quantity = $requestedUnits[(int) $reading->id];
                if ($p < 0 || ($p > 0 && $quantity > intdiv(PHP_INT_MAX - 50, $p))) {
                    throw ValidationException::withMessages(['readings' => 'الوحدات أو التعرفة خارج النطاق المالي.']);
                }
                $lineAmount = intdiv($quantity * $p + 50, 100);
                if ($lineAmount > 999999999999 - $amount || $quantity > 999999999999 - $u) {
                    throw ValidationException::withMessages(['readings' => 'المبلغ أو الوحدات المصححة تتجاوز الحد المالي.']);
                }
                $u += $quantity;
                $amount += $lineAmount;
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
            DB::table('invoice_tariff_lines')->insert(array_map(fn ($line) => $line + ['invoice_id' => $replacement->id], $lines));
            InvoiceItem::create(['invoice_id' => $replacement->id, 'type' => 'consumption',
                'description' => 'استهلاك مصحح وفق تفاصيل التعرفات التاريخية', 'amount' => $decimal($amount)]);
            if ($previous > 0) {
                InvoiceItem::create(['invoice_id' => $replacement->id, 'type' => 'previous_balance',
                    'description' => 'متأخرات سابقة', 'amount' => $previous]);
            }
            $correction = HistoricalInvoiceCorrection::create(['original_invoice_id' => $invoice->id,
                'replacement_invoice_id' => $replacement->id, 'approved_by' => $actor->id,
                'corrected_units' => $decimal($u), 'historical_unit_price' => $unitPrice,
                'corrected_amount' => $decimal($amount), 'reason' => $reason, 'evidence' => $evidence]);
            $in = $ledger->recordTransaction($subscriber, 'invoice', -($amount / 100),
                'فاتورة تاريخية مصححة '.$replacement->bill_number, $replacement);
            $allocator->recalculateInvoices($subscriber);
            $after = $minor($subscriber->getAccountBalance());
            if ($after !== $before + $oldAmount - $amount) {
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
