<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CashSession;
use App\Models\Payment;
use App\Models\Subscriber;
use App\Models\Transaction;
use App\Models\User;
use App\Support\AccountMoney;
use App\Support\CashCustodyAmounts as Cash;
use App\Support\DecimalDomain;
use App\Support\SignedMinor;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Inactive candidate: evidenced duplicate bookkeeping entry in one approved
 * physical-cash till. Equal amounts alone do not prove duplication. No refund,
 * cash movement, legacy adoption or production route activation.
 */
final class GatedClosedCashDuplicateWriter
{
    public function __construct(
        private FinancialWriteGate $gate,
        private ClosedCashCorrectionService $corrections,
        private FinancialLedgerEvidence $ledger,
        private FinancialPostingRegistry $registry,
        private CashSessionService $sessions,
    ) {}

    public function correct(User $actor, int $duplicateId, int $originalId, string $reason,
        string $evidence, string $uuid, DateTimeImmutable $now): array
    {
        $date = $now->setTimezone(new DateTimeZone('Asia/Aden'))->format('Y-m-d');
        $reason = trim($reason);
        $evidence = trim($evidence);
        return $this->gate->withinOpenPeriod('water', $date, $now,
            function (array $context) use ($actor, $duplicateId, $originalId, $reason, $evidence, $uuid, $now): array {
                $this->need(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1, 'posting_uuid');
                $this->need($originalId > 0 && $originalId < $duplicateId, 'gated_duplicate_identity');
                $this->need(mb_strlen($reason) >= 10 && mb_strlen($reason) <= 1000
                    && mb_strlen($evidence) >= 10 && mb_strlen($evidence) <= 2000, 'gated_duplicate_evidence_required');
                $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null,
                    'gated_duplicate_issuance_lock_missing');
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                abort_unless($actor->hasRole('super_admin') ||
                    ($actor->can('cash_sessions.approve') && $actor->can('payments.void.approve')), 403);
                $candidate = Payment::query()->findOrFail($duplicateId);
                $subscriber = Subscriber::query()->lockForUpdate()->findOrFail($candidate->subscriber_id);
                $ids = [$originalId, $duplicateId];
                $payments = Payment::query()->where('subscriber_id', $subscriber->id)
                    ->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $this->need($payments->count() === 2, 'gated_duplicate_identity');
                $original = $payments->get($originalId);
                $duplicate = $payments->get($duplicateId);
                $this->need((int) $duplicate->cash_session_id > 0
                    && (int) $duplicate->cash_session_id === (int) $original->cash_session_id,
                    'gated_duplicate_till_identity');
                $session = CashSession::query()->lockForUpdate()->findOrFail($duplicate->cash_session_id);
                abort_if((int) $session->user_id === (int) $actor->id
                    || (int) $original->user_id === (int) $actor->id || (int) $duplicate->user_id === (int) $actor->id, 403);
                $driver = $session->getConnection()->getDriverName();
                $this->need($session->isApproved() && $session->settlement_basis === 'physical_cash'
                    && $session->closed_at !== null && $session->approved_at !== null
                    && (int) $session->approved_by > 0 && (int) $session->approved_by !== (int) $session->user_id
                    && $session->closed_at <= $session->approved_at
                    && $session->approved_at->toDateString() <= $context['effective_on']
                    && Cash::consistent(
                        Cash::stored($session->getRawOriginal('counted_amount'), $driver, nullable: true),
                        Cash::stored($session->getRawOriginal('expected_amount'), $driver, nullable: true),
                        Cash::stored($session->getRawOriginal('variance_amount'), $driver, signed: true, nullable: true)),
                    'gated_duplicate_till_unverified');
                $minor = AccountMoney::stored($original);
                $this->need($minor > 0 && $minor === AccountMoney::stored($duplicate)
                    && ! $original->isVoided() && ! $original->hasPendingVoidRequest() && ! $duplicate->hasPendingVoidRequest(),
                    'gated_duplicate_receipt_unsupported');
                foreach ([$original, $duplicate] as $receipt) {
                    $this->need($receipt->payment_method === 'cash' && is_string($receipt->sync_uuid)
                        && (int) $receipt->user_id === (int) $session->user_id
                        && $receipt->payment_date->toDateString() === $session->session_date->toDateString(),
                        'gated_duplicate_receipt_unsupported');
                }
                foreach (['late_receipt_reviews', 'noncash_collection_claims', 'treasury_bank_collections',
                    'payment_refunds', 'payment_account_transfers'] as $table) {
                    $this->need(! DB::table($table)->whereIn('payment_id', $ids)->exists(),
                        'gated_duplicate_receipt_unsupported');
                }
                $this->need(! DB::table('closed_cash_corrections')->where('payment_id', $originalId)->exists()
                    && ! DB::table('closed_cash_corrections')->where('duplicate_of_id', $duplicateId)->exists(),
                    'gated_duplicate_receipt_unsupported');

                $originalEntry = $this->entry($originalId, 'payment');
                $duplicateEntry = $this->entry($duplicateId, 'payment');
                $originalLeg = $this->ledger->leg((int) $originalEntry->id);
                $duplicateLeg = $this->ledger->leg((int) $duplicateEntry->id);
                $periods = [];
                foreach ([[$original, $originalLeg], [$duplicate, $duplicateLeg]] as [$receipt, $leg]) {
                    $op = $this->registeredOperation($leg, $receipt->payment_date->toDateString());
                    $this->need($op->operation_uuid === $receipt->sync_uuid && $leg['amount_minor'] === $minor,
                        'gated_duplicate_receipt_identity');
                    $periods[] = (int) $op->period_id;
                }
                foreach (array_unique($periods) as $periodId) {
                    $this->registry->verifyPeriod('water', $periodId);
                }
                $this->need(! Transaction::withTrashed()->where('reference_type', Payment::class)
                    ->where('reference_id', $originalId)->where('type', 'payment_reversal')->exists(),
                    'gated_duplicate_original_reversed');

                $existing = DB::table('closed_cash_corrections')->where('payment_id', $duplicateId)->lockForUpdate()->first();
                $operation = DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->lockForUpdate()->first();
                if ($existing !== null) {
                    $this->need($operation !== null && (int) $existing->duplicate_of_id === $originalId
                        && (int) $existing->approved_by === (int) $actor->id
                        && $existing->reason === $reason && $existing->evidence === $evidence,
                        'gated_duplicate_operation_conflict');
                    $reversal = $this->entry($duplicateId, 'payment_reversal');
                    $op = $this->registeredOperation($this->ledger->leg((int) $reversal->id), $context['effective_on']);
                    $this->need($op->operation_uuid === $uuid, 'gated_duplicate_operation_conflict');
                } else {
                    $this->need($operation === null && ! $duplicate->isVoided(), 'gated_duplicate_operation_conflict');
                }

                $sessionBytes = $session->getRawOriginal();
                $summary = $this->physicalSummary($session);
                $originalBytes = $original->getRawOriginal();
                $duplicateBytes = $this->receiptIdentity($duplicate);
                $balance = app(TransactionService::class);
                $before = $balance->accountBalanceMinor($subscriber);
                $correction = $this->corrections->correctDuplicate($duplicate, $originalId, $actor, $reason, $evidence);
                $reversal = $this->entry($duplicateId, 'payment_reversal');
                $duplicate = $duplicate->fresh();
                $this->need((int) $correction['payment_id'] === $duplicateId
                    && (int) $correction['duplicate_of_id'] === $originalId
                    && (int) $correction['cash_session_id'] === (int) $session->id
                    && (int) $correction['approved_by'] === (int) $actor->id
                    && (int) $correction['reversal_transaction_id'] === (int) $reversal->id
                    && Cash::positiveStored($correction['amount'], $driver, DecimalDomain::D12) === $minor
                    && $correction['reason'] === $reason && $correction['evidence'] === $evidence
                    && \Carbon\CarbonImmutable::parse($correction['created_at'])->toDateString() === $context['effective_on']
                    && $duplicate->isVoided() && $duplicate->voided_via === 'closed_correction'
                    && (int) $duplicate->voided_by === (int) $actor->id && $duplicate->void_reason === $reason
                    && $duplicate->voided_at?->toDateString() === $context['effective_on'],
                    'gated_duplicate_document_mismatch');
                $this->need($session->fresh()->getRawOriginal() === $sessionBytes
                    && $this->physicalSummary($session->fresh()) === $summary
                    && $original->fresh()->getRawOriginal() === $originalBytes
                    && $this->receiptIdentity($duplicate) === $duplicateBytes, 'gated_duplicate_custody_changed');
                $leg = $this->ledger->leg((int) $reversal->id);
                $this->need($leg['amount_minor'] === -$minor
                    && $this->ledger->leg((int) $originalEntry->id) === $originalLeg
                    && $this->ledger->leg((int) $duplicateEntry->id) === $duplicateLeg
                    && $balance->accountBalanceMinor($subscriber) === SignedMinor::subtract($before, $existing === null ? $minor : 0),
                    'gated_duplicate_effect_mismatch');
                $audits = AuditEvent::query()->where('event', 'cash_session.duplicate_corrected')
                    ->where('entity_type', CashSession::class)->where('entity_id', (string) $session->id)
                    ->orderBy('id')->lockForUpdate()->get()->filter(fn ($a) =>
                        (int) ($a->after['correction_id'] ?? 0) === (int) $correction['id']);
                $this->need($audits->count() === 1, 'gated_duplicate_audit_mismatch');
                $audit = $audits->first();
                $this->need((int) $audit->actor_user_id === (int) $actor->id
                    && (int) ($audit->after['payment_id'] ?? 0) === $duplicateId
                    && (int) ($audit->after['duplicate_of_id'] ?? 0) === $originalId
                    && ($audit->after['amount'] ?? null) === SignedMinor::decimal($minor)
                    && ($audit->after['cash_movement'] ?? null) === false
                    && ($audit->after['reason'] ?? null) === $reason && ($audit->after['evidence'] ?? null) === $evidence
                    && ($audit->before['expected_amount'] ?? null) === $session->expected_amount
                    && ($audit->before['counted_amount'] ?? null) === $session->counted_amount
                    && ($audit->before['variance_amount'] ?? null) === $session->variance_amount
                    && ($audit->before['state'] ?? null) === $session->state, 'gated_duplicate_audit_mismatch');
                return ['correction' => $correction, 'reversal_id' => (int) $reversal->id,
                    'registry_created' => $this->registry->record($context, $uuid, [$leg], $now)];
            });
    }

    private function receiptIdentity(Payment $payment): array
    {
        return array_diff_key($payment->getRawOriginal(), array_flip([
            'voided_at', 'void_reason', 'voided_by', 'voided_via', 'updated_at',
        ]));
    }

    private function physicalSummary(CashSession $session): array
    {
        return array_intersect_key($this->sessions->summary($session), array_flip([
            'state', 'opening_amount', 'settlement_basis', 'expected_amount', 'counted_amount', 'variance_amount',
            'cash_collections', 'non_cash_collections', 'total_collections', 'receipts', 'voided_receipts',
            'counted_cash_surplus_capacity', 'counted_cash_surplus_allocated', 'counted_cash_surplus_available',
            'treasury_handover', 'by_method',
        ]));
    }

    private function registeredOperation(array $leg, string $date): object
    {
        $event = DB::table('financial_posting_events')->where('scope_key', 'water')
            ->where('source_type', $leg['source_type'])->where('source_id', $leg['source_id'])
            ->where('leg', $leg['leg'])->lockForUpdate()->first();
        $this->need($event !== null && (string) $event->amount_minor === (string) $leg['amount_minor']
            && $event->currency === $leg['currency'] && hash_equals($leg['source_hash'], $event->source_hash),
            'gated_duplicate_prerequisite_unregistered');
        $operation = DB::table('financial_posting_operations')->where('operation_uuid', $event->operation_uuid)
            ->lockForUpdate()->first();
        $this->need($operation !== null && $operation->scope_key === 'water' && $operation->effective_on === $date,
            'gated_duplicate_prerequisite_unregistered');
        return $operation;
    }

    private function entry(int $id, string $type): Transaction
    {
        $entries = Transaction::withTrashed()->where('reference_type', Payment::class)
            ->where('reference_id', $id)->where('type', $type)->lockForUpdate()->get();
        $this->need($entries->count() === 1 && ! $entries->first()->trashed(), 'gated_duplicate_ledger_identity');
        return $entries->first();
    }

    private function need(bool $condition, string $reason): void
    {
        if (! $condition) throw new DomainException($reason);
    }
}
