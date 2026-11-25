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
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Inactive writer candidate for ordinary water invoices.
 * Explicit complete pending-reading set, current issue date (not consumption date),
 * one stable operation UUID. Never adopt an existing unregistered invoice.
 * All financial writers must be fenced before this can be activated.
 */
final class GatedInvoiceWriter
{
    public function __construct(
        private FinancialWriteGate $gate,
        private InvoiceService $invoices,
        private FinancialLedgerEvidence $evidence,
        private FinancialPostingRegistry $registry,
        private AuditLogger $audit,
    ) {}

    public function issue(User $actor, int $subscriberId, int $meterId, array $readingIds,
        string $uuid, DateTimeImmutable $now): array
    {
        $this->need(array_is_list($readingIds) && count($readingIds) > 0 && count($readingIds) <= 10000,
            'gated_invoice_readings_invalid');
        foreach ($readingIds as $id) {
            $this->need(is_int($id) && $id > 0, 'gated_invoice_readings_invalid');
        }
        $this->need(count(array_unique($readingIds)) === count($readingIds), 'gated_invoice_readings_invalid');
        sort($readingIds, SORT_NUMERIC);
        $date = $now->setTimezone(new DateTimeZone('Asia/Aden'))->format('Y-m-d');

        return $this->gate->withinOpenPeriod('water', $date, $now,
            function (array $context) use ($actor, $subscriberId, $meterId, $readingIds, $uuid, $now): array {
                $this->need(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1,
                    'posting_uuid');
                $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null,
                    'gated_invoice_issuance_lock_missing');
                $subscriber = Subscriber::query()->lockForUpdate()->findOrFail($subscriberId);
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                abort_unless($actor->hasRole('super_admin') || $actor->can('Create:Invoice'), 403);
                $operation = DB::table('financial_posting_operations')->where('operation_uuid', $uuid)
                    ->lockForUpdate()->first();

                // Do not consume a receipt identity reserved by an unposted
                // claim, late review or pre-registry receipt. The issuance
                // lock serializes these checks with their current services.
                $this->need(! \App\Models\Payment::withTrashed()->where('sync_uuid', $uuid)->lockForUpdate()->first()
                    && ! DB::table('noncash_collection_claims')->where('sync_uuid', $uuid)->lockForUpdate()->first()
                    && ! DB::table('late_receipt_reviews')->where('sync_uuid', $uuid)->lockForUpdate()->first(),
                    'gated_invoice_uuid_reserved');

                if ($operation !== null) {
                    $events = DB::table('financial_posting_events')->where('operation_uuid', $uuid)
                        ->lockForUpdate()->get();
                    $this->need($operation->scope_key === 'water' && $events->count() === 1,
                        'gated_invoice_operation_conflict');
                    $event = $events->first();
                    $id = $event->source_id;
                    $this->need($event->scope_key === 'water' && $event->source_type === 'ledger_transaction'
                        && $event->leg === 'subscriber' && is_string($id)
                        && preg_match('/\A[1-9][0-9]*\z/', $id) === 1 && (string) (int) $id === $id,
                        'gated_invoice_operation_conflict');
                    $entry = Transaction::query()->lockForUpdate()->findOrFail((int) $id);
                    $this->need($entry->type === 'invoice' && $entry->reference_type === Invoice::class,
                        'gated_invoice_operation_conflict');
                    $invoice = Invoice::query()->lockForUpdate()->findOrFail($entry->reference_id);
                    $this->need((int) $invoice->subscriber_id === $subscriberId
                        && (int) ($invoice->meter_id ?? $invoice->retired_meter_id) === $meterId
                        && $this->billedIds($invoice) === $readingIds, 'gated_invoice_operation_conflict');
                    $audits = AuditEvent::query()->where('event', 'financial.gated_invoice_issued')
                        ->where('entity_type', Invoice::class)->where('entity_id', (string) $invoice->id)
                        ->lockForUpdate()->get();
                    $this->need($audits->count() === 1
                        && (int) $audits->first()->actor_user_id === (int) $actor->id
                        && ($audits->first()->after['operation_uuid'] ?? null) === $uuid
                        && ($audits->first()->after['reading_ids'] ?? null) === $readingIds,
                        'gated_invoice_operation_conflict');
                } else {
                    $meter = Meter::query()->where('subscriber_id', $subscriberId)->lockForUpdate()->findOrFail($meterId);
                    $pending = Reading::query()->where('meter_id', $meterId)->where('is_invoiced', false)
                        ->orderBy('id')->lockForUpdate()->get()->modelKeys();
                    $pending = array_map('intval', $pending);
                    $this->need($pending === $readingIds, 'gated_invoice_pending_set_changed');
                    $invoice = $this->invoices->generateInvoice($subscriber, $meter);
                    $this->need($this->billedIds($invoice) === $readingIds, 'gated_invoice_billed_set_changed');
                    $entry = Transaction::where('reference_type', Invoice::class)->where('reference_id', $invoice->id)
                        ->where('type', 'invoice')->lockForUpdate()->sole();
                    $audit = $this->audit->recordRequired('financial.gated_invoice_issued', [
                        'entity_type' => Invoice::class, 'entity_id' => $invoice->id,
                        'actor_user_id' => $actor->id, 'after' => ['operation_uuid' => $uuid, 'reading_ids' => $readingIds],
                    ]);
                    $this->need($audit->exists && $audit->getKey() !== null, 'gated_invoice_audit_missing');
                }
                $this->need($invoice->bill_date->toDateString() === $context['effective_on'], 'gated_invoice_date_mismatch');
                $leg = $this->evidence->leg((int) $entry->id);
                $this->need($leg['amount_minor'] === -AccountMoney::stored($invoice, 'total_amount'),
                    'gated_invoice_amount_mismatch');
                return ['invoice' => $invoice, 'transaction_id' => (int) $entry->id,
                    'registry_created' => $this->registry->record($context, $uuid, [$leg], $now)];
            });
    }

    private function billedIds(Invoice $invoice): array
    {
        return array_map('intval', Reading::query()->where('invoice_id', $invoice->id)
            ->where('is_invoiced', true)->orderBy('id')->lockForUpdate()->get()->modelKeys());
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
