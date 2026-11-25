<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CashHandover;
use App\Models\CashSession;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use App\Support\CashCustodyAmounts as Cash;
use App\Support\DecimalDomain;
use App\Support\SignedMinor;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Inactive handover adapter for fully reconciled cash-only tills with zero
 * opening and variance. Complex funding/variance/late settlements need their
 * own verified custody coverage before this can replace existing routes.
 */
final class GatedCashHandoverWriter
{
    public function __construct(
        private FinancialWriteGate $gate,
        private CashHandoverService $handovers,
        private TreasuryRegisterService $treasury,
        private TreasuryOpeningFundingEvidence $opening,
        private TreasuryCustodyEvidence $custody,
        private FinancialLedgerEvidence $ledger,
        private FinancialPostingRegistry $registry,
    ) {}

    public function receive(User $actor, int $sessionId, int $accountId, string $amount,
        string $reference, string $date, string $evidence, string $uuid, DateTimeImmutable $now): array
    {
        $reference = mb_strtoupper(trim($reference));
        return $this->gate->withinOpenPeriod('water', $date, $now, function (array $context) use ($actor, $sessionId, $accountId, $amount, $reference, $date, $evidence, $uuid, $now): array {
            $this->need(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1, 'posting_uuid');
            DB::table('financial_periods')->where('scope_key', 'water')->orderBy('id')->lockForUpdate()->get();
            $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null, 'gated_handover_issuance_lock');
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $this->treasury->authorize($actor);
            abort_unless($this->handovers->canRecord($actor), 403);
            $account = DB::table('treasury_accounts')->where('id', $accountId)->lockForUpdate()->first();
            $this->need($account !== null && $account->channel === 'cash' && $account->opening_on <= $date, 'gated_handover_destination');
            $this->registered($this->opening->opening($accountId, $account->opening_on), $account->opening_on);
            $session = CashSession::query()->lockForUpdate()->findOrFail($sessionId);
            $driver = $session->getConnection()->getDriverName();
            $read = fn (string $field, bool $signed = false): ?int =>
                Cash::stored($session->getRawOriginal($field), $driver, DecimalDomain::D14, $signed, true);
            $counted = $read('counted_amount');
            $this->need($session->isApproved() && $session->settlement_basis === 'physical_cash'
                && $session->closed_at !== null && $session->approved_at !== null
                && $session->closed_at <= $session->approved_at
                && $date >= $session->closed_at->toDateString()
                && (int) $session->approved_by > 0 && (int) $session->approved_by !== (int) $session->user_id
                && $read('opening_amount') === 0 && $read('variance_amount', true) === 0
                && $counted !== null && $counted > 0 && $read('expected_amount') === $counted,
                'gated_handover_session_scope');
            $this->need(! DB::table('cash_session_fundings')->where('cash_session_id', $sessionId)->exists()
                && ! DB::table('closed_cash_corrections')->where('cash_session_id', $sessionId)->exists()
                && ! DB::table('late_receipt_reviews')->where('cash_session_id', $sessionId)->exists(), 'gated_handover_session_scope');
            $approval = AuditEvent::query()->where('event', 'cash_session.approved')->where('entity_type', CashSession::class)
                ->where('entity_id', (string) $sessionId)->lockForUpdate()->sole();
            $this->need((int) $approval->actor_user_id === (int) $session->approved_by, 'gated_handover_approval');
            $total = 0;
            $payments = Payment::withTrashed()->where('cash_session_id', $sessionId)->orderBy('id')->lockForUpdate()->get();
            foreach ($payments as $payment) {
                $this->need(! $payment->trashed() && ! $payment->isVoided() && ! $payment->hasPendingVoidRequest()
                    && $payment->payment_method === 'cash' && (int) $payment->user_id === (int) $session->user_id
                    && $payment->payment_date->toDateString() >= $session->session_date->toDateString()
                    && $payment->payment_date->toDateString() <= $session->closed_at->toDateString(), 'gated_handover_session_scope');
                $entry = Transaction::query()->where('reference_type', Payment::class)->where('reference_id', $payment->id)
                    ->where('type', 'payment')->lockForUpdate()->sole();
                $leg = $this->ledger->leg((int) $entry->id);
                $this->registered($leg, $payment->payment_date->toDateString());
                $total = SignedMinor::add($total, $leg['amount_minor']);
            }
            $this->need($total === $counted, 'gated_handover_count_mismatch');
            $existing = CashHandover::query()->where('reference', $reference)->lockForUpdate()->first();
            $op = DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->lockForUpdate()->first();
            $replay = $existing !== null;
            $this->need($replay === ($op !== null), 'gated_handover_operation_conflict');
            foreach (CashHandover::query()->where('cash_session_id', $sessionId)->orderBy('id')->lockForUpdate()->get() as $prior) {
                $posted = DB::table('treasury_postings')->where('cash_handover_id', $prior->id)->lockForUpdate()->first();
                $this->need($posted !== null, 'gated_handover_prior_unregistered');
                $priorOp = $this->registered($this->custody->leg((int) $posted->id), $prior->received_on->toDateString());
                if ($existing !== null && (int) $prior->id === (int) $existing->id) {
                    $this->need($priorOp->operation_uuid === $uuid && (int) $posted->posted_by === (int) $actor->id,
                        'gated_handover_operation_conflict');
                }
            }
            $before = $session->getRawOriginal();
            $paymentBytes = $payments->map(fn ($p) => $p->getRawOriginal())->all();
            $handover = $this->handovers->record($session, $actor, $amount, $reference, $date, $evidence);
            $posting = $this->treasury->post($actor, $accountId, 'cash_handover', (int) $handover->id);
            $leg = $this->custody->leg((int) $posting['id']);
            $this->need($leg['amount_minor'] === Cash::positiveStored($handover->getRawOriginal('amount'), $driver, DecimalDomain::D14)
                && $posting['effective_on'] === $date && (int) $posting['treasury_account_id'] === $accountId
                && $session->fresh()->getRawOriginal() === $before
                && Payment::withTrashed()->where('cash_session_id', $sessionId)->orderBy('id')->lockForUpdate()->get()
                    ->map(fn ($p) => $p->getRawOriginal())->all() === $paymentBytes, 'gated_handover_effect_mismatch');
            $created = $this->registry->record($context, $uuid, [$leg], $now);
            $this->need($created === ! $replay, 'gated_handover_operation_conflict');
            return ['handover' => $handover, 'posting' => $posting, 'registry_created' => $created];
        });
    }

    private function registered(array $leg, string $date): object
    {
        $event = DB::table('financial_posting_events')->where('scope_key', 'water')->where('source_type', $leg['source_type'])
            ->where('source_id', $leg['source_id'])->where('leg', $leg['leg'])->lockForUpdate()->first();
        $this->need($event !== null && (string) $event->amount_minor === (string) $leg['amount_minor']
            && $event->currency === $leg['currency'] && hash_equals($leg['source_hash'], $event->source_hash), 'gated_handover_prerequisite_unregistered');
        $op = DB::table('financial_posting_operations')->where('operation_uuid', $event->operation_uuid)->lockForUpdate()->first();
        $this->need($op !== null && $op->scope_key === 'water' && $op->effective_on === $date, 'gated_handover_prerequisite_unregistered');
        $this->registry->verifyPeriod('water', (int) $op->period_id);
        return $op;
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
