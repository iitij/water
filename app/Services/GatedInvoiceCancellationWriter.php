<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Reading;
use App\Models\Subscriber;
use App\Models\Transaction;
use App\Models\User;
use App\Support\AccountMoney;
use App\Support\HistoricalTariffEvidence;
use App\Support\ObservationBillingChain;
use App\Support\SignedMinor;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Inactive candidate for cancelling registered current-meter observation invoices.
 * Reject cancellation if it would break the pending observation suffix.
 * Never erase the original invoice, pricing snapshots or physical receipts.
 * No retired-meter correction, historical adoption or production activation.
 */
final class GatedInvoiceCancellationWriter
{
    private const SOURCE = 'gated_invoice_cancel';

    public function __construct(
        private FinancialWriteGate $gate,
        private InvoiceCancellationService $cancellations,
        private FinancialLedgerEvidence $ledger,
        private FinancialPostingRegistry $registry,
        private AuditLogger $audit,
    ) {}

    public function cancel(User $actor, int $invoiceId, string $reason, string $uuid, DateTimeImmutable $now): array
    {
        $date = $now->setTimezone(new DateTimeZone('Asia/Aden'))->format('Y-m-d');
        $reason = trim($reason);

        return $this->gate->withinOpenPeriod('water', $date, $now,
            function (array $context) use ($actor, $invoiceId, $reason, $uuid, $now): array {
                $this->need(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1, 'posting_uuid');
                $this->need(mb_strlen($reason) >= 10 && mb_strlen($reason) <= 1000, 'gated_invoice_cancel_reason');
                $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null,
                    'gated_invoice_cancel_issuance_lock_missing');
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                $candidate = Invoice::query()->findOrFail($invoiceId);
                $subscriber = Subscriber::query()->lockForUpdate()->findOrFail($candidate->subscriber_id);
                $this->need($candidate->meter_id !== null && $candidate->retired_meter_id === null,
                    'gated_invoice_cancel_meter_unsupported');
                $meter = Meter::query()->where('subscriber_id', $subscriber->id)
                    ->lockForUpdate()->findOrFail($candidate->meter_id);
                $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);
                abort_unless($actor->hasRole('super_admin') || $actor->can('delete', $invoice), 403);
                $this->need((int) $invoice->subscriber_id === (int) $subscriber->id
                    && (int) $invoice->meter_id === (int) $meter->id && $invoice->retired_meter_id === null
                    && $meter->isCurrent() && $invoice->invoice_type === 'meter' && $invoice->status !== 'draft',
                    'gated_invoice_cancel_meter_unsupported');
                $original = $this->entry($invoiceId, 'invoice');
                $originalLeg = $this->ledger->leg((int) $original->id);
                $originalOp = $this->registeredOperation($originalLeg, $invoice->bill_date->toDateString());
                $this->registry->verifyPeriod('water', (int) $originalOp->period_id);
                $amount = AccountMoney::stored($invoice, 'total_amount');
                $this->need($amount >= 0 && $originalLeg['amount_minor'] === -$amount
                    && $amount === AccountMoney::stored($invoice, 'consumption_fee'), 'gated_invoice_cancel_amount');
                $pricing = $this->rows('invoice_tariff_lines', $invoiceId);
                $items = $this->rows('invoice_items', $invoiceId);
                $pricingHash = $this->digest([$pricing, $items]);
                $paymentRows = $this->rows('payments', $invoiceId);
                $balance = app(TransactionService::class);
                $before = $balance->accountBalanceMinor($subscriber);
                $operation = DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->lockForUpdate()->first();
                $replay = $invoice->status === 'cancelled';
                if ($replay) {
                    $this->need($operation !== null && (int) $invoice->voided_by === (int) $actor->id
                        && $invoice->void_reason === $reason, 'gated_invoice_cancel_operation_conflict');
                    $reversal = $this->entry($invoiceId, 'invoice_reversal');
                    $registered = $this->registeredOperation($this->ledger->leg((int) $reversal->id), $context['effective_on']);
                    $this->need($registered->operation_uuid === $uuid, 'gated_invoice_cancel_operation_conflict');
                } else {
                    $this->need($operation === null, 'gated_invoice_cancel_operation_conflict');
                    $readings = $invoice->readings()->orderBy('id')->lockForUpdate()->get();
                    $this->need($readings->isNotEmpty() && ! $readings->contains(fn ($r) => ! $r->is_invoiced
                        || (int) $r->meter_id !== (int) $meter->id || $r->retired_meter_id !== null
                        || $r->period_basis !== 'observation'), 'gated_invoice_cancel_readings_unsupported');
                    $ids = array_map('intval', $readings->modelKeys());
                    HistoricalTariffEvidence::verify($readings->map(fn ($r) => (object) [
                        'id' => $r->id,
                        'period_start' => CarbonImmutable::parse($r->period_start)->toDateString(),
                        'period_end' => CarbonImmutable::parse($r->period_end)->toDateString(),
                        'consumption_units' => $r->getRawOriginal('consumption_units'),
                    ]), array_map(fn ($row) => (object) $row, $pricing), $invoice->getRawOriginal('consumption_fee'));
                    $observations = $readings->map(fn ($r) => $this->observation($r))->all();
                    $invoice = $this->cancellations->cancel($invoice, $reason, (int) $actor->id, self::SOURCE);
                    $released = Reading::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
                    $this->need($released->count() === count($ids)
                        && ! $released->contains(fn ($r) => $r->is_invoiced || $r->invoice_id !== null)
                        && $released->map(fn ($r) => $this->observation($r))->all() === $observations,
                        'gated_invoice_cancel_reading_changed');
                    // Releasing an older billed interval can leave a later billed
                    // anchor after pending readings, making re-billing impossible.
                    $chain = Reading::query()->where('meter_id', $meter->id)->orderBy('id')->lockForUpdate()->get();
                    $pending = $chain->filter(fn ($r) => ! $r->is_invoiced)->values()->modelKeys();
                    try {
                        ObservationBillingChain::assertValid($chain->map(fn ($r) => $r->getRawOriginal())->all(),
                            $pending, $meter->getRawOriginal('installed_at') ?? $subscriber->getRawOriginal('subscription_start_date'),
                            $meter->getRawOriginal('initial_reading'));
                    } catch (\InvalidArgumentException $error) {
                        throw new DomainException('gated_invoice_cancel_chain_gap', 0, $error);
                    }
                    $reversal = $this->entry($invoiceId, 'invoice_reversal');
                    $this->audit->recordRequired('financial.invoice_cancel_registered', [
                        'entity_type' => Invoice::class, 'entity_id' => $invoiceId, 'actor_user_id' => $actor->id,
                        'source' => self::SOURCE, 'after' => ['operation_uuid' => $uuid,
                            'reading_ids' => $ids, 'pricing_hash' => $pricingHash, 'reason' => $reason,
                            'reversal_id' => (int) $reversal->id, 'amount_minor' => $amount],
                    ]);
                }
                $this->need($invoice->status === 'cancelled' && $invoice->voided_at?->toDateString() === $context['effective_on']
                    && (int) $invoice->voided_by === (int) $actor->id && $invoice->void_reason === $reason
                    && AccountMoney::stored($invoice, 'amount_paid') === 0
                    && $invoice->readings()->count() === 0
                    && $this->rows('payments', $invoiceId) === $paymentRows
                    && $this->digest([$this->rows('invoice_tariff_lines', $invoiceId), $this->rows('invoice_items', $invoiceId)]) === $pricingHash,
                    'gated_invoice_cancel_effect_mismatch');
                $proof = AuditEvent::query()->where('event', 'financial.invoice_cancel_registered')
                    ->where('entity_type', Invoice::class)->where('entity_id', (string) $invoiceId)->lockForUpdate()->sole();
                $decision = AuditEvent::query()->where('event', 'invoice.cancelled')
                    ->where('entity_type', Invoice::class)->where('entity_id', (string) $invoiceId)->lockForUpdate()->sole();
                $pricingIds = array_map(fn ($row) => (int) $row['reading_id'], $pricing);
                sort($pricingIds, SORT_NUMERIC);
                $decisionIds = array_map('intval', $decision->before['reading_ids'] ?? []);
                sort($decisionIds, SORT_NUMERIC);
                $this->need((int) $proof->actor_user_id === (int) $actor->id
                    && (int) $decision->actor_user_id === (int) $actor->id && $decision->source === self::SOURCE
                    && ($decision->after['reason'] ?? null) === $reason
                    && ($proof->after['operation_uuid'] ?? null) === $uuid
                    && ($proof->after['reason'] ?? null) === $reason
                    && ($proof->after['pricing_hash'] ?? null) === $pricingHash
                    && ($proof->after['reading_ids'] ?? null) === $pricingIds && $decisionIds === $pricingIds
                    && ($proof->after['reversal_id'] ?? null) === (int) $reversal->id
                    && ($proof->after['amount_minor'] ?? null) === $amount, 'gated_invoice_cancel_audit_mismatch');
                $leg = $this->ledger->leg((int) $reversal->id);
                $this->need($leg['amount_minor'] === $amount && $this->ledger->leg((int) $original->id) === $originalLeg
                    && $balance->accountBalanceMinor($subscriber) === SignedMinor::add($before, $replay ? 0 : $amount),
                    'gated_invoice_cancel_effect_mismatch');
                return ['invoice' => $invoice, 'reversal_id' => (int) $reversal->id,
                    'registry_created' => $this->registry->record($context, $uuid, [$leg], $now)];
            });
    }

    private function rows(string $table, int $invoiceId): array
    {
        return DB::table($table)->where('invoice_id', $invoiceId)->orderBy('id')->lockForUpdate()->get()
            ->map(fn ($row) => (array) $row)->all();
    }

    private function observation(Reading $reading): array
    {
        return array_diff_key($reading->getRawOriginal(), array_flip(['invoice_id', 'is_invoiced', 'updated_at']));
    }

    private function digest(array $rows): string
    {
        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function registeredOperation(array $leg, string $date): object
    {
        $event = DB::table('financial_posting_events')->where('scope_key', 'water')
            ->where('source_type', $leg['source_type'])->where('source_id', $leg['source_id'])
            ->where('leg', $leg['leg'])->lockForUpdate()->first();
        $this->need($event !== null && (string) $event->amount_minor === (string) $leg['amount_minor']
            && $event->currency === $leg['currency'] && hash_equals($leg['source_hash'], $event->source_hash),
            'gated_invoice_cancel_prerequisite_unregistered');
        $operation = DB::table('financial_posting_operations')->where('operation_uuid', $event->operation_uuid)
            ->lockForUpdate()->first();
        $this->need($operation !== null && $operation->scope_key === 'water' && $operation->effective_on === $date,
            'gated_invoice_cancel_prerequisite_unregistered');
        return $operation;
    }

    private function entry(int $id, string $type): Transaction
    {
        $entries = Transaction::withTrashed()->where('reference_type', Invoice::class)->where('reference_id', $id)
            ->where('type', $type)->lockForUpdate()->get();
        $this->need($entries->count() === 1 && ! $entries->first()->trashed(), 'gated_invoice_cancel_ledger_identity');
        return $entries->first();
    }

    private function need(bool $condition, string $reason): void
    {
        if (! $condition) throw new DomainException($reason);
    }
}
