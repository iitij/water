<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Payment;
use App\Models\Subscriber;
use App\Models\Transaction;
use App\Models\User;
use App\Support\AccountMoney;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Internal candidate for ordinary current-day cash receipts only.
 * No late or noncash claim, historical adoption, or production route activation.
 * A single stable UUID identifies both the receipt and its registry operation.
 */
final class GatedCashReceiptWriter
{
    private ?array $mutationIdentity = null;

    public function __construct(
        private FinancialWriteGate $gate,
        private PaymentService $payments,
        private FinancialLedgerEvidence $evidence,
        private FinancialPostingRegistry $registry,
    ) {}

    public function collect(User $actor, int $subscriberId, string $amount, string $uuid,
        DateTimeImmutable $now, ?int $cashSessionId = null, ?int $invoiceId = null,
        ?int $meterId = null, ?string $reference = null, ?string $reason = null,
        ?string $notes = null, ?string $effectiveOn = null, bool $deferred = false): array
    {
        $today = $now->setTimezone(new DateTimeZone('Asia/Aden'))->format('Y-m-d');
        $date = $effectiveOn ?? $today;
        $this->need(! $deferred || ($cashSessionId !== null && $effectiveOn !== null), 'gated_cash_deferred_identity_required');
        return $this->gate->withinOpenPeriod('water', $date, $now,
            function (array $context) use ($actor, $subscriberId, $amount, $uuid, $now, $cashSessionId, $invoiceId, $meterId, $reference, $reason, $notes, $today, $deferred): array {
                $this->need(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1, 'posting_uuid');
                $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null,
                    'gated_cash_issuance_lock_missing');
                // Isolated candidate lock order. Unfenced legacy writers still use subscriber-first.
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                $subscriber = Subscriber::query()->lockForUpdate()->findOrFail($subscriberId);
                abort_unless($actor->hasRole('super_admin') || $actor->can('Create:Payment'), 403);
                $this->need(! DB::table('late_receipt_reviews')->where('sync_uuid', $uuid)->exists()
                    && ! DB::table('noncash_collection_claims')->where('sync_uuid', $uuid)->exists(),
                    'gated_cash_claim_conflict');

                $existing = Payment::withTrashed()->where('sync_uuid', $uuid)->lockForUpdate()->first();
                $operation = DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->lockForUpdate()->first();
                if ($existing) {
                    $this->need(! $existing->trashed() && $operation !== null, 'gated_cash_unregistered_or_conflicting_source');
                    $entry = $this->entry($existing);
                    $registered = DB::table('financial_posting_events')->where('scope_key', 'water')
                        ->where('source_type', 'ledger_transaction')->where('source_id', (string) $entry->id)
                        ->where('leg', 'subscriber')->lockForUpdate()->first();
                    $this->need($registered !== null && $registered->operation_uuid === $uuid,
                        'gated_cash_unregistered_or_conflicting_source');
                } else {
                    $this->need($operation === null, 'gated_cash_operation_conflict');
                    $this->need($context['effective_on'] === $today, 'gated_cash_late_review_required');
                }
                if ($invoiceId !== null && $meterId !== null && $existing === null) {
                    $invoice = \App\Models\Invoice::query()->where('subscriber_id', $subscriberId)->lockForUpdate()->findOrFail($invoiceId);
                    $this->need((int) $invoice->meter_id === $meterId, 'gated_cash_destination_mismatch');
                }
                $before = app(TransactionService::class)->accountBalanceMinor($subscriber);

                // Do not silently infer a till on behalf of the caller.
                $payment = $this->mutate($subscriberId, $uuid, fn () => $this->payments->collectPayment($subscriber, $amount, invoiceId: $invoiceId,
                    paymentMethod: 'cash', referenceNumber: $reference, notes: $notes, reason: $reason, userId: (int) $actor->id,
                    paymentDate: $context['effective_on'], requestedMeterId: $meterId,
                    syncUuid: $uuid, requestedCashSessionId: $cashSessionId, deferred: $deferred, gatedWriter: $this));
                $this->need((int) ($payment->cash_session_id ?? 0) === (int) ($cashSessionId ?? 0)
                    && $payment->payment_date->toDateString() === $context['effective_on']
                    && (int) $payment->subscriber_id === $subscriberId
                    && (int) $payment->user_id === (int) $actor->id
                    && $payment->sync_uuid === $uuid && $payment->payment_method === 'cash'
                    && $payment->notes === $notes
                    && ($meterId === null || (int) ($payment->meter_id ?? $payment->retired_meter_id) === $meterId),
                    'gated_cash_destination_mismatch');
                $entry = $this->entry($payment);
                $leg = $this->evidence->leg((int) $entry->id);
                $this->need($leg['amount_minor'] === AccountMoney::stored($payment)
                    && app(TransactionService::class)->accountBalanceMinor($subscriber) === ($existing
                        ? $before : \App\Support\SignedMinor::add($before, $leg['amount_minor'])), 'gated_cash_amount_mismatch');
                return ['payment' => $payment, 'transaction_id' => (int) $entry->id,
                    'registry_created' => $this->registry->record($context, $uuid, [$leg], $now)];
            });
    }

    /** A caller cannot grant itself the private verified receipt scope. */
    public function allowsMutation(int $subscriberId, ?string $uuid): bool
    {
        return $uuid !== null && $this->mutationIdentity === [$subscriberId, $uuid]
            && DB::transactionLevel() > 0;
    }

    private function mutate(int $subscriberId, string $uuid, callable $action): Payment
    {
        $this->need($this->mutationIdentity === null && DB::transactionLevel() > 0, 'gated_cash_mutation_scope');
        $this->mutationIdentity = [$subscriberId, $uuid];
        try {
            return $action();
        } finally {
            $this->mutationIdentity = null;
        }
    }

    private function entry(Payment $payment): Transaction
    {
        return Transaction::where('reference_type', Payment::class)->where('reference_id', $payment->id)
            ->where('type', 'payment')->lockForUpdate()->sole();
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
