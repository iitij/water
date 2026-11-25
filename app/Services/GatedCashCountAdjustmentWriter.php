<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use App\Support\TreasuryMoney as Money;
use App\Support\SignedMinor;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Inactive investigated cash variance writer. No arbitrary balancing plug,
 * subscriber credit or revenue classification; no claim of full GL coverage.
 */
final class GatedCashCountAdjustmentWriter
{
    public function __construct(
        private FinancialWriteGate $gate,
        private TreasuryCashCountService $counts,
        private TreasuryRegisterService $treasury,
        private TreasuryOpeningFundingEvidence $opening,
        private TreasuryAdjustmentEvidence $adjustments,
        private FinancialLedgerRegistryVerifier $verifier,
        private FinancialPostingRegistry $registry,
    ) {}

    public function adjust(User $actor, int $countId, string $reference, string $classification,
        string $reason, string $evidence, string $uuid, DateTimeImmutable $now): array
    {
        $date = $now->setTimezone(new DateTimeZone('Asia/Aden'))->format('Y-m-d');
        return $this->gate->withinOpenPeriod('water', $date, $now,
            function (array $context) use ($actor, $countId, $reference, $classification, $reason, $evidence, $uuid, $now): array {
                $this->need(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1, 'posting_uuid');
                $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null,
                    'gated_cash_count_issuance_lock_missing');
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                $this->treasury->authorize($actor);
                $count = DB::table('treasury_cash_counts')->where('id', $countId)->lockForUpdate()->first();
                $this->need($count !== null, 'gated_cash_count_missing');
                abort_if((int) $count->counted_by === (int) $actor->id, 403);
                $account = DB::table('treasury_accounts')->where('id', $count->treasury_account_id)->lockForUpdate()->first();
                $this->need($account !== null && $account->channel === 'cash', 'gated_cash_count_account');
                $review = DB::table('treasury_cash_count_reviews')->where('treasury_cash_count_id', $countId)->lockForUpdate()->first();
                $this->need($review !== null && (bool) $review->accepted
                    && (int) $review->reviewed_by > 0 && (int) $review->reviewed_by !== (int) $count->counted_by,
                    'gated_cash_count_review_required');
                $variance = Money::variance($count->counted_minor, $count->recorded_minor);
                $this->need($variance === Money::signed($count->variance_minor)
                    && (($variance < 0 && $classification === 'confirmed_cash_loss')
                        || ($variance > 0 && $classification === 'unattributed_cash_held')),
                    'gated_cash_count_classification');
                $this->need(\Carbon\CarbonImmutable::parse($count->observed_at)->toDateString() === $context['effective_on'],
                    'gated_cash_count_observation_date');

                $observed = $this->audit('treasury.cash_count_recorded', 'treasury_cash_count', $countId);
                $decision = $this->audit('treasury.cash_count_reviewed', 'treasury_cash_count_review', (int) $review->id);
                $this->need((int) $observed->actor_user_id === (int) $count->counted_by
                    && ($observed->after['ledger_hash'] ?? null) === $count->ledger_hash
                    && ($observed->after['counted_minor'] ?? null) === Money::document($count->counted_minor)
                    && ($observed->after['recorded_minor'] ?? null) === Money::signed($count->recorded_minor)
                    && ($observed->after['variance_minor'] ?? null) === $variance
                    && ($observed->after['reference'] ?? null) === $count->reference
                    && ($observed->after['evidence'] ?? null) === $count->evidence
                    && (int) $decision->actor_user_id === (int) $review->reviewed_by
                    && (int) ($decision->after['treasury_cash_count_id'] ?? 0) === $countId
                    && ($decision->after['accepted'] ?? null) === true
                    && ($decision->after['evidence'] ?? null) === $review->evidence
                    && ($decision->after['financial_adjustment_created'] ?? null) === false,
                    'gated_cash_count_audit_mismatch');

                $existing = DB::table('treasury_cash_adjustments')->where('treasury_cash_count_id', $countId)->lockForUpdate()->first();
                $operation = DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->lockForUpdate()->first();
                $priorPosting = null;
                if ($existing !== null) {
                    $priorPosting = DB::table('treasury_postings')->where('treasury_cash_adjustment_id', $existing->id)->lockForUpdate()->first();
                    $this->need($operation !== null && $priorPosting !== null, 'gated_cash_count_operation_conflict');
                    $registered = $this->registered($this->adjustments->leg((int) $priorPosting->id), $context['effective_on']);
                    $this->need($registered->operation_uuid === $uuid, 'gated_cash_count_operation_conflict');
                } else {
                    $this->need($operation === null, 'gated_cash_count_operation_conflict');
                }

                // Verify every persisted preceding posting, not only the net
                // balance or a self-consistent registry. On replay use the
                // original observation boundary, excluding subsequent movements.
                $opening = $this->opening->opening((int) $account->id, $account->opening_on);
                $periods = [(int) $this->registered($opening, $account->opening_on)->period_id];
                $query = DB::table('treasury_postings')->where('treasury_account_id', $account->id);
                if ($priorPosting !== null) $query->where('id', '<', $priorPosting->id);
                $balance = $opening['amount_minor'];
                foreach ($query->orderBy('id')->lockForUpdate()->get() as $row) {
                    $this->need(in_array($row->kind, ['internal_transfer_out', 'internal_transfer_in',
                        'cash_handover', 'payment_refund', 'collector_funding', 'cash_count_adjustment',
                        'late_cash_receipt'], true), 'gated_cash_count_prior_unsupported');
                    $event = DB::table('financial_posting_events')->where('scope_key', 'water')
                        ->where('source_type', 'treasury_posting')->where('source_id', (string) $row->id)
                        ->where('leg', 'treasury')->lockForUpdate()->first();
                    $this->need($event !== null && (string) $event->amount_minor === (string) $row->amount_minor
                        && $event->currency === 'YER', 'gated_cash_count_prior_unregistered');
                    $op = DB::table('financial_posting_operations')->where('operation_uuid', $event->operation_uuid)->lockForUpdate()->first();
                    $this->need($op !== null && $op->scope_key === 'water' && $op->effective_on === $row->effective_on,
                        'gated_cash_count_prior_unregistered');
                    $periods[] = (int) $op->period_id;
                    $balance = SignedMinor::add($balance, Money::signed($row->amount_minor));
                }
                $this->need($balance === Money::signed($count->recorded_minor), 'gated_cash_count_snapshot_mismatch');
                foreach (array_unique($periods) as $periodId) {
                    $this->verifier->verifyPeriod('water', $periodId);
                }

                $result = $this->counts->adjust($actor, $countId, $reference, $classification, $reason, $evidence);
                $adjustment = $result['adjustment'];
                $posting = $result['posting'];
                $leg = $this->adjustments->leg((int) $posting['id']);
                $this->need($leg['amount_minor'] === $variance && $posting['effective_on'] === $context['effective_on']
                    && (int) $posting['treasury_account_id'] === (int) $account->id
                    && (int) $posting['posted_by'] === (int) $actor->id
                    && (int) $adjustment['treasury_cash_count_id'] === $countId
                    && (int) $adjustment['treasury_cash_count_review_id'] === (int) $review->id
                    && $adjustment['classification'] === $classification
                    && $result['observation']['adjustment_status'] === 'posted'
                    && $result['observation']['certified_balance'] === false
                    && ($existing !== null || $result['observation']['current_recorded_minor'] === Money::document($count->counted_minor)),
                    'gated_cash_count_effect_mismatch');
                $audit = $this->audit('treasury.cash_count_adjusted', 'treasury_cash_adjustment', (int) $adjustment['id']);
                $this->need((int) $audit->actor_user_id === (int) $actor->id
                    && (int) ($audit->after['posting_id'] ?? 0) === (int) $posting['id']
                    && ($audit->after['amount_minor'] ?? null) === $variance
                    && ($audit->after['classification'] ?? null) === $classification
                    && ($audit->after['reason'] ?? null) === trim($reason)
                    && ($audit->after['evidence'] ?? null) === trim($evidence)
                    && ($audit->after['subscriber_posting_created'] ?? null) === false,
                    'gated_cash_count_audit_mismatch');
                $created = $this->registry->record($context, $uuid, [$leg], $now);
                $result['registry_created'] = $created;
                if ($classification === 'unattributed_cash_held') {
                    $result['held_liability_id'] = app(TreasuryHeldCashLiabilityService::class)
                        ->recognize((int) $adjustment['id'], $uuid, $created);
                }
                return $result;
            });
    }

    private function audit(string $event, string $type, int $id): AuditEvent
    {
        return AuditEvent::query()->where('event', $event)->where('entity_type', $type)
            ->where('entity_id', (string) $id)->lockForUpdate()->sole();
    }

    private function registered(array $leg, string $date): object
    {
        $event = DB::table('financial_posting_events')->where('scope_key', 'water')
            ->where('source_type', $leg['source_type'])->where('source_id', $leg['source_id'])
            ->where('leg', $leg['leg'])->lockForUpdate()->first();
        $this->need($event !== null && (string) $event->amount_minor === (string) $leg['amount_minor']
            && $event->currency === $leg['currency'] && hash_equals($leg['source_hash'], $event->source_hash),
            'gated_cash_count_prerequisite_unregistered');
        $op = DB::table('financial_posting_operations')->where('operation_uuid', $event->operation_uuid)->lockForUpdate()->first();
        $this->need($op !== null && $op->scope_key === 'water' && $op->effective_on === $date,
            'gated_cash_count_prerequisite_unregistered');
        return $op;
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
