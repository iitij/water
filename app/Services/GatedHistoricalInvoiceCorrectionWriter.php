<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\HistoricalInvoiceCorrection;
use App\Models\Invoice;
use App\Models\Subscriber;
use App\Models\Transaction;
use App\Models\User;
use App\Support\AccountMoney;
use App\Support\ExactMoney;
use App\Support\FinancialAmount;
use App\Support\HistoricalTariffEvidence;
use App\Support\SignedMinor;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Inactive correction candidate, limited to a registered retired-meter
 * invoice with complete original pricing snapshots. No legacy adoption.
 * The original consumption period is not the correction posting date.
 */
final class GatedHistoricalInvoiceCorrectionWriter
{
    public function __construct(
        private FinancialWriteGate $gate,
        private HistoricalInvoiceCorrectionService $corrections,
        private FinancialLedgerEvidence $ledger,
        private FinancialPostingRegistry $registry,
    ) {}

    public function correct(User $actor, int $invoiceId, array $readings, string $reason,
        string $evidence, string $uuid, DateTimeImmutable $now): array
    {
        $this->need(array_is_list($readings) && count($readings) > 0 && count($readings) <= 10000,
            'gated_correction_readings');
        $units = [];
        foreach ($readings as $row) {
            $this->need(is_array($row) && count($row) === 2 && is_int($row['reading_id'] ?? null)
                && $row['reading_id'] > 0 && ! isset($units[$row['reading_id']])
                && is_string($row['corrected_units'] ?? null)
                && preg_match('/\A[0-9]{1,8}(?:\.[0-9]{1,2})?\z/', $row['corrected_units']) === 1,
                'gated_correction_readings');
            $units[$row['reading_id']] = FinancialAmount::parse($row['corrected_units'])->minor;
        }
        ksort($units, SORT_NUMERIC);
        $reason = trim($reason);
        $evidence = trim($evidence);
        $date = $now->setTimezone(new DateTimeZone('Asia/Aden'))->format('Y-m-d');
        return $this->gate->withinOpenPeriod('water', $date, $now,
            function (array $context) use ($actor, $invoiceId, $units, $reason, $evidence, $uuid, $now): array {
                $this->need(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1, 'posting_uuid');
                $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null,
                    'gated_correction_issuance_lock_missing');
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                $candidate = Invoice::query()->findOrFail($invoiceId);
                $subscriber = Subscriber::query()->lockForUpdate()->findOrFail($candidate->subscriber_id);
                $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);
                abort_unless($actor->hasRole('super_admin') ||
                    ($actor->can('delete', $invoice) && $actor->can('create', Invoice::class)), 403);
                $this->need((int) $invoice->subscriber_id === (int) $subscriber->id
                    && $invoice->meter_id === null && (int) $invoice->retired_meter_id > 0
                    && ! HistoricalInvoiceCorrection::where('replacement_invoice_id', $invoiceId)->exists(),
                    'gated_correction_invoice_unsupported');
                $original = $this->entry($invoiceId, 'invoice');
                $originalLeg = $this->ledger->leg((int) $original->id);
                $originalOp = $this->registeredOperation($originalLeg, $invoice->bill_date->toDateString());
                $this->registry->verifyPeriod('water', (int) $originalOp->period_id);
                $originalReadings = $invoice->readings()->orderBy('id')->lockForUpdate()->get();
                $this->need(array_map('intval', $originalReadings->modelKeys()) === array_keys($units)
                    && ! $originalReadings->contains(fn ($r) => ! $r->is_invoiced || $r->meter_id !== null
                        || (int) $r->retired_meter_id !== (int) $invoice->retired_meter_id),
                    'gated_correction_reading_identity');
                $originalLines = DB::table('invoice_tariff_lines')->where('invoice_id', $invoiceId)
                    ->orderBy('reading_id')->lockForUpdate()->get();
                HistoricalTariffEvidence::verify($originalReadings->map(fn ($r) => (object) [
                    'id' => $r->id, 'period_start' => $r->period_start, 'period_end' => $r->period_end,
                    'consumption_units' => $r->getRawOriginal('consumption_units'),
                ]), $originalLines, $invoice->getRawOriginal('consumption_fee'));
                $originalMinor = AccountMoney::stored($invoice, 'total_amount');
                $this->need($originalMinor === AccountMoney::stored($invoice, 'consumption_fee'), 'gated_correction_original_amount');
                $existing = HistoricalInvoiceCorrection::query()->where('original_invoice_id', $invoiceId)
                    ->lockForUpdate()->first();
                $operation = DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->lockForUpdate()->first();
                if ($existing !== null) {
                    $this->need($operation !== null && (int) $existing->approved_by === (int) $actor->id
                        && $existing->reason === $reason && $existing->evidence === $evidence, 'gated_correction_operation_conflict');
                    foreach ([$this->entry($invoiceId, 'invoice_reversal'),
                        $this->entry((int) $existing->replacement_invoice_id, 'invoice')] as $entry) {
                        $registered = $this->registeredOperation($this->ledger->leg((int) $entry->id), $context['effective_on']);
                        $this->need($registered->operation_uuid === $uuid, 'gated_correction_operation_conflict');
                    }
                } else {
                    $this->need($operation === null, 'gated_correction_operation_conflict');
                }
                $readingBytes = $originalReadings->map(fn ($r) => $r->getRawOriginal())->all();
                $payments = DB::table('payments')->where('invoice_id', $invoiceId)->orderBy('id')->lockForUpdate()->get()
                    ->map(fn ($r) => (array) $r)->all();
                $balance = app(TransactionService::class);
                $before = $balance->accountBalanceMinor($subscriber);
                $request = [];
                foreach ($units as $id => $minor) {
                    $request[] = ['reading_id' => $id, 'corrected_units' => ExactMoney::fromMinor($minor)->decimal()];
                }
                $correction = $this->corrections->correctReadings($invoice, $actor, $request, $reason, $evidence);
                $replacement = Invoice::query()->lockForUpdate()->findOrFail($correction->replacement_invoice_id);
                $invoice = $invoice->fresh();
                $this->need((int) $correction->original_invoice_id === $invoiceId
                    && (int) $correction->approved_by === (int) $actor->id
                    && $correction->reason === $reason && $correction->evidence === $evidence
                    && $invoice->status === 'cancelled' && (int) $invoice->voided_by === (int) $actor->id
                    && $invoice->void_reason === $reason && $invoice->voided_at?->toDateString() === $context['effective_on']
                    && $replacement->bill_date->toDateString() === $context['effective_on']
                    && $correction->created_at?->toDateString() === $context['effective_on']
                    && (int) $replacement->subscriber_id === (int) $subscriber->id
                    && $replacement->meter_id === null && (int) $replacement->retired_meter_id === (int) $invoice->retired_meter_id
                    && $replacement->billing_period_start->toDateString() === $invoice->billing_period_start->toDateString()
                    && $replacement->billing_period_end->toDateString() === $invoice->billing_period_end->toDateString(),
                    'gated_correction_document_mismatch');
                $newLines = DB::table('invoice_tariff_lines')->where('invoice_id', $replacement->id)
                    ->orderBy('reading_id')->lockForUpdate()->get();
                $this->need($newLines->count() === $originalLines->count(), 'gated_correction_pricing_mismatch');
                $total = ExactMoney::fromMinor(0);
                $quantity = ExactMoney::fromMinor(0);
                foreach ($originalLines as $i => $old) {
                    $new = $newLines[$i];
                    foreach (['reading_id', 'tariff_id', 'tariff_name', 'period_start', 'period_end'] as $field) {
                        $this->need($new->$field === $old->$field, 'gated_correction_pricing_mismatch');
                    }
                    $q = ExactMoney::fromMinor($units[(int) $old->reading_id]);
                    $price = FinancialAmount::parse($old->unit_price);
                    $line = ExactMoney::tariffLine($q->decimal(), $price->decimal());
                    $this->need(FinancialAmount::parse($new->units)->minor === $q->minor
                        && FinancialAmount::parse($new->unit_price)->minor === $price->minor
                        && FinancialAmount::parse($new->amount)->minor === $line->minor, 'gated_correction_pricing_mismatch');
                    $total = $total->plus($line);
                    $quantity = $quantity->plus($q);
                }
                $this->need($total->minor === AccountMoney::stored($replacement, 'total_amount')
                    && $total->minor === AccountMoney::stored($replacement, 'consumption_fee')
                    && $total->minor === AccountMoney::stored($correction, 'corrected_amount')
                    && $quantity->minor === FinancialAmount::parse($correction->corrected_units)->minor,
                    'gated_correction_pricing_mismatch');
                $this->need($invoice->readings()->orderBy('id')->lockForUpdate()->get()
                    ->map(fn ($r) => $r->getRawOriginal())->all() === $readingBytes
                    && DB::table('payments')->where('invoice_id', $invoiceId)->orderBy('id')->lockForUpdate()->get()
                        ->map(fn ($r) => (array) $r)->all() === $payments, 'gated_correction_original_links_changed');
                $out = $this->entry($invoiceId, 'invoice_reversal');
                $in = $this->entry((int) $replacement->id, 'invoice');
                $outLeg = $this->ledger->leg((int) $out->id);
                $inLeg = $this->ledger->leg((int) $in->id);
                $this->need($outLeg['amount_minor'] === $originalMinor && $inLeg['amount_minor'] === -$total->minor
                    && $this->ledger->leg((int) $original->id) === $originalLeg, 'gated_correction_effect_mismatch');
                $delta = $existing === null ? SignedMinor::subtract($originalMinor, $total->minor) : 0;
                $this->need($balance->accountBalanceMinor($subscriber) === SignedMinor::add($before, $delta),
                    'gated_correction_balance_mismatch');
                $audit = AuditEvent::query()->where('event', 'invoice.historical_corrected')
                    ->where('entity_type', HistoricalInvoiceCorrection::class)->where('entity_id', (string) $correction->id)
                    ->lockForUpdate()->sole();
                $this->need((int) $audit->actor_user_id === (int) $actor->id
                    && ($audit->after['reason'] ?? null) === $reason && ($audit->after['evidence'] ?? null) === $evidence
                    && ($audit->after['pricing_source'] ?? null) === 'original_invoice_snapshot'
                    && (int) ($audit->after['reversal_id'] ?? 0) === (int) $out->id
                    && (int) ($audit->after['posting_id'] ?? 0) === (int) $in->id,
                    'gated_correction_audit_mismatch');
                return ['correction' => $correction, 'invoice' => $replacement,
                    'reversal_id' => (int) $out->id, 'posting_id' => (int) $in->id,
                    'registry_created' => $this->registry->record($context, $uuid, [$outLeg, $inLeg], $now)];
            });
    }

    private function registeredOperation(array $leg, string $date): object
    {
        $event = DB::table('financial_posting_events')->where('scope_key', 'water')
            ->where('source_type', $leg['source_type'])->where('source_id', $leg['source_id'])
            ->where('leg', $leg['leg'])->lockForUpdate()->first();
        $this->need($event !== null && (string) $event->amount_minor === (string) $leg['amount_minor']
            && $event->currency === $leg['currency'] && hash_equals($leg['source_hash'], $event->source_hash),
            'gated_correction_prerequisite_unregistered');
        $operation = DB::table('financial_posting_operations')->where('operation_uuid', $event->operation_uuid)
            ->lockForUpdate()->first();
        $this->need($operation !== null && $operation->scope_key === 'water' && $operation->effective_on === $date,
            'gated_correction_prerequisite_unregistered');
        return $operation;
    }

    private function entry(int $id, string $type): Transaction
    {
        $entries = Transaction::withTrashed()->where('reference_type', Invoice::class)
            ->where('reference_id', $id)->where('type', $type)->lockForUpdate()->get();
        $this->need($entries->count() === 1 && ! $entries->first()->trashed(), 'gated_correction_ledger_identity');
        return $entries->first();
    }

    private function need(bool $condition, string $reason): void
    {
        if (! $condition) throw new DomainException($reason);
    }
}
