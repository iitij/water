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
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Inactive ordinary cash handover writer. Records evidence of actual physical
 * receipt; does not move money externally or create a subscriber payment.
 * No adoption of a legacy handover, opening, funding or receipt.
 */
final class GatedCashHandoverWriter
{
    public function __construct(
        private FinancialWriteGate $gate,
        private CashHandoverService $handovers,
        private TreasuryRegisterService $treasury,
        private TreasuryCustodyEvidence $custody,
        private TreasuryOpeningFundingEvidence $opening,
        private FinancialLedgerEvidence $ledger,
        private FinancialPostingRegistry $registry,
    ) {}

    public function receive(User $actor, int $sessionId, int $accountId, string $amount,
        string $reference, string $evidence, string $uuid, DateTimeImmutable $now): array
    {
        $date = $now->setTimezone(new DateTimeZone('Asia/Aden'))->format('Y-m-d');
        $reference = mb_strtoupper(trim($reference));
        $evidence = trim($evidence);
        return $this->gate->withinOpenPeriod('water', $date, $now,
            function (array $context) use ($actor, $sessionId, $accountId, $amount, $reference, $evidence, $uuid, $now): array {
                $this->need(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1, 'posting_uuid');
                $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null,
                    'gated_handover_issuance_lock_missing');
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                $this->treasury->authorize($actor);
                $session = CashSession::query()->lockForUpdate()->findOrFail($sessionId);
                abort_if((int) $session->user_id === (int) $actor->id, 403);
                $driver = $session->getConnection()->getDriverName();
                $target = Cash::stored($session->getRawOriginal('counted_amount'), $driver, nullable: true);
                $expected = Cash::stored($session->getRawOriginal('expected_amount'), $driver, nullable: true);
                $variance = Cash::stored($session->getRawOriginal('variance_amount'), $driver, signed: true, nullable: true);
                $this->need($session->isApproved() && $session->settlement_basis === 'physical_cash'
                    && $session->closed_at !== null && $session->approved_at !== null
                    && (int) $session->approved_by > 0 && (int) $session->approved_by !== (int) $session->user_id
                    && $session->closed_at <= $session->approved_at
                    && $session->approved_at->toDateString() <= $context['effective_on']
                    && Cash::consistent($target, $expected, $variance) && $target > 0,
                    'gated_handover_session_unverified');
                $approval = AuditEvent::query()->where('event', 'cash_session.approved')
                    ->where('entity_type', CashSession::class)->where('entity_id', (string) $sessionId)
                    ->lockForUpdate()->sole();
                $this->need((int) $approval->actor_user_id === (int) $session->approved_by
                    && ($approval->after['state'] ?? null) === 'approved'
                    && ($approval->after['counted_amount'] ?? null) === $session->counted_amount
                    && ($approval->after['expected_amount'] ?? null) === $session->expected_amount
                    && ($approval->after['variance_amount'] ?? null) === $session->variance_amount,
                    'gated_handover_approval_mismatch');

                $periods = [];
                $account = DB::table('treasury_accounts')->where('id', $accountId)->lockForUpdate()->first();
                $this->need($account !== null && $account->channel === 'cash', 'gated_handover_cash_account');
                $periods[] = $this->openingPeriod($account);
                $openingMinor = Cash::stored($session->getRawOriginal('opening_amount'), $driver);
                $fundings = DB::table('cash_session_fundings')->where('cash_session_id', $sessionId)->lockForUpdate()->get();
                $this->need($fundings->count() === ($openingMinor > 0 ? 1 : 0), 'gated_handover_funding_mismatch');
                if ($openingMinor > 0) {
                    $funding = $fundings->first();
                    $posting = DB::table('treasury_postings')->where('cash_session_funding_id', $funding->id)->lockForUpdate()->sole();
                    $fundingLeg = $this->opening->funding((int) $posting->id, $posting->effective_on);
                    $this->need($fundingLeg['amount_minor'] === -$openingMinor, 'gated_handover_funding_mismatch');
                    $periods[] = (int) $this->registered($fundingLeg, $posting->effective_on)->period_id;
                    $source = DB::table('treasury_accounts')->where('id', $funding->treasury_account_id)->lockForUpdate()->first();
                    $periods[] = $this->openingPeriod($source);
                }

                // Narrow scope: ordinary uncorrected cash receipts only.
                // Till corrections/exceptional receipts need separate custody proof.
                $receipts = Payment::withTrashed()->where('cash_session_id', $sessionId)->orderBy('id')->lockForUpdate()->get();
                $cash = [];
                foreach ($receipts as $receipt) {
                    $this->need(! $receipt->trashed() && ! $receipt->isVoided() && ! $receipt->hasPendingVoidRequest()
                        && $receipt->payment_method === 'cash' && is_string($receipt->sync_uuid)
                        && (int) $receipt->user_id === (int) $session->user_id
                        && $receipt->payment_date->toDateString() === $session->session_date->toDateString(),
                        'gated_handover_receipt_unsupported');
                    $entry = Transaction::query()->where('reference_type', Payment::class)
                        ->where('reference_id', $receipt->id)->where('type', 'payment')->lockForUpdate()->sole();
                    $leg = $this->ledger->leg((int) $entry->id);
                    $op = $this->registered($leg, $receipt->payment_date->toDateString());
                    $this->need($op->operation_uuid === $receipt->sync_uuid && $leg['amount_minor'] > 0,
                        'gated_handover_receipt_identity');
                    $periods[] = (int) $op->period_id;
                    $cash[] = $leg['amount_minor'];
                }
                $this->need(Cash::expected($openingMinor, $cash) === $expected, 'gated_handover_expected_mismatch');

                // Every previous partial handover must already have a registered
                // matching treasury effect. Never complete an unposted legacy handover.
                $previous = CashHandover::query()->where('cash_session_id', $sessionId)->orderBy('id')->lockForUpdate()->get();
                $received = 0;
                foreach ($previous as $row) {
                    $posting = DB::table('treasury_postings')->where('cash_handover_id', $row->id)->lockForUpdate()->first();
                    $this->need($posting !== null, 'gated_handover_prior_unregistered');
                    $leg = $this->custody->leg((int) $posting->id);
                    $periods[] = (int) $this->registered($leg, $posting->effective_on)->period_id;
                    $received = SignedMinor::add($received, $leg['amount_minor']);
                }
                foreach (array_unique($periods) as $periodId) {
                    $this->registry->verifyPeriod('water', $periodId);
                }

                $requested = Cash::input($amount, 'handover', DecimalDomain::D14, 1);
                $existing = CashHandover::query()->where('reference', $reference)->lockForUpdate()->first();
                $operation = DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->lockForUpdate()->first();
                if ($existing !== null) {
                    $posting = DB::table('treasury_postings')->where('cash_handover_id', $existing->id)->lockForUpdate()->first();
                    $this->need($operation !== null && $posting !== null && (int) $posting->treasury_account_id === $accountId
                        && (int) $posting->posted_by === (int) $actor->id, 'gated_handover_operation_conflict');
                    $op = $this->registered($this->custody->leg((int) $posting->id), $context['effective_on']);
                    $this->need($op->operation_uuid === $uuid, 'gated_handover_operation_conflict');
                } else {
                    $this->need($operation === null, 'gated_handover_operation_conflict');
                    $this->need($received <= $target && $requested <= SignedMinor::subtract($target, $received),
                        'gated_handover_exceeds_counted');
                }
                $sessionBytes = $session->getRawOriginal();
                $receiptBytes = $receipts->map(fn ($r) => $r->getRawOriginal())->all();
                $handover = $this->handovers->record($session, $actor, $amount, $reference, $context['effective_on'], $evidence);
                $posting = $this->treasury->post($actor, $accountId, 'cash_handover', (int) $handover->id);
                $leg = $this->custody->leg((int) $posting['id']);
                $this->need($leg['amount_minor'] === $requested && $posting['effective_on'] === $context['effective_on']
                    && (int) $posting['posted_by'] === (int) $actor->id
                    && (int) $handover->received_by === (int) $actor->id,
                    'gated_handover_effect_mismatch');
                $summary = $this->handovers->summary($session->fresh());
                $after = SignedMinor::add($received, $existing === null ? $requested : 0);
                $this->need($summary['received_amount'] === SignedMinor::decimal($after)
                    && $summary['remaining_amount'] === SignedMinor::decimal(SignedMinor::subtract($target, $after))
                    && $session->fresh()->getRawOriginal() === $sessionBytes
                    && Payment::withTrashed()->where('cash_session_id', $sessionId)->orderBy('id')->lockForUpdate()->get()
                        ->map(fn ($r) => $r->getRawOriginal())->all() === $receiptBytes,
                    'gated_handover_custody_changed');
                return ['handover' => $handover, 'posting_id' => (int) $posting['id'], 'summary' => $summary,
                    'registry_created' => $this->registry->record($context, $uuid, [$leg], $now)];
            });
    }

    private function openingPeriod(?object $account): int
    {
        $this->need($account !== null, 'gated_handover_cash_account');
        return (int) $this->registered($this->opening->opening((int) $account->id, $account->opening_on),
            $account->opening_on)->period_id;
    }

    private function registered(array $leg, string $date): object
    {
        $event = DB::table('financial_posting_events')->where('scope_key', 'water')
            ->where('source_type', $leg['source_type'])->where('source_id', $leg['source_id'])
            ->where('leg', $leg['leg'])->lockForUpdate()->first();
        $this->need($event !== null && (string) $event->amount_minor === (string) $leg['amount_minor']
            && $event->currency === $leg['currency'] && hash_equals($leg['source_hash'], $event->source_hash),
            'gated_handover_prerequisite_unregistered');
        $op = DB::table('financial_posting_operations')->where('operation_uuid', $event->operation_uuid)->lockForUpdate()->first();
        $this->need($op !== null && $op->scope_key === 'water' && $op->effective_on === $date,
            'gated_handover_prerequisite_unregistered');
        return $op;
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
