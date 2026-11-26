<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\PaymentAccountTransfer;
use App\Models\Subscriber;
use App\Models\Transaction;
use App\Models\User;
use App\Support\AccountMoney;
use App\Support\SignedMinor;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Inactive, prospective invoice and receipt-transfer adapters.
 * Never adopt an existing unregistered document or modify its original entry.
 * Operation UUID is a server-bound idempotency key; no production caller yet.
 */
final class GatedInvoiceTransferWriter
{
    private ?array $transferIdentity = null;
    private ?array $invoiceIdentity = null;

    public function __construct(
        private FinancialWriteGate $gate,
        private FinancialLedgerEvidence $ledger,
        private FinancialPostingRegistry $registry,
    ) {}

    public function issue(User $actor, int $subscriberId, int $meterId, string $uuid,
        DateTimeImmutable $now, ?string $notes = null, ?array $selectedReadingIds = null, ?int $autoReadingId = null): array
    {
        if ($selectedReadingIds !== null) {
            $this->need($selectedReadingIds !== [] && count($selectedReadingIds) === count(array_unique($selectedReadingIds)), 'gated_invoice_reading_selection');
            foreach ($selectedReadingIds as $id) $this->need(is_int($id) && $id > 0, 'gated_invoice_reading_selection');
            sort($selectedReadingIds, SORT_NUMERIC);
        }
        return $this->within($actor, $uuid, $now, function (User $actor, array $context) use ($subscriberId, $meterId, $uuid, $now, $notes, $selectedReadingIds, $autoReadingId): array {
            abort_unless($actor->hasRole('super_admin') || $actor->can('Create:Invoice'), 403);
            $subscriber = Subscriber::query()->lockForUpdate()->findOrFail($subscriberId);
            $meter = Meter::query()->where('subscriber_id', $subscriberId)->lockForUpdate()->findOrFail($meterId);
            $op = $this->operation($uuid);
            if ($autoReadingId !== null) {
                app(ProspectiveReadingAutoInvoice::class)->assertIntent($actor, $autoReadingId, $meterId,
                    $uuid, $notes, $selectedReadingIds, $op !== null);
            }
            if ($op !== null) {
                // The immutable request proof binds caller, meter, subscriber and notes.
                $event = $this->eventFor($uuid, 'invoice');
                $entry = Transaction::query()->lockForUpdate()->findOrFail((int) $event->source_id);
                $invoice = Invoice::query()->lockForUpdate()->findOrFail($entry->reference_id);
                $this->need($entry->reference_type === Invoice::class && $entry->type === 'invoice'
                    && (int) $invoice->subscriber_id === $subscriberId && (int) $invoice->meter_id === $meterId
                    && $invoice->bill_date->toDateString() === $context['effective_on']
                    && $invoice->notes === $notes, 'gated_invoice_replay_conflict');
                $this->checkIssueAudit($invoice, $actor, $uuid, $selectedReadingIds);
                $created = $this->registry->record($context, $uuid, [$this->ledger->leg((int) $entry->id)], $now);
                $this->need(! $created, 'gated_invoice_replay_conflict');
                return ['invoice' => $invoice, 'registry_created' => false];
            }
            if ($selectedReadingIds !== null) {
                $selected = \App\Models\Reading::query()->where('meter_id', $meterId)
                    ->whereIn('id', $selectedReadingIds)->where('is_invoiced', false)
                    ->orderBy('id')->lockForUpdate()->get();
                $this->need($selected->count() === count($selectedReadingIds), 'gated_invoice_reading_selection');
            }
            $balance = app(TransactionService::class);
            $before = $balance->accountBalanceMinor($subscriber);
            $this->need($this->invoiceIdentity === null && DB::transactionLevel() > 0, 'gated_invoice_mutation_scope');
            $this->invoiceIdentity = ['issue', $subscriberId, $meterId];
            try {
                $invoice = app(InvoiceService::class)->generateInvoice($subscriber, $meter, $notes, true, 'meter', $this);
            } finally {
                $this->invoiceIdentity = null;
            }
            $entry = $this->entry(Invoice::class, (int) $invoice->id, 'invoice');
            $leg = $this->ledger->leg((int) $entry->id);
            $this->need($invoice->bill_date->toDateString() === $context['effective_on']
                && (int) $invoice->subscriber_id === $subscriberId && (int) $invoice->meter_id === $meterId
                && $leg['amount_minor'] === -AccountMoney::stored($invoice, 'total_amount')
                && $balance->accountBalanceMinor($subscriber) === SignedMinor::add($before, $leg['amount_minor']),
                'gated_invoice_effect_mismatch');
            app(AuditLogger::class)->recordRequired('financial.invoice_issued', [
                'entity_type' => Invoice::class, 'entity_id' => $invoice->id, 'actor_user_id' => $actor->id,
                'after' => ['operation_uuid' => $uuid, 'subscriber_id' => $subscriberId, 'meter_id' => $meterId, 'notes' => $notes,
                    'selected_reading_ids' => $selectedReadingIds],
            ]);
            $this->checkIssueAudit($invoice, $actor, $uuid, $selectedReadingIds);
            $this->need($this->registry->record($context, $uuid, [$leg], $now), 'gated_invoice_operation_conflict');
            return ['invoice' => $invoice, 'registry_created' => true];
        });
    }

    public function cancel(User $actor, int $invoiceId, string $reason, string $uuid, DateTimeImmutable $now): array
    {
        return $this->within($actor, $uuid, $now, function (User $actor, array $context) use ($invoiceId, $reason, $uuid, $now): array {
            $candidate = Invoice::query()->findOrFail($invoiceId);
            $subscriber = Subscriber::query()->lockForUpdate()->findOrFail($candidate->subscriber_id);
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);
            abort_unless($actor->hasRole('super_admin') || $actor->can('Delete:Invoice'), 403);
            $this->need((int) $invoice->subscriber_id === (int) $subscriber->id && $invoice->status !== 'draft', 'gated_invoice_identity');
            $original = $this->entry(Invoice::class, $invoiceId, 'invoice');
            $this->registered($this->ledger->leg((int) $original->id), $invoice->bill_date->toDateString());
            $replay = $invoice->status === 'cancelled';
            $this->need($replay === ($this->operation($uuid) !== null), 'gated_invoice_operation_conflict');
            if ($replay) {
                $this->need((int) $invoice->voided_by === (int) $actor->id && $invoice->void_reason === trim($reason)
                    && $invoice->voided_at->toDateString() === $context['effective_on'], 'gated_invoice_replay_conflict');
                $this->owned($this->entry(Invoice::class, $invoiceId, 'invoice_reversal'), $uuid, $context['effective_on']);
            }
            $balance = app(TransactionService::class);
            $before = $balance->accountBalanceMinor($subscriber);
            $this->need($this->invoiceIdentity === null && DB::transactionLevel() > 0, 'gated_invoice_mutation_scope');
            $this->invoiceIdentity = ['cancel', $invoiceId, (int) $actor->id];
            try {
                $invoice = app(InvoiceCancellationService::class)->cancel($invoice, $reason, (int) $actor->id, 'api', $this);
            } finally {
                $this->invoiceIdentity = null;
            }
            $leg = $this->ledger->leg((int) $this->entry(Invoice::class, $invoiceId, 'invoice_reversal')->id);
            $this->need($invoice->status === 'cancelled' && $leg['amount_minor'] === AccountMoney::stored($invoice, 'total_amount')
                && $balance->accountBalanceMinor($subscriber) === SignedMinor::add($before, $replay ? 0 : $leg['amount_minor']),
                'gated_invoice_effect_mismatch');
            $created = $this->registry->record($context, $uuid, [$leg], $now);
            $this->need($created === ! $replay, 'gated_invoice_operation_conflict');
            return ['invoice' => $invoice, 'registry_created' => $created];
        });
    }

    public function transfer(User $actor, int $paymentId, int $destinationId, string $reason, string $evidence,
        string $uuid, DateTimeImmutable $now): array
    {
        return $this->within($actor, $uuid, $now, function (User $actor, array $context) use ($paymentId, $destinationId, $reason, $evidence, $uuid, $now): array {
            $candidate = Payment::query()->findOrFail($paymentId);
            $accounts = Subscriber::query()->whereIn('id', [$candidate->subscriber_id, $destinationId])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $from = $accounts->get($candidate->subscriber_id);
            $to = $accounts->get($destinationId);
            $this->need($from !== null && $to !== null && $from->id !== $to->id, 'gated_transfer_accounts');
            $payment = Payment::query()->lockForUpdate()->findOrFail($paymentId);
            $this->need((int) $payment->subscriber_id === (int) $from->id, 'gated_transfer_identity');
            $original = $this->entry(Payment::class, $paymentId, 'payment');
            $this->registered($this->ledger->leg((int) $original->id), $payment->payment_date->toDateString());
            $existing = PaymentAccountTransfer::query()->where('payment_id', $paymentId)->lockForUpdate()->first();
            $replay = $existing !== null;
            $this->need($replay === ($this->operation($uuid) !== null), 'gated_transfer_operation_conflict');
            if ($replay) {
                $this->need((int) $existing->approved_by === (int) $actor->id
                    && (int) $existing->to_subscriber_id === $destinationId && $existing->reason === trim($reason)
                    && $existing->evidence === trim($evidence)
                    && $existing->created_at->toDateString() === $context['effective_on'], 'gated_transfer_replay_conflict');
                $this->owned($this->entry(Payment::class, $paymentId, 'payment_reversal'), $uuid, $context['effective_on']);
                $this->owned($this->entry(PaymentAccountTransfer::class, (int) $existing->id, 'payment_transfer_in'), $uuid, $context['effective_on']);
            }
            $balance = app(TransactionService::class);
            $beforeFrom = $balance->accountBalanceMinor($from);
            $beforeTo = $balance->accountBalanceMinor($to);
            $this->need($this->transferIdentity === null && DB::transactionLevel() > 0, 'gated_subscriber_transfer_mutation_scope');
            $this->transferIdentity = [$paymentId, $destinationId, (int) $actor->id];
            try {
                $transfer = app(PaymentAccountTransferService::class)->transfer($payment, $destinationId, $actor, $reason, $evidence, $this);
            } finally {
                $this->transferIdentity = null;
            }
            $out = $this->ledger->leg((int) $this->entry(Payment::class, $paymentId, 'payment_reversal')->id);
            $in = $this->ledger->leg((int) $this->entry(PaymentAccountTransfer::class, (int) $transfer->id, 'payment_transfer_in')->id);
            $minor = AccountMoney::stored($payment);
            $this->need($minor > 0 && $out['amount_minor'] === -$minor && $in['amount_minor'] === $minor
                && $balance->accountBalanceMinor($from) === SignedMinor::add($beforeFrom, $replay ? 0 : -$minor)
                && $balance->accountBalanceMinor($to) === SignedMinor::add($beforeTo, $replay ? 0 : $minor),
                'gated_transfer_effect_mismatch');
            $created = $this->registry->record($context, $uuid, [$out, $in], $now);
            $this->need($created === ! $replay, 'gated_transfer_operation_conflict');
            return ['transfer' => $transfer, 'registry_created' => $created];
        });
    }

    public function allowsIssue(int $subscriberId, int $meterId): bool
    {
        return DB::transactionLevel() > 0 && $this->invoiceIdentity === ['issue', $subscriberId, $meterId];
    }

    public function allowsInvoiceReversal(int $invoiceId): bool
    {
        return DB::transactionLevel() > 0 && $this->invoiceIdentity !== null
            && $this->invoiceIdentity[0] === 'cancel' && $this->invoiceIdentity[1] === $invoiceId;
    }

    public function allowsCancellation(int $invoiceId, ?int $actorId): bool
    {
        return DB::transactionLevel() > 0 && $this->invoiceIdentity === ['cancel', $invoiceId, $actorId];
    }

    public function allowsReceiptReversal(int $paymentId): bool
    {
        return DB::transactionLevel() > 0 && $this->transferIdentity !== null
            && $this->transferIdentity[0] === $paymentId;
    }

    public function allowsTransfer(int $paymentId, int $destinationId, int $actorId): bool
    {
        return DB::transactionLevel() > 0 && $this->transferIdentity === [$paymentId, $destinationId, $actorId];
    }

    private function within(User $actor, string $uuid, DateTimeImmutable $now, callable $action): array
    {
        $date = $now->setTimezone(new DateTimeZone('Asia/Aden'))->format('Y-m-d');
        return $this->gate->withinOpenPeriod('water', $date, $now, function (array $context) use ($actor, $uuid, $action): array {
            $this->need(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1, 'posting_uuid');
            DB::table('financial_periods')->where('scope_key', 'water')->orderBy('id')->lockForUpdate()->get();
            $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null, 'gated_document_issuance_lock');
            return $action(User::query()->lockForUpdate()->findOrFail($actor->id), $context);
        });
    }

    private function checkIssueAudit(Invoice $invoice, User $actor, string $uuid, ?array $selectedReadingIds): void
    {
        $audit = \App\Models\AuditEvent::query()->where('event', 'financial.invoice_issued')
            ->where('entity_type', Invoice::class)->where('entity_id', (string) $invoice->id)->lockForUpdate()->sole();
        $this->need((int) $audit->actor_user_id === (int) $actor->id
            && ($audit->after['operation_uuid'] ?? null) === $uuid
            && (int) ($audit->after['subscriber_id'] ?? 0) === (int) $invoice->subscriber_id
            && (int) ($audit->after['meter_id'] ?? 0) === (int) $invoice->meter_id
            && ($audit->after['selected_reading_ids'] ?? null) === $selectedReadingIds
            && array_key_exists('notes', $audit->after) && $audit->after['notes'] === $invoice->notes, 'gated_invoice_replay_conflict');
    }

    private function eventFor(string $uuid, string $type): object
    {
        $events = DB::table('financial_posting_events')->where('operation_uuid', $uuid)->lockForUpdate()->get();
        $this->need($events->count() === 1 && $events[0]->source_type === 'ledger_transaction'
            && $events[0]->leg === 'subscriber', 'gated_invoice_replay_conflict');
        return $events[0];
    }

    private function operation(string $uuid): ?object
    {
        return DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->lockForUpdate()->first();
    }

    private function entry(string $class, int $id, string $type): Transaction
    {
        return Transaction::query()->where('reference_type', $class)->where('reference_id', $id)
            ->where('type', $type)->lockForUpdate()->sole();
    }

    private function owned(Transaction $entry, string $uuid, string $date): void
    {
        $op = $this->registered($this->ledger->leg((int) $entry->id), $date);
        $this->need($op->operation_uuid === $uuid, 'gated_document_operation_conflict');
    }

    private function registered(array $leg, string $date): object
    {
        $event = DB::table('financial_posting_events')->where('scope_key', 'water')
            ->where('source_type', $leg['source_type'])->where('source_id', $leg['source_id'])
            ->where('leg', $leg['leg'])->lockForUpdate()->first();
        $this->need($event !== null && (string) $event->amount_minor === (string) $leg['amount_minor']
            && $event->currency === $leg['currency'] && hash_equals($event->source_hash, $leg['source_hash']), 'gated_document_prerequisite_unregistered');
        $op = $this->operation($event->operation_uuid);
        $this->need($op !== null && $op->scope_key === 'water' && $op->effective_on === $date, 'gated_document_prerequisite_unregistered');
        $this->registry->verifyPeriod('water', (int) $op->period_id);
        return $op;
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
