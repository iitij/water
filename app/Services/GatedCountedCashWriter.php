<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CashSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscriber;
use App\Models\Transaction;
use App\Models\User;
use App\Support\AccountMoney;
use App\Support\CashCustodyAmounts as Cash;
use App\Support\DecimalDomain;
use App\Support\SignedMinor;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Inactive allocation of cash already included in an approved physical count.
 * No new treasury movement. Registered funding and independently matched noncash
 * collections never increase physical cash capacity. Legacy gaps fail closed.
 */
final class GatedCountedCashWriter
{
    private ?array $mutationIdentity = null;

    public function __construct(
        private FinancialWriteGate $gate,
        private LateReceiptReviewService $reviews,
        private FinancialLedgerEvidence $ledger,
        private FinancialPostingRegistry $registry,
        private AuditLogger $audit,
    ) {}

    public function allocate(User $actor, int $reviewId, string $effectiveOn,
        string $reference, string $evidence, string $uuid, DateTimeImmutable $now): array
    {
        $reference = mb_strtoupper(trim($reference));
        $evidence = trim($evidence);
        return $this->gate->withinOpenPeriod('water', $effectiveOn, $now,
            function (array $context) use ($actor, $reviewId, $reference, $evidence, $uuid, $now): array {
                app(SubscriberFinancialWriteProtection::class)->requireActive();
                try {
                    $active = app(CollectionEvidenceAuditProtection::class)->active()
                        && app(CollectionEvidencePeriodProtection::class)->active()
                        && app(FinancialEvidenceDestinationProtection::class)->active()
                        && app(SessionTransferDestinationProtection::class)->active();
                } catch (\LogicException $error) {
                    throw new DomainException('counted_cash_write_protection_missing', 0, $error);
                }
                $this->need($active, 'counted_cash_write_protection_missing');
                $this->need(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1, 'posting_uuid');
                DB::table('financial_periods')->where('scope_key', 'water')->orderBy('id')->lockForUpdate()->get();
                $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null,
                    'gated_counted_issuance_lock_missing');
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                abort_unless($this->reviews->canDecide($actor), 403);
                $candidate = DB::table('late_receipt_reviews')->find($reviewId);
                $this->need($candidate !== null, 'gated_counted_review_missing');
                $subscriber = Subscriber::query()->lockForUpdate()->findOrFail($candidate->subscriber_id);
                $review = DB::table('late_receipt_reviews')->where('id', $reviewId)->lockForUpdate()->first();
                $this->need($review !== null && (int) $review->subscriber_id === (int) $subscriber->id
                    && $review->sync_uuid === $uuid && $review->payment_method === 'cash'
                    && in_array($review->status, ['pending', 'approved'], true), 'gated_counted_review_identity');
                $this->need($review->payment_date === $context['effective_on'], 'gated_counted_effective_date');
                abort_if((int) $review->collector_id === (int) $actor->id, 403);
                $minor = $this->submission($review);
                $session = CashSession::query()->lockForUpdate()->findOrFail($review->cash_session_id);
                $this->need((int) $session->user_id === (int) $review->collector_id
                    && $session->closed_at !== null
                    && $review->payment_date >= $session->session_date->toDateString()
                    && $review->payment_date <= $session->closed_at->toDateString(), 'gated_counted_session_unverified');
                [$variance, $allocated, $approval, $sessionBytes, $receiptBytes] = $this->sessionProof($session);
                $replay = $review->status === 'approved';
                $operation = DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->lockForUpdate()->first();
                $this->need($replay ? $operation !== null : ($operation === null && $review->payment_id === null),
                    'gated_counted_operation_conflict');
                $this->need($replay || $minor <= SignedMinor::subtract($variance, $allocated), 'gated_counted_capacity_exceeded');

                if ($review->invoice_id !== null) {
                    $invoice = Invoice::query()->lockForUpdate()->findOrFail($review->invoice_id);
                    $this->need((int) $invoice->subscriber_id === (int) $subscriber->id && $invoice->status !== 'draft'
                        && ($replay || $invoice->status !== 'cancelled'), 'gated_counted_invoice_identity');
                    $entry = Transaction::query()->where('reference_type', Invoice::class)->where('reference_id', $invoice->id)
                        ->where('type', 'invoice')->lockForUpdate()->sole();
                    $op = $this->registered($this->ledger->leg((int) $entry->id), $invoice->bill_date->toDateString());
                    $this->registry->verifyPeriod('water', (int) $op->period_id);
                }
                $balance = app(TransactionService::class);
                $before = $balance->accountBalanceMinor($subscriber);
                $result = $this->mutate($reviewId, $uuid, fn () => $this->reviews->decide($reviewId, $actor, ['approve' => true, 'settlement_method' => 'already_counted',
                    'evidence_reference' => $reference, 'evidence' => $evidence], $this));
                $row = (object) $result;
                $leg = $this->allocationLeg($row, $session);
                $this->need($leg['amount_minor'] === $minor && (int) $row->decided_by === (int) $actor->id
                    && $row->evidence_reference === $reference && $row->evidence === $evidence
                    && $balance->accountBalanceMinor($subscriber) === SignedMinor::add($before, $replay ? 0 : $minor)
                    && $session->fresh()->getRawOriginal() === $sessionBytes
                    && Payment::withTrashed()->where('cash_session_id', $session->id)->orderBy('id')->lockForUpdate()->get()
                        ->map(fn ($p) => $p->getRawOriginal())->all() === $receiptBytes,
                    'gated_counted_effect_mismatch');
                $after = SignedMinor::add($allocated, $replay ? 0 : $minor);
                $snapshot = $this->reviews->countedCashAllocation($session->fresh(), true);
                $this->need($snapshot['eligible'] && $snapshot['capacity_minor'] === $variance
                    && $snapshot['allocated_minor'] === $after
                    && $snapshot['available_minor'] === SignedMinor::subtract($variance, $after), 'gated_counted_capacity_mismatch');
                if (! $replay) {
                    $proof = $this->audit->recordRequired('financial.counted_cash_allocated', [
                        'entity_type' => 'late_receipt_review', 'entity_id' => $reviewId, 'actor_user_id' => $actor->id,
                        'after' => $this->proof($row, $leg, $session, (int) $approval->id, $variance, $allocated),
                    ]);
                    $this->checkProof($proof, $row, $leg, $session, (int) $approval->id, $variance, $allocated);
                }
                $created = $this->registry->record($context, $uuid, [$leg], $now);
                $this->need($created === ! $replay, 'gated_counted_operation_conflict');
                return ['review' => $result, 'transaction_id' => (int) $leg['source_id'],
                    'allocation' => $snapshot, 'registry_created' => $created];
            });
    }


    public function allowsMutation(string $kind, int $reviewId, string $uuid): bool
    {
        return $kind === 'already_counted' && $this->mutationIdentity === [$reviewId, $uuid]
            && DB::transactionLevel() > 0;
    }

    private function mutate(int $reviewId, string $uuid, callable $action): array
    {
        $this->need($this->mutationIdentity === null && DB::transactionLevel() > 0, 'gated_counted_mutation_scope');
        $this->mutationIdentity = [$reviewId, $uuid];
        try {
            return $action();
        } finally {
            $this->mutationIdentity = null;
        }
    }

    /** Read-only independent invocation of the complete capacity proof; never creates or repairs effects. */
    public function verifySession(int $sessionId): array
    {
        $this->need(DB::transactionLevel() > 0, 'gated_counted_requires_gate');
        $scope = DB::table('financial_scopes')->where('scope_key', 'water')->lockForUpdate()->first();
        $this->need($scope !== null && $scope->mode === 'ACTIVE' && $scope->currency === 'YER',
            'gated_counted_requires_gate');
        DB::table('financial_periods')->where('scope_key', 'water')->orderBy('id')->lockForUpdate()->get();
        $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null,
            'gated_counted_issuance_lock_missing');
        $session = CashSession::query()->lockForUpdate()->findOrFail($sessionId);
        [$capacity, $allocated] = $this->sessionProof($session);
        return ['capacity_minor' => $capacity, 'allocated_minor' => $allocated,
            'available_minor' => SignedMinor::subtract($capacity, $allocated),
            'source_coverage_verified' => false, 'closure_authorized' => false];
    }

    /** Revalidate the allocation sequence, then retain only decisions and
     * registered effects belonging to the requested local cutoff.
     * A late approval with a backdated posting is an explicit mismatch, not
     * silently adopted into the earlier count disposition.
     */
    public function verifySessionThrough(int $sessionId, string $next): array
    {
        $end = new DateTimeImmutable($next, new \DateTimeZone('Asia/Aden'));
        if ($end->format('Y-m-d') !== $next || now('Asia/Aden')->toDateString() < $next) {
            throw new DomainException('gated_counted_cutoff_invalid');
        }
        $until = $end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $verified = $this->verifySession($sessionId);
        $rows = [];
        $allocated = 0;
        foreach (DB::table('late_receipt_reviews')->where('cash_session_id', $sessionId)
            ->where('status', 'approved')->where('settlement_method', 'already_counted')
            ->orderBy('id')->lockForUpdate()->get() as $review) {
            $proof = $this->event('financial.counted_cash_allocated', 'late_receipt_review', (int) $review->id);
            $op = DB::table('financial_posting_operations')->where('operation_uuid', $review->sync_uuid)
                ->lockForUpdate()->sole();
            $beforeCutoff = $proof->getRawOriginal('occurred_at') < $until
                && $review->decided_at !== null && $review->decided_at < $until;
            if ($op->effective_on < $next && ! $beforeCutoff) {
                throw new DomainException('gated_counted_post_cutoff_backdated_allocation');
            }
            if (! $beforeCutoff || $op->effective_on >= $next) continue;
            $minor = Cash::positiveStored($review->amount, DB::getDriverName(), DecimalDomain::D12);
            $allocated = SignedMinor::add($allocated, $minor);
            $rows[] = ['review_id' => (int) $review->id, 'subscriber_id' => (int) $review->subscriber_id,
                'payment_id' => (int) $review->payment_id, 'audit_id' => (int) $proof->id,
                'operation_uuid' => $review->sync_uuid, 'amount_minor' => $minor,
                'ledger_hash' => $proof->after['ledger_hash']];
        }
        $available = SignedMinor::subtract($verified['capacity_minor'], $allocated);
        $this->need($available >= 0 && $allocated <= $verified['allocated_minor'], 'gated_counted_cutoff_invalid');
        return ['capacity_minor' => $verified['capacity_minor'], 'allocated_minor' => $allocated,
            'unallocated_minor' => $available, 'allocations' => $rows, 'closure_authorized' => false];
    }

    /** Scoped bidirectional audit; legacy approvals without proof are reported by refusal. */
    public function verifyCoverage(): array
    {
        $this->need(DB::transactionLevel() > 0, 'gated_counted_requires_gate');
        $scope = DB::table('financial_scopes')->where('scope_key', 'water')->lockForUpdate()->first();
        $this->need($scope !== null && $scope->mode === 'ACTIVE' && $scope->currency === 'YER',
            'gated_counted_requires_gate');
        DB::table('financial_periods')->where('scope_key', 'water')->orderBy('id')->lockForUpdate()->get();
        $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null,
            'gated_counted_issuance_lock_missing');
        $rows = DB::table('late_receipt_reviews')->where('status', 'approved')->where('settlement_method', 'already_counted')
            ->orderBy('id')->lockForUpdate()->get();
        $audits = AuditEvent::query()->where('event', 'financial.counted_cash_allocated')->orderBy('id')->lockForUpdate()->get();
        $this->need($audits->count() === $rows->count(), 'gated_counted_coverage_missing_or_orphan_proof');
        foreach ($audits as $audit) {
            $this->need($audit->entity_type === 'late_receipt_review'
                && $rows->contains(fn ($row) => (string) $row->id === (string) $audit->entity_id),
                'gated_counted_coverage_missing_or_orphan_proof');
        }
        $capacity = 0;
        $allocated = 0;
        $sessions = $rows->pluck('cash_session_id')->unique()->sort()->values();
        foreach ($sessions as $sessionId) {
            $proof = $this->verifySession((int) $sessionId);
            $capacity = SignedMinor::add($capacity, $proof['capacity_minor']);
            $allocated = SignedMinor::add($allocated, $proof['allocated_minor']);
        }
        return ['scope' => 'approved_counted_cash_only', 'sessions' => $sessions->count(),
            'allocations' => $rows->count(), 'capacity_minor' => $capacity, 'allocated_minor' => $allocated,
            'source_coverage_verified' => false, 'closure_authorized' => false];
    }

    private function verifyOtherCollection(Payment $payment, object $operation, string $kind, ?int $reviewId = null): int
    {
        $this->need(! $payment->isVoided() && ! $payment->hasPendingVoidRequest(), 'gated_counted_other_settlement_unsupported');
        if ($kind === 'bank_collection') {
            $this->need(in_array($payment->payment_method, ['bank_transfer', 'check'], true),
                'gated_counted_other_settlement_unsupported');
            $document = DB::table('treasury_bank_collections')->where('payment_id', $payment->id)->lockForUpdate()->first();
            $column = 'treasury_bank_collection_id';
        } else {
            $this->need($reviewId !== null && $payment->payment_method === 'cash', 'gated_counted_other_settlement_unsupported');
            $document = DB::table('treasury_late_cash_receipts')->where('late_receipt_review_id', $reviewId)->lockForUpdate()->first();
            $column = 'treasury_late_cash_receipt_id';
        }
        $this->need($document !== null, 'gated_counted_other_settlement_unsupported');
        $posting = DB::table('treasury_postings')->where($column, $document->id)->lockForUpdate()->first();
        $this->need($posting !== null && $posting->kind === $kind, 'gated_counted_other_settlement_unsupported');
        $account = DB::table('treasury_accounts')->where('id', $posting->treasury_account_id)->lockForUpdate()->first();
        $this->need($account !== null, 'gated_counted_other_settlement_unsupported');
        $opening = app(TreasuryOpeningFundingEvidence::class)->opening((int) $account->id, $account->opening_on);
        $openingOp = $this->registered($opening, $account->opening_on);
        $this->registry->verifyPeriod('water', (int) $openingOp->period_id);
        $leg = app(TreasuryCollectionEvidence::class)->leg((int) $posting->id);
        $op = $this->registered($leg, $posting->effective_on);
        $this->need($op->operation_uuid === $operation->operation_uuid
            && DB::table('financial_posting_events')->where('operation_uuid', $op->operation_uuid)->count() === 2,
            'gated_counted_other_settlement_unsupported');
        return (int) $op->period_id;
    }

    private function sessionProof(CashSession $session): array
    {
        $driver = $session->getConnection()->getDriverName();
        $expected = Cash::stored($session->getRawOriginal('expected_amount'), $driver, nullable: true);
        $counted = Cash::stored($session->getRawOriginal('counted_amount'), $driver, nullable: true);
        $variance = Cash::stored($session->getRawOriginal('variance_amount'), $driver, signed: true, nullable: true);
        $this->need($session->isApproved() && $session->settlement_basis === 'physical_cash'
            && (int) $session->approved_by > 0 && (int) $session->approved_by !== (int) $session->user_id
            && $session->closed_at !== null && $session->approved_at !== null
            && $session->closed_at <= $session->approved_at
            && Cash::consistent($counted, $expected, $variance), 'gated_counted_session_unverified');
        $this->need($variance > 0, 'gated_counted_no_surplus');
        $opening = Cash::stored($session->getRawOriginal('opening_amount'), $driver);
        $fundings = DB::table('cash_session_fundings')->where('cash_session_id', $session->id)
            ->orderBy('id')->lockForUpdate()->get();
        $periods = [];
        $this->need($opening === 0 ? $fundings->isEmpty() : $fundings->count() === 1,
            'gated_counted_funding_missing_or_conflicting');
        if ($opening > 0) {
            $funding = $fundings->first();
            $posting = DB::table('treasury_postings')->where('cash_session_funding_id', $funding->id)->lockForUpdate()->first();
            $account = DB::table('treasury_accounts')->where('id', $funding->treasury_account_id)->lockForUpdate()->first();
            $this->need($posting !== null && $account !== null, 'gated_counted_funding_missing_or_conflicting');
            $adapter = app(TreasuryOpeningFundingEvidence::class);
            $fundingLeg = $adapter->funding((int) $posting->id, $session->session_date->toDateString());
            $this->need($fundingLeg['amount_minor'] === SignedMinor::negate($opening),
                'gated_counted_funding_mismatch');
            $periods[] = (int) $this->registered($fundingLeg, $posting->effective_on)->period_id;
            $periods[] = (int) $this->registered($adapter->opening((int) $account->id, $account->opening_on), $account->opening_on)->period_id;
        }
        $approval = $this->event('cash_session.approved', CashSession::class, (int) $session->id);
        $this->need((int) $approval->actor_user_id === (int) $session->approved_by
            && ($approval->after['state'] ?? null) === 'approved'
            && ($approval->after['expected_amount'] ?? null) === $session->expected_amount
            && ($approval->after['counted_amount'] ?? null) === $session->counted_amount
            && ($approval->after['variance_amount'] ?? null) === $session->variance_amount,
            'gated_counted_approval_mismatch');
        $sessionBytes = $session->getRawOriginal();
        $receipts = Payment::withTrashed()->where('cash_session_id', $session->id)->orderBy('id')->lockForUpdate()->get();
        $receiptBytes = $receipts->map(fn ($p) => $p->getRawOriginal())->all();
        $sum = $opening;
        $cash = 0;
        $noncash = 0;
        foreach ($receipts as $payment) {
            $this->need(! $payment->trashed() && ! $payment->isVoided() && ! $payment->hasPendingVoidRequest()
                && in_array($payment->payment_method, ['cash', 'bank_transfer', 'check'], true)
                && (int) $payment->user_id === (int) $session->user_id
                && $payment->payment_date->toDateString() === $session->session_date->toDateString()
                && is_string($payment->sync_uuid), 'gated_counted_till_receipt_unsupported');
            foreach (['payment_refunds', 'payment_account_transfers'] as $table) {
                $this->need(! DB::table($table)->where('payment_id', $payment->id)->exists(),
                    'gated_counted_till_receipt_unsupported');
            }
            $leg = $this->ledger->leg((int) $this->entry((int) $payment->id)->id);
            $op = $this->registered($leg, $payment->payment_date->toDateString());
            $this->need($op->operation_uuid === $payment->sync_uuid, 'gated_counted_till_receipt_unsupported');
            $periods[] = (int) $op->period_id;
            if ($payment->payment_method === 'cash') {
                $cash = SignedMinor::add($cash, $leg['amount_minor']);
                $sum = SignedMinor::add($sum, $leg['amount_minor']);
                $this->need(DB::table('financial_posting_events')->where('operation_uuid', $op->operation_uuid)->count() === 1,
                    'gated_counted_till_receipt_unsupported');
            } else {
                $periods[] = $this->verifyOtherCollection($payment, $op, 'bank_collection');
                $noncash = SignedMinor::add($noncash, $leg['amount_minor']);
            }
        }
        $this->need($sum === $expected
            && ($approval->after['opening_amount'] ?? null) === SignedMinor::decimal($opening)
            && ($approval->after['cash_collections'] ?? null) === SignedMinor::decimal($cash)
            && ($approval->after['non_cash_collections'] ?? null) === SignedMinor::decimal($noncash),
            'gated_counted_expected_mismatch');

        // Verify the complete prior allocation sequence using the
        // durable decision proof order, not the submission ID order.
        $prior = [];
        $approved = DB::table('late_receipt_reviews')->where('cash_session_id', $session->id)
            ->where('status', 'approved')->orderBy('id')->lockForUpdate()->get();
        foreach ($approved as $row) {
            if ($row->settlement_method !== 'already_counted') {
                $this->need(in_array($row->settlement_method, ['verified_non_cash', 'separate_handover'], true)
                    && (int) $row->payment_id > 0, 'gated_counted_other_settlement_unsupported');
                $payment = Payment::query()->lockForUpdate()->findOrFail($row->payment_id);
                $leg = $this->ledger->leg((int) $this->entry((int) $payment->id)->id);
                $op = $this->registered($leg, $payment->payment_date->toDateString());
                $this->need($row->sync_uuid === $op->operation_uuid && $payment->sync_uuid === $row->sync_uuid
                    && $payment->cash_session_id === null && (int) $payment->subscriber_id === (int) $row->subscriber_id
                    && $leg['amount_minor'] === $this->submission($row), 'gated_counted_other_settlement_unsupported');
                $periods[] = (int) $op->period_id;
                $periods[] = $this->verifyOtherCollection($payment, $op,
                    $row->settlement_method === 'verified_non_cash' ? 'bank_collection' : 'late_cash_receipt', (int) $row->id);
                continue;
            }
            $leg = $this->allocationLeg($row, $session);
            $op = $this->registered($leg, $row->payment_date);
            $this->need($op->operation_uuid === $row->sync_uuid
                && DB::table('financial_posting_events')->where('operation_uuid', $op->operation_uuid)->count() === 1,
                'gated_counted_prior_unregistered');
            $periods[] = (int) $op->period_id;
            $proof = $this->event('financial.counted_cash_allocated', 'late_receipt_review', (int) $row->id);
            $prior[] = ['row' => $row, 'leg' => $leg, 'proof' => $proof];
        }
        usort($prior, fn ($a, $b) => $a['proof']->id <=> $b['proof']->id);
        $allocated = 0;
        foreach ($prior as $item) {
            $this->checkProof($item['proof'], $item['row'], $item['leg'], $session, (int) $approval->id,
                $variance, $allocated);
            $allocated = SignedMinor::add($allocated, $item['leg']['amount_minor']);
            $this->need($allocated <= $variance, 'gated_counted_capacity_exceeded');
        }
        foreach (array_unique($periods) as $periodId) $this->registry->verifyPeriod('water', $periodId);
        $snapshot = $this->reviews->countedCashAllocation($session, true);
        $this->need($snapshot['eligible'] && $snapshot['capacity_minor'] === $variance
            && $snapshot['allocated_minor'] === $allocated, 'gated_counted_capacity_mismatch');
        return [$variance, $allocated, $approval, $sessionBytes, $receiptBytes];
    }

    private function submission(object $row): int
    {
        $audit = $this->event('late_receipt.submitted', 'late_receipt_review', (int) $row->id);
        $minor = Cash::positiveStored($row->amount, DB::connection()->getDriverName(), DecimalDomain::D12);
        $this->need((int) $audit->actor_user_id === (int) $row->collector_id
            && Cash::input($audit->after['amount'] ?? '', 'amount', DecimalDomain::D12, 1) === $minor,
            'gated_counted_submission_mismatch');
        foreach (['sync_uuid', 'cash_session_id', 'subscriber_id', 'invoice_id', 'collector_id', 'submitted_meter_id',
            'payment_method', 'reference_number', 'payment_date', 'reason', 'notes'] as $field) {
            $this->need(array_key_exists($field, $audit->after ?? []) && (string) $audit->after[$field] === (string) $row->$field,
                'gated_counted_submission_mismatch');
        }
        return $minor;
    }

    private function allocationLeg(object $row, CashSession $session): array
    {
        $minor = $this->submission($row);
        $this->need($row->status === 'approved' && $row->settlement_method === 'already_counted'
            && $row->payment_method === 'cash' && (int) $row->cash_session_id === (int) $session->id
            && (int) $row->collector_id === (int) $session->user_id
            && (int) $row->decided_by > 0 && (int) $row->decided_by !== (int) $row->collector_id
            && $row->payment_date >= $session->session_date->toDateString()
            && $row->payment_date <= $session->closed_at->toDateString()
            && $row->bank_clearance_reference === null && (int) $row->payment_id > 0
            && ! DB::table('treasury_late_cash_receipts')->where('late_receipt_review_id', $row->id)->exists(),
            'gated_counted_allocation_identity');
        $payment = Payment::query()->lockForUpdate()->findOrFail($row->payment_id);
        $this->need(! $payment->isVoided() && ! $payment->hasPendingVoidRequest()
            && $payment->cash_session_id === null && $payment->payment_method === 'cash'
            && $payment->sync_uuid === $row->sync_uuid && (int) $payment->subscriber_id === (int) $row->subscriber_id
            && (int) $payment->user_id === (int) $row->collector_id && (int) $payment->invoice_id === (int) $row->invoice_id
            && $payment->payment_date->toDateString() === $row->payment_date
            && $payment->reference_number === $row->reference_number && AccountMoney::stored($payment) === $minor,
            'gated_counted_allocation_identity');
        foreach (['payment_refunds', 'payment_account_transfers', 'treasury_bank_collections'] as $table) {
            $this->need(! DB::table($table)->where('payment_id', $payment->id)->exists(), 'gated_counted_allocation_identity');
        }
        $this->need(! Transaction::withTrashed()->where('reference_type', Payment::class)->where('reference_id', $payment->id)
            ->where('type', 'payment_reversal')->exists(), 'gated_counted_allocation_identity');
        $decision = $this->event('late_receipt.approved', 'late_receipt_review', (int) $row->id);
        $this->need((int) $decision->actor_user_id === (int) $row->decided_by
            && ($decision->after['counted_cash_allocation'] ?? null) === true
            && ($decision->after['new_cash_movement'] ?? null) === false
            && ($decision->after['settlement_method'] ?? null) === 'already_counted'
            && (int) ($decision->after['payment_id'] ?? 0) === (int) $payment->id
            && ($decision->after['evidence'] ?? null) === $row->evidence
            && ($decision->after['evidence_reference'] ?? null) === $row->evidence_reference,
            'gated_counted_decision_mismatch');
        $leg = $this->ledger->leg((int) $this->entry((int) $payment->id)->id);
        $this->need($leg['amount_minor'] === $minor, 'gated_counted_effect_mismatch');
        return $leg;
    }

    private function proof(object $row, array $leg, CashSession $session, int $approvalId, int $capacity, int $before): array
    {
        return ['operation_uuid' => $row->sync_uuid, 'session_id' => (int) $session->id, 'approval_audit_id' => $approvalId,
            'payment_id' => (int) $row->payment_id, 'transaction_id' => (int) $leg['source_id'], 'ledger_hash' => $leg['source_hash'],
            'capacity_minor' => $capacity, 'allocated_before_minor' => $before,
            'allocated_after_minor' => SignedMinor::add($before, $leg['amount_minor']),
            'amount_minor' => $leg['amount_minor'], 'effective_on' => $row->payment_date,
            'reference' => $row->evidence_reference, 'evidence' => $row->evidence, 'new_cash_movement' => false];
    }

    private function checkProof(AuditEvent $proof, object $row, array $leg, CashSession $session,
        int $approvalId, int $capacity, int $before): void
    {
        $this->need((int) $proof->actor_user_id === (int) $row->decided_by, 'gated_counted_proof_mismatch');
        foreach ($this->proof($row, $leg, $session, $approvalId, $capacity, $before) as $key => $value) {
            $this->need(array_key_exists($key, $proof->after ?? []) && $proof->after[$key] === $value, 'gated_counted_proof_mismatch');
        }
    }

    private function event(string $event, string $type, int $id): AuditEvent
    {
        return AuditEvent::query()->where('event', $event)->where('entity_type', $type)
            ->where('entity_id', (string) $id)->lockForUpdate()->sole();
    }

    private function entry(int $id): Transaction
    {
        return Transaction::query()->where('reference_type', Payment::class)->where('reference_id', $id)
            ->where('type', 'payment')->lockForUpdate()->sole();
    }

    private function registered(array $leg, string $date): object
    {
        $event = DB::table('financial_posting_events')->where('scope_key', 'water')
            ->where('source_type', $leg['source_type'])->where('source_id', $leg['source_id'])
            ->where('leg', $leg['leg'])->lockForUpdate()->first();
        $this->need($event !== null && (string) $event->amount_minor === (string) $leg['amount_minor']
            && $event->currency === $leg['currency'] && hash_equals($leg['source_hash'], $event->source_hash),
            'gated_counted_prerequisite_unregistered');
        $op = DB::table('financial_posting_operations')->where('operation_uuid', $event->operation_uuid)->lockForUpdate()->first();
        $this->need($op !== null && $op->scope_key === 'water' && $op->effective_on === $date,
            'gated_counted_prerequisite_unregistered');
        return $op;
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
