<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Subscriber;
use App\Models\User;
use App\Support\SignedMinor;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Inactive held-cash subledger. Recognition is atomic with the cash adjustment.
 * Assignment changes the creditor, not the cash balance or subscriber balance.
 * Cash return discharges only a documented identified claim. No revenue, legacy adoption or closure authorization.
 */
final class TreasuryHeldCashLiabilityService
{
    public function __construct(
        private FinancialWriteGate $gate,
        private FinancialPostingRegistry $registry,
        private TreasuryAdjustmentEvidence $adjustments,
        private TreasuryRegisterService $treasury,
    ) {}

    public function recognize(int $adjustmentId, string $uuid, bool $created): int
    {
        $this->lock();
        [$adjustment, $posting, $leg, $operation] = $this->source($adjustmentId);
        $this->need($operation->operation_uuid === $uuid, 'held_cash_source_operation');
        $record = ['treasury_cash_adjustment_id' => $adjustmentId,
            'treasury_posting_id' => (int) $posting->id, 'operation_uuid' => $uuid,
            'amount_minor' => $leg['amount_minor'], 'source_hash' => $leg['source_hash']];
        $row = DB::table('treasury_held_cash_liabilities')->where('treasury_cash_adjustment_id', $adjustmentId)
            ->lockForUpdate()->first();
        if ($created) {
            $this->need($row === null, 'held_cash_recognition_conflict');
            return (int) DB::table('treasury_held_cash_liabilities')->insertGetId($record);
        }
        $this->need($row !== null, 'held_cash_legacy_recognition_required');
        foreach ($record as $key => $value) {
            $this->need((string) $row->$key === (string) $value, 'held_cash_recognition_conflict');
        }
        return (int) $row->id;
    }

    public function assign(User $actor, int $liabilityId, int $subscriberId, int $minor,
        string $reference, string $evidence, string $uuid, string $date, DateTimeImmutable $now): array
    {
        $reference = mb_strtoupper(trim($reference));
        $evidence = trim($evidence);
        $this->need($minor > 0 && $subscriberId > 0 && $liabilityId > 0
            && mb_strlen($reference) >= 3 && mb_strlen($reference) <= 150
            && mb_strlen($evidence) >= 20 && mb_strlen($evidence) <= 4000
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1,
            'held_cash_assignment_input');
        return $this->gate->withinOpenPeriod('water', $date, $now,
            function (array $context) use ($actor, $liabilityId, $subscriberId, $minor, $reference, $evidence, $uuid, $date, $now): array {
                $this->lock();
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                $this->treasury->authorize($actor);
                $lot = $this->lot($liabilityId);
                [$adjustment, $posting] = $this->verifyLot($lot);
                $count = DB::table('treasury_cash_counts')->where('id', $adjustment->treasury_cash_count_id)->lockForUpdate()->first();
                $this->need($count !== null && (int) $actor->id !== (int) $count->counted_by
                    && (int) $actor->id !== (int) $adjustment->approved_by, 'held_cash_independent_approval');
                Subscriber::query()->lockForUpdate()->findOrFail($subscriberId);
                $this->need($date >= $posting->effective_on, 'held_cash_assignment_date');
                $before = $this->inspectLocked($lot);
                $row = ['operation_uuid' => $uuid, 'liability_id' => $liabilityId,
                    'subscriber_id' => $subscriberId, 'approved_by' => (int) $actor->id,
                    'amount_minor' => $minor, 'effective_on' => $date, 'reference' => $reference, 'evidence' => $evidence];
                $old = DB::table('treasury_held_cash_assignments')->where('operation_uuid', $uuid)->lockForUpdate()->first();
                $legs = $this->legs((object) $row, $lot);
                if ($old !== null) {
                    foreach ($row as $key => $value) {
                        $this->need((string) $old->$key === (string) $value, 'held_cash_assignment_conflict');
                    }
                    $this->need(! $this->registry->record($context, $uuid, $legs, $now), 'held_cash_assignment_conflict');
                    return $before + ['created' => false];
                }
                $this->need($minor <= $before['unidentified_minor'], 'held_cash_capacity_exceeded');
                foreach (['payments', 'late_receipt_reviews', 'noncash_collection_claims'] as $table) {
                    $this->need(! DB::table($table)->where('sync_uuid', $uuid)->lockForUpdate()->first(),
                        'held_cash_uuid_reserved');
                }
                $this->assertUnusedReference($reference, 'held_cash_assignment_conflict');
                $this->need(! DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->lockForUpdate()->first()
                    && ! DB::table('treasury_held_cash_payout_authorizations')->where('operation_uuid', $uuid)->exists(),
                    'held_cash_assignment_conflict');
                $this->need($this->registry->record($context, $uuid, $legs, $now), 'held_cash_assignment_conflict');
                DB::table('treasury_held_cash_assignments')->insert($row);
                return $this->inspectLocked($lot) + ['created' => true];
            });
    }



    /** Authorize before physical disbursement. No cash or liability posting is made here. */
    public function authorizePayout(User $authorizer, User $executor, string $assignmentUuid, int $minor,
        string $reference, string $evidence, string $uuid, string $date, DateTimeImmutable $now): array
    {
        $reference = mb_strtoupper(trim($reference));
        $evidence = trim($evidence);
        $this->need($minor > 0 && mb_strlen($reference) >= 3 && mb_strlen($reference) <= 150
            && mb_strlen($evidence) >= 20 && mb_strlen($evidence) <= 4000
            && $date === $now->setTimezone(new \DateTimeZone('Asia/Aden'))->format('Y-m-d')
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1,
            'held_cash_authorization_input');
        return $this->gate->withinOpenPeriod('water', $date, $now,
            function (array $context) use ($authorizer, $executor, $assignmentUuid, $minor, $reference, $evidence, $uuid, $date, $now): array {
                $this->lock();
                $users = User::query()->whereIn('id', [$authorizer->id, $executor->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $authorizer = $users->get($authorizer->id);
                $executor = $users->get($executor->id);
                $this->need($authorizer !== null && $executor !== null && (int) $authorizer->id !== (int) $executor->id,
                    'held_cash_authorization_independence');
                $this->treasury->authorize($authorizer);
                $this->treasury->authorize($executor);
                $assignment = DB::table('treasury_held_cash_assignments')->where('operation_uuid', $assignmentUuid)->lockForUpdate()->first();
                $this->need($assignment !== null, 'held_cash_assignment_missing');
                $lot = $this->lot((int) $assignment->liability_id);
                [$adjustment, $original] = $this->verifyLot($lot);
                $count = DB::table('treasury_cash_counts')->where('id', $adjustment->treasury_cash_count_id)->lockForUpdate()->first();
                $this->need($count !== null, 'held_cash_source_missing');
                $excluded = [(int) $assignment->approved_by, (int) $count->counted_by, (int) $adjustment->approved_by];
                $this->need(! in_array((int) $executor->id, $excluded, true), 'held_cash_payout_independence');
                $this->need(! in_array((int) $authorizer->id, $excluded, true), 'held_cash_authorization_independence');
                $this->need($date >= $assignment->effective_on, 'held_cash_payout_date');
                $this->inspectLocked($lot);
                $row = ['operation_uuid' => $uuid, 'assignment_uuid' => $assignmentUuid,
                    'treasury_account_id' => (int) $original->treasury_account_id, 'authorized_by' => (int) $authorizer->id,
                    'executor_id' => (int) $executor->id, 'amount_minor' => $minor, 'effective_on' => $date,
                    'reference' => $reference, 'evidence' => $evidence];
                $old = DB::table('treasury_held_cash_payout_authorizations')->where('operation_uuid', $uuid)->lockForUpdate()->first();
                if ($old !== null) {
                    foreach ($row as $key => $value) $this->need((string) $old->$key === (string) $value, 'held_cash_authorization_conflict');
                    $state = $this->authorizationState($old, $assignment, $original);
                    return ['authorization' => (array) $old, 'state' => $state, 'created' => false];
                }
                $released = SignedMinor::add($this->paidForAssignment($assignment, $lot, $original, true),
                    $this->creditedForAssignment($assignment, $lot, true));
                $pending = $this->pendingAuthorized($assignment, $original);
                $this->need($minor <= SignedMinor::subtract(SignedMinor::subtract(SignedMinor::parse($assignment->amount_minor), $released), $pending),
                    'held_cash_payout_capacity');
                foreach (['payments', 'late_receipt_reviews', 'noncash_collection_claims'] as $table) {
                    $this->need(! DB::table($table)->where('sync_uuid', $uuid)->lockForUpdate()->first(), 'held_cash_uuid_reserved');
                }
                $this->need(! DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->exists(),
                    'held_cash_authorization_conflict');
                $this->assertUnusedReference($reference, 'held_cash_authorization_conflict');
                $this->assertPayoutCash((int) $original->treasury_account_id, $minor, $date);
                $row['authorized_at_utc'] = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
                DB::table('treasury_held_cash_payout_authorizations')->insert($row);
                $this->treasury->requiredAudit('treasury.held_cash_payout_authorized', ['entity_type' => 'held_cash_payout_authorization',
                    'entity_id' => $uuid, 'actor_user_id' => $authorizer->id, 'after' => $row]);
                return ['authorization' => $row, 'state' => 'authorized', 'created' => true];
            });
    }

    /** Cancellation does not discharge a liability; evidence must establish no cash was handed out. */
    public function cancelPayoutAuthorization(User $actor, string $uuid, string $evidence, string $date, DateTimeImmutable $now): array
    {
        $evidence = trim($evidence);
        $this->need(mb_strlen($evidence) >= 20 && mb_strlen($evidence) <= 4000
            && $date === $now->setTimezone(new \DateTimeZone('Asia/Aden'))->format('Y-m-d'), 'held_cash_cancel_input');
        return $this->gate->withinOpenPeriod('water', $date, $now,
            function (array $context) use ($actor, $uuid, $evidence, $date): array {
                $this->lock();
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                $this->treasury->authorize($actor);
                $auth = DB::table('treasury_held_cash_payout_authorizations')->where('operation_uuid', $uuid)->lockForUpdate()->first();
                $this->need($auth !== null && (int) $actor->id !== (int) $auth->executor_id
                    && $date >= $auth->effective_on, 'held_cash_cancel_independence');
                $assignment = DB::table('treasury_held_cash_assignments')->where('operation_uuid', $auth->assignment_uuid)->lockForUpdate()->first();
                $this->need($assignment !== null, 'held_cash_assignment_missing');
                $lot = $this->lot((int) $assignment->liability_id);
                [, $original] = $this->verifyLot($lot);
                $state = $this->authorizationState($auth, $assignment, $original);
                $this->need($state !== 'paid', 'held_cash_authorization_already_paid');
                $row = ['operation_uuid' => $uuid, 'cancelled_by' => (int) $actor->id,
                    'effective_on' => $date, 'evidence' => $evidence];
                if ($state === 'cancelled') {
                    $old = DB::table('treasury_held_cash_payout_cancellations')->where('operation_uuid', $uuid)->lockForUpdate()->first();
                    foreach ($row as $key => $value) $this->need((string) $old->$key === (string) $value, 'held_cash_cancel_conflict');
                    return ['state' => 'cancelled', 'created' => false];
                }
                $this->need(! DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->exists(),
                    'held_cash_authorization_conflict');
                DB::table('treasury_held_cash_payout_cancellations')->insert($row);
                $this->treasury->requiredAudit('treasury.held_cash_payout_cancelled', ['entity_type' => 'held_cash_payout_authorization',
                    'entity_id' => $uuid, 'actor_user_id' => $actor->id, 'after' => $row]);
                return ['state' => 'cancelled', 'created' => true];
            });
    }

    private function assertPayoutCash(int $accountId, int $minor, string $date): void
    {
        $account = DB::table('treasury_accounts')->where('id', $accountId)->lockForUpdate()->first();
        $this->need($account !== null && $account->channel === 'cash', 'held_cash_payout_account');
        $balance = app(HeldCashRecordedLiquidityEvidence::class)->balance($accountId, $date);
        $guard = app(HeldCashReservation::class);
        $held = $guard->outstandingForAccount($accountId);
        $reserved = SignedMinor::add(SignedMinor::add($held, $guard->subscriberCreditReserveForAccount($accountId)),
            app(HandoverSurplusReservation::class)->forAccount($accountId));
        $pendingRefund = $guard->unpostedCashRefundMinor();
        $this->need(SignedMinor::subtract($balance, $pendingRefund) >= $reserved && $held >= $minor, 'held_cash_payout_insufficient_cash');
    }

    private function authorizationState(object $auth, object $assignment, object $original): string
    {
        $minor = SignedMinor::parse($auth->amount_minor);
        $when = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $auth->authorized_at_utc, new \DateTimeZone('UTC'));
        $this->need($minor > 0 && $auth->assignment_uuid === $assignment->operation_uuid
            && (int) $auth->treasury_account_id === (int) $original->treasury_account_id
            && (int) $auth->authorized_by > 0 && (int) $auth->executor_id > 0
            && (int) $auth->authorized_by !== (int) $auth->executor_id
            && (int) $auth->authorized_by !== (int) $assignment->approved_by
            && (int) $auth->executor_id !== (int) $assignment->approved_by
            && $when !== false && $when->format('Y-m-d\TH:i:s.u\Z') === $auth->authorized_at_utc
            && $when->setTimezone(new \DateTimeZone('Asia/Aden'))->format('Y-m-d') === $auth->effective_on
            && $auth->effective_on >= $assignment->effective_on, 'held_cash_authorization_source');
        $adjustment = DB::table('treasury_cash_adjustments')->where('id', $original->treasury_cash_adjustment_id)->lockForUpdate()->first();
        $count = $adjustment ? DB::table('treasury_cash_counts')->where('id', $adjustment->treasury_cash_count_id)->lockForUpdate()->first() : null;
        $this->need($count !== null && ! in_array((int) $auth->executor_id, [(int) $count->counted_by, (int) $adjustment->approved_by], true)
            && ! in_array((int) $auth->authorized_by, [(int) $count->counted_by, (int) $adjustment->approved_by], true),
            'held_cash_authorization_source');
        $approvalId = $this->authorizationAudit('treasury.held_cash_payout_authorized', $auth->operation_uuid, (int) $auth->authorized_by, (array) $auth);
        $cancel = DB::table('treasury_held_cash_payout_cancellations')->where('operation_uuid', $auth->operation_uuid)->lockForUpdate()->first();
        $paid = DB::table('treasury_held_cash_payouts')->where('operation_uuid', $auth->operation_uuid)->lockForUpdate()->first();
        $this->need(! ($cancel && $paid) && ($paid !== null || ! DB::table('financial_posting_operations')->where('operation_uuid', $auth->operation_uuid)->exists()), 'held_cash_authorization_source');
        if ($cancel) {
            $this->need((int) $cancel->cancelled_by !== (int) $auth->executor_id
                && $cancel->effective_on >= $auth->effective_on, 'held_cash_cancel_independence');
            $cancelId = $this->authorizationAudit('treasury.held_cash_payout_cancelled', $auth->operation_uuid, (int) $cancel->cancelled_by, (array) $cancel);
            $this->need($cancelId > $approvalId, 'held_cash_authorization_source');
            return 'cancelled';
        }
        if ($paid) {
            $this->need($paid->assignment_uuid === $auth->assignment_uuid
                && SignedMinor::parse($paid->amount_minor) === $minor && $paid->effective_on === $auth->effective_on
                && (int) $paid->approved_by === (int) $auth->executor_id, 'held_cash_authorization_source');
            return 'paid';
        }
        return 'authorized';
    }

    private function authorizationAudit(string $event, string $uuid, int $actor, array $row): int
    {
        $audits = \App\Models\AuditEvent::query()->where('event', $event)->where('entity_type', 'held_cash_payout_authorization')
            ->where('entity_id', $uuid)->lockForUpdate()->get();
        $this->need($audits->count() === 1 && (int) $audits->first()->actor_user_id === $actor,
            'held_cash_authorization_audit');
        foreach ($row as $key => $value) {
            $this->need(array_key_exists($key, $audits->first()->after ?? [])
                && (string) $audits->first()->after[$key] === (string) $value, 'held_cash_authorization_audit');
        }
        return (int) $audits->first()->id;
    }

    private function pendingAuthorized(object $assignment, object $original): int
    {
        $total = 0;
        foreach (DB::table('treasury_held_cash_payout_authorizations')->where('assignment_uuid', $assignment->operation_uuid)
            ->orderBy('operation_uuid')->lockForUpdate()->get() as $auth) {
            if ($this->authorizationState($auth, $assignment, $original) === 'authorized') {
                $total = SignedMinor::add($total, SignedMinor::parse($auth->amount_minor));
            }
        }
        return $total;
    }

    private function payoutAuthorization(object $row, object $assignment, object $original, DateTimeImmutable $now): object
    {
        $auth = DB::table('treasury_held_cash_payout_authorizations')->where('operation_uuid', $row->operation_uuid)->lockForUpdate()->first();
        $this->need($auth !== null, 'held_cash_payout_authorization_required');
        $state = $this->authorizationState($auth, $assignment, $original);
        $this->need($state !== 'cancelled' && $auth->assignment_uuid === $row->assignment_uuid
            && (int) $auth->executor_id === (int) $row->approved_by
            && SignedMinor::parse($auth->amount_minor) === SignedMinor::parse($row->amount_minor)
            && $auth->effective_on === $row->effective_on
            && $auth->reference !== $row->reference
            && $auth->authorized_at_utc <= $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'held_cash_payout_authorization_mismatch');
        return $auth;
    }

    /** Record a documented cash return to the identified owner, never a refund of a subscriber payment. */
    public function payout(User $actor, string $assignmentUuid, int $minor, string $reference,
        string $evidence, string $uuid, string $date, DateTimeImmutable $now): array
    {
        $reference = mb_strtoupper(trim($reference));
        $evidence = trim($evidence);
        $this->need($minor > 0 && mb_strlen($reference) >= 3 && mb_strlen($reference) <= 150
            && mb_strlen($evidence) >= 20 && mb_strlen($evidence) <= 4000
            && $date === $now->setTimezone(new \DateTimeZone('Asia/Aden'))->format('Y-m-d')
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1,
            'held_cash_payout_input');
        return $this->gate->withinOpenPeriod('water', $date, $now,
            function (array $context) use ($actor, $assignmentUuid, $minor, $reference, $evidence, $uuid, $date, $now): array {
                $this->lock();
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                $this->treasury->authorize($actor);
                $assignment = DB::table('treasury_held_cash_assignments')->where('operation_uuid', $assignmentUuid)->lockForUpdate()->first();
                $this->need($assignment !== null, 'held_cash_assignment_missing');
                $lot = $this->lot((int) $assignment->liability_id);
                [$adjustment, $original] = $this->verifyLot($lot);
                $count = DB::table('treasury_cash_counts')->where('id', $adjustment->treasury_cash_count_id)->lockForUpdate()->first();
                $this->need($count !== null && (int) $actor->id !== (int) $assignment->approved_by
                    && (int) $actor->id !== (int) $count->counted_by
                    && (int) $actor->id !== (int) $adjustment->approved_by, 'held_cash_payout_independence');
                $this->need($date >= $assignment->effective_on, 'held_cash_payout_date');
                $this->inspectLocked($lot);
                $row = ['operation_uuid' => $uuid, 'assignment_uuid' => $assignmentUuid, 'approved_by' => (int) $actor->id,
                    'amount_minor' => $minor, 'effective_on' => $date, 'reference' => $reference, 'evidence' => $evidence];
                $auth = $this->payoutAuthorization((object) $row, $assignment, $original, $now);
                $legs = $this->payoutLegs((object) $row, $assignment, $lot, $auth);
                $old = DB::table('treasury_held_cash_payouts')->where('operation_uuid', $uuid)->lockForUpdate()->first();
                if ($old !== null) {
                    foreach ($row as $key => $value) {
                        $this->need((string) $old->$key === (string) $value, 'held_cash_payout_conflict');
                    }
                    $this->need(! $this->registry->record($context, $uuid, $legs, $now), 'held_cash_payout_conflict');
                    return $this->inspectLocked($lot) + ['created' => false];
                }
                $paid = SignedMinor::add($this->paidForAssignment($assignment, $lot, $original, true), $this->creditedForAssignment($assignment, $lot, true));
                $this->need($minor <= SignedMinor::subtract(SignedMinor::parse($assignment->amount_minor), $paid),
                    'held_cash_payout_capacity');
                foreach (['payments', 'late_receipt_reviews', 'noncash_collection_claims'] as $table) {
                    $this->need(! DB::table($table)->where('sync_uuid', $uuid)->lockForUpdate()->first(), 'held_cash_uuid_reserved');
                }
                $this->assertUnusedReference($reference, 'held_cash_payout_conflict');
                $this->need(! DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->exists(), 'held_cash_payout_conflict');
                $accountId = (int) $original->treasury_account_id;
                $this->assertPayoutCash($accountId, $minor, $date);
                $this->need($this->registry->record($context, $uuid, $legs, $now), 'held_cash_payout_conflict');
                DB::table('treasury_held_cash_payouts')->insert($row);
                DB::table('treasury_postings')->insert([
                    'treasury_account_id' => $accountId, 'held_cash_payout_uuid' => $uuid, 'kind' => 'held_cash_payout',
                    'amount_minor' => SignedMinor::negate($minor), 'effective_on' => $date, 'evidence_reference' => $reference,
                    'posted_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->treasury->requiredAudit('treasury.held_cash_paid', ['entity_type' => 'held_cash_payout',
                    'entity_id' => $uuid, 'actor_user_id' => $actor->id, 'after' => $row]);
                return array_replace($this->inspectLocked($lot), ['created' => true,
                    'cash_movement_created' => true, 'discharge_created' => true]);
            });
    }


    /** Owner-authorized transfer of an identified claim to the same subscriber account. */
    public function credit(User $actor, string $assignmentUuid, int $minor, string $reference,
        string $evidence, string $uuid, string $date, DateTimeImmutable $now): array
    {
        $reference = mb_strtoupper(trim($reference));
        $evidence = trim($evidence);
        \App\Support\AccountMoney::positiveAllocation($minor);
        $this->need(mb_strlen($reference) >= 3 && mb_strlen($reference) <= 150
            && mb_strlen($evidence) >= 20 && mb_strlen($evidence) <= 4000
            && $date === $now->setTimezone(new \DateTimeZone('Asia/Aden'))->format('Y-m-d')
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1,
            'held_cash_credit_input');
        return $this->gate->withinOpenPeriod('water', $date, $now,
            function (array $context) use ($actor, $assignmentUuid, $minor, $reference, $evidence, $uuid, $date, $now): array {
                $this->lock();
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                $this->treasury->authorize($actor);
                $assignment = DB::table('treasury_held_cash_assignments')->where('operation_uuid', $assignmentUuid)->lockForUpdate()->first();
                $this->need($assignment !== null, 'held_cash_assignment_missing');
                $lot = $this->lot((int) $assignment->liability_id);
                [$adjustment, $original] = $this->verifyLot($lot);
                $count = DB::table('treasury_cash_counts')->where('id', $adjustment->treasury_cash_count_id)->lockForUpdate()->first();
                $this->need($count !== null && (int) $actor->id !== (int) $assignment->approved_by
                    && (int) $actor->id !== (int) $count->counted_by
                    && (int) $actor->id !== (int) $adjustment->approved_by, 'held_cash_credit_independence');
                $this->need($date >= $assignment->effective_on, 'held_cash_credit_date');
                $subscriber = Subscriber::query()->lockForUpdate()->findOrFail($assignment->subscriber_id);
                $this->inspectLocked($lot);
                $record = ['operation_uuid' => $uuid, 'assignment_uuid' => $assignmentUuid,
                    'subscriber_id' => (int) $subscriber->id, 'approved_by' => (int) $actor->id,
                    'amount' => \App\Support\ExactMoney::fromMinor($minor)->decimal(),
                    'effective_on' => $date, 'reference' => $reference, 'evidence' => $evidence];
                $old = \App\Models\TreasuryHeldCashCredit::where('operation_uuid', $uuid)->lockForUpdate()->first();
                if ($old !== null) {
                    foreach ($record as $key => $value) {
                        $this->need((string) $old->$key === (string) $value, 'held_cash_credit_conflict');
                    }
                    $this->need(! $this->registry->record($context, $uuid, $this->creditLegs($old, $assignment, $lot), $now),
                        'held_cash_credit_conflict');
                    return $this->inspectLocked($lot) + ['created' => false];
                }
                $released = SignedMinor::add($this->paidForAssignment($assignment, $lot, $original, true),
                    $this->creditedForAssignment($assignment, $lot, true));
                $pending = $this->pendingAuthorized($assignment, $original);
                $this->need($minor <= SignedMinor::subtract(SignedMinor::subtract(SignedMinor::parse($assignment->amount_minor), $released), $pending),
                    'held_cash_credit_capacity');
                foreach (['payments', 'late_receipt_reviews', 'noncash_collection_claims'] as $table) {
                    $this->need(! DB::table($table)->where('sync_uuid', $uuid)->lockForUpdate()->first(), 'held_cash_uuid_reserved');
                }
                $this->assertUnusedReference($reference, 'held_cash_credit_conflict');
                $this->need(! DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->exists()
                    && ! DB::table('treasury_held_cash_payout_authorizations')->where('operation_uuid', $uuid)->exists(), 'held_cash_credit_conflict');
                $ledger = app(TransactionService::class);
                $before = $ledger->accountBalanceMinor($subscriber);
                $doc = \App\Models\TreasuryHeldCashCredit::create($record);
                $ledger->recordTransaction($subscriber, 'held_cash_credit', $record['amount'],
                    'تسوية فائض نقدي محتجز لصالح المشترك', $doc);
                $this->need($this->registry->record($context, $uuid, $this->creditLegs($doc, $assignment, $lot), $now),
                    'held_cash_credit_conflict');
                $this->treasury->requiredAudit('treasury.held_cash_credited', ['entity_type' => 'held_cash_credit',
                    'entity_id' => $doc->id, 'actor_user_id' => $actor->id,
                    'after' => $record + ['amount_minor' => $minor, 'cash_movement' => false]]);
                app(PaymentService::class)->recalculateInvoices($subscriber);
                $this->need($ledger->accountBalanceMinor($subscriber) === SignedMinor::add($before, $minor),
                    'held_cash_credit_balance');
                $this->need(app(FinancialReconciliationService::class)->inspect($subscriber)['matches'],
                    'held_cash_credit_reconciliation');
                return array_replace($this->inspectLocked($lot), ['created' => true, 'discharge_created' => true,
                    'subscriber_credit_created' => true]);
            });
    }

    private function creditedForAssignment(object $assignment, object $lot, bool $periodProof): int
    {
        $credited = 0;
        foreach (\App\Models\TreasuryHeldCashCredit::where('assignment_uuid', $assignment->operation_uuid)
            ->orderBy('id')->lockForUpdate()->get() as $doc) {
            $minor = \App\Support\AccountMoney::stored($doc);
            $this->need($minor > 0 && (int) $doc->subscriber_id === (int) $assignment->subscriber_id
                && $doc->effective_on >= $assignment->effective_on
                && (int) $doc->approved_by !== (int) $assignment->approved_by, 'held_cash_credit_source');
            $audit = \App\Models\AuditEvent::query()->where('event', 'treasury.held_cash_credited')
                ->where('entity_type', 'held_cash_credit')->where('entity_id', (string) $doc->id)->lockForUpdate()->get();
            $this->need($audit->count() === 1 && (int) $audit->first()->actor_user_id === (int) $doc->approved_by
                && ($audit->first()->after['cash_movement'] ?? null) === false
                && ($audit->first()->after['amount_minor'] ?? null) === $minor, 'held_cash_credit_audit');
            foreach (['operation_uuid', 'assignment_uuid', 'subscriber_id', 'approved_by', 'effective_on', 'reference', 'evidence'] as $key) {
                $this->need(array_key_exists($key, $audit->first()->after ?? [])
                    && (string) $audit->first()->after[$key] === (string) $doc->$key, 'held_cash_credit_audit');
            }
            $this->registered($doc->operation_uuid, $doc->effective_on, $this->creditLegs($doc, $assignment, $lot), true, $periodProof);
            $credited = SignedMinor::add($credited, $minor);
        }
        return $credited;
    }

    private function creditLegs(\App\Models\TreasuryHeldCashCredit $doc, object $assignment, object $lot): array
    {
        $minor = \App\Support\AccountMoney::stored($doc);
        $rows = \App\Models\Transaction::withTrashed()->where('reference_type', \App\Models\TreasuryHeldCashCredit::class)
            ->where('reference_id', $doc->id)->where('type', 'held_cash_credit')->lockForUpdate()->get();
        $this->need($rows->count() === 1, 'held_cash_credit_source');
        $entry = app(FinancialLedgerEvidence::class)->leg((int) $rows->first()->id);
        $hash = hash('sha256', json_encode(['format' => 1, 'uuid' => $doc->operation_uuid,
            'assignment_uuid' => $doc->assignment_uuid, 'assignment_hash' => $this->legs($assignment, $lot)[0]['source_hash'],
            'credit_id' => (string) $doc->id, 'subscriber_id' => (string) $doc->subscriber_id,
            'approved_by' => (string) $doc->approved_by, 'amount_minor' => (string) $minor,
            'effective_on' => $doc->effective_on, 'reference' => $doc->reference, 'evidence' => $doc->evidence,
            'ledger_hash' => $entry['source_hash']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return [$entry, ['source_type' => 'held_cash_credit', 'source_id' => (string) $doc->id,
            'leg' => 'identified_liability', 'amount_minor' => SignedMinor::negate($minor), 'currency' => 'YER', 'source_hash' => $hash]];
    }

    /** Issuance-lock read path: never obtains scope/period locks after business locks. */
    public function releasedForAdjustment(int $adjustmentId): int
    {
        $lot = DB::table('treasury_held_cash_liabilities')->where('treasury_cash_adjustment_id', $adjustmentId)->lockForUpdate()->first();
        if ($lot === null) return 0;
        [, $original] = $this->verifyLot($lot, false);
        $paid = 0;
        $assigned = 0;
        foreach (DB::table('treasury_held_cash_assignments')->where('liability_id', $lot->id)->orderBy('operation_uuid')->lockForUpdate()->get() as $assignment) {
            $minor = SignedMinor::parse($assignment->amount_minor);
            $this->need($minor > 0 && $assignment->effective_on >= $original->effective_on, 'held_cash_assignment_corrupt');
            $this->registered($assignment->operation_uuid, $assignment->effective_on, $this->legs($assignment, $lot), true, false);
            $assigned = SignedMinor::add($assigned, $minor);
            $this->need($assigned <= SignedMinor::parse($lot->amount_minor), 'held_cash_capacity_exceeded');
            $released = SignedMinor::add($this->paidForAssignment($assignment, $lot, $original, false),
                $this->creditedForAssignment($assignment, $lot, false));
            $this->need(SignedMinor::add($released, $this->pendingAuthorized($assignment, $original)) <= $minor, 'held_cash_credit_capacity');
            $paid = SignedMinor::add($paid, $released);
        }
        return $paid;
    }

    public function checkPayoutPosting(object $posting): bool
    {
        try {
            $row = DB::table('treasury_held_cash_payouts')->where('operation_uuid', $posting->held_cash_payout_uuid)->first();
            $this->need($row !== null, 'held_cash_payout_missing');
            $assignment = DB::table('treasury_held_cash_assignments')->where('operation_uuid', $row->assignment_uuid)->first();
            $this->need($assignment !== null, 'held_cash_assignment_missing');
            $lot = $this->lot((int) $assignment->liability_id);
            [, $original] = $this->verifyLot($lot, false);
            $actual = DB::table('treasury_postings')->where('held_cash_payout_uuid', $row->operation_uuid)->lockForUpdate()->first();
            $this->need($actual !== null, 'held_cash_payout_source');
            foreach (['id', 'treasury_account_id', 'kind', 'held_cash_payout_uuid', 'amount_minor',
                'effective_on', 'evidence_reference', 'posted_by', 'cash_handover_id', 'payment_refund_id',
                'cash_session_funding_id', 'treasury_transfer_id', 'treasury_transfer_receipt_id',
                'treasury_bank_fee_id', 'treasury_cash_adjustment_id', 'treasury_bank_collection_id',
                'treasury_late_cash_receipt_id'] as $field) {
                $this->need(property_exists($posting, $field)
                    && ($posting->$field === null) === ($actual->$field === null)
                    && (string) $posting->$field === (string) $actual->$field, 'held_cash_payout_source');
            }
            $this->releasedForAdjustment((int) $lot->treasury_cash_adjustment_id);
            return true;
        } catch (DomainException|\InvalidArgumentException|\OverflowException) {
            return false;
        }
    }

    private function paidForAssignment(object $assignment, object $lot, object $original, bool $periodProof): int
    {
        $paid = 0;
        foreach (DB::table('treasury_held_cash_payouts')->where('assignment_uuid', $assignment->operation_uuid)
            ->orderBy('operation_uuid')->lockForUpdate()->get() as $row) {
            $minor = SignedMinor::parse($row->amount_minor);
            $posting = DB::table('treasury_postings')->where('held_cash_payout_uuid', $row->operation_uuid)->lockForUpdate()->first();
            $this->need($minor > 0 && $row->effective_on >= $assignment->effective_on && $posting !== null
                && $posting->kind === 'held_cash_payout' && (int) $posting->treasury_account_id === (int) $original->treasury_account_id
                && SignedMinor::parse($posting->amount_minor) === SignedMinor::negate($minor)
                && $posting->effective_on === $row->effective_on && $posting->evidence_reference === $row->reference
                && (int) $posting->posted_by === (int) $row->approved_by
                && (int) $row->approved_by !== (int) $assignment->approved_by, 'held_cash_payout_source');
            foreach (['cash_handover_id', 'payment_refund_id', 'cash_session_funding_id', 'treasury_transfer_id',
                'treasury_transfer_receipt_id', 'treasury_bank_fee_id', 'treasury_cash_adjustment_id',
                'treasury_bank_collection_id', 'treasury_late_cash_receipt_id'] as $column) {
                $this->need($posting->$column === null, 'held_cash_payout_source');
            }
            $audit = \App\Models\AuditEvent::query()->where('event', 'treasury.held_cash_paid')
                ->where('entity_type', 'held_cash_payout')->where('entity_id', $row->operation_uuid)->lockForUpdate()->get();
            $this->need($audit->count() === 1 && (int) $audit->first()->actor_user_id === (int) $row->approved_by,
                'held_cash_payout_audit');
            foreach (['operation_uuid', 'assignment_uuid', 'approved_by', 'amount_minor', 'effective_on', 'reference', 'evidence'] as $key) {
                $this->need(array_key_exists($key, $audit->first()->after ?? [])
                    && (string) $audit->first()->after[$key] === (string) $row->$key, 'held_cash_payout_audit');
            }
            $auth = DB::table('treasury_held_cash_payout_authorizations')->where('operation_uuid', $row->operation_uuid)->lockForUpdate()->first();
            $this->need($auth !== null && $this->authorizationState($auth, $assignment, $original) === 'paid',
                'held_cash_payout_authorization_required');
            $authAuditId = $this->authorizationAudit('treasury.held_cash_payout_authorized', $auth->operation_uuid, (int) $auth->authorized_by, (array) $auth);
            $this->need($authAuditId < (int) $audit->first()->id && $auth->reference !== $row->reference, 'held_cash_payout_authorization_mismatch');
            $this->registered($row->operation_uuid, $row->effective_on, $this->payoutLegs($row, $assignment, $lot, $auth), true, $periodProof);
            $paid = SignedMinor::add($paid, $minor);
            $this->need($paid <= SignedMinor::parse($assignment->amount_minor), 'held_cash_payout_capacity');
        }
        return $paid;
    }

    private function payoutLegs(object $row, object $assignment, object $lot, object $auth): array
    {
        $minor = SignedMinor::parse($row->amount_minor);
        $hash = hash('sha256', json_encode(['format' => 1, 'uuid' => $row->operation_uuid,
            'assignment_uuid' => $row->assignment_uuid, 'assignment_hash' => $this->legs($assignment, $lot)[0]['source_hash'],
            'authorization_hash' => hash('sha256', json_encode(array_map('strval', (array) $auth), JSON_THROW_ON_ERROR)),
            'approved_by' => (string) $row->approved_by, 'amount_minor' => (string) $minor,
            'effective_on' => $row->effective_on, 'reference' => $row->reference, 'evidence' => $row->evidence],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $base = ['source_type' => 'held_cash_payout', 'source_id' => $row->operation_uuid,
            'currency' => 'YER', 'source_hash' => $hash, 'amount_minor' => SignedMinor::negate($minor)];
        return [$base + ['leg' => 'identified_liability'], $base + ['leg' => 'treasury']];
    }

    /** Reconstruct registered reclassifications, never infer ownership from a balance. */
    public function inspect(int $liabilityId): array
    {
        $this->lock();
        return $this->inspectLocked($this->lot($liabilityId));
    }

    /**
     * Bidirectional coverage for this subledger only. No whole-system proof.
     * Missing legacy recognition is a blocker, never a reason to backfill.
     */
    public function verifyCoverage(): array
    {
        $this->lock();
        $lots = DB::table('treasury_held_cash_liabilities')->orderBy('id')->lockForUpdate()->get();
        $adjustments = DB::table('treasury_cash_adjustments')->where('classification', 'unattributed_cash_held')
            ->orderBy('id')->lockForUpdate()->get();
        $this->need($lots->count() === $adjustments->count(), 'held_cash_coverage_missing_recognition');
        $this->need(! DB::table('financial_posting_events as e')
            ->leftJoin('treasury_held_cash_assignments as a', 'a.operation_uuid', '=', 'e.source_id')
            ->where('e.source_type', 'held_cash_assignment')->whereNull('a.operation_uuid')->first(),
            'held_cash_coverage_orphan_event');
        $this->need(! DB::table('financial_posting_events as e')
            ->leftJoin('treasury_held_cash_payouts as p', 'p.operation_uuid', '=', 'e.source_id')
            ->where('e.source_type', 'held_cash_payout')->whereNull('p.operation_uuid')->first(),
            'held_cash_coverage_orphan_event');
        $this->need(! DB::table('financial_posting_events as e')
            ->leftJoin('treasury_held_cash_credits as c', 'c.operation_uuid', '=', 'e.operation_uuid')
            ->where('e.source_type', 'held_cash_credit')->whereNull('c.id')->first(), 'held_cash_coverage_orphan_event');
        foreach (['treasury.held_cash_payout_authorized' => 'treasury_held_cash_payout_authorizations',
            'treasury.held_cash_payout_cancelled' => 'treasury_held_cash_payout_cancellations'] as $event => $table) {
            $proofs = \App\Models\AuditEvent::query()->where('event', $event)->lockForUpdate()->get();
            $documents = DB::table($table)->lockForUpdate()->get();
            $this->need($proofs->count() === $documents->count(), 'held_cash_authorization_coverage');
            foreach ($proofs as $proof) {
                $this->need($proof->entity_type === 'held_cash_payout_authorization'
                    && $documents->contains(fn ($doc) => $doc->operation_uuid === $proof->entity_id), 'held_cash_authorization_coverage');
            }
        }
        $held = 0;
        $identified = 0;
        $paid = 0;
        $credited = 0;
        $pending = 0;
        foreach ($lots as $lot) {
            $report = $this->inspectLocked($lot);
            $held = SignedMinor::add($held, $report['held_minor']);
            $identified = SignedMinor::add($identified, $report['identified_minor']);
            $paid = SignedMinor::add($paid, $report['paid_minor']);
            $credited = SignedMinor::add($credited, $report['credited_minor']);
            $pending = SignedMinor::add($pending, $report['pending_authorized_minor']);
        }
        return ['liabilities' => $lots->count(), 'held_minor' => $held,
            'identified_minor' => $identified, 'paid_minor' => $paid, 'pending_authorized_minor' => $pending,
            'credited_minor' => $credited, 'outstanding_minor' => SignedMinor::subtract(SignedMinor::subtract($held, $paid), $credited),
            'unidentified_minor' => SignedMinor::subtract(SignedMinor::subtract(SignedMinor::subtract($held, $identified), $paid), $credited),
            'scope' => 'held_cash_only', 'source_coverage_verified' => false, 'closure_authorized' => false];
    }

    private function inspectLocked(object $lot): array
    {
        [, $posting] = $this->verifyLot($lot);
        $total = SignedMinor::parse($lot->amount_minor);
        $assigned = 0;
        $owners = [];
        $pending = 0;
        $paid = 0;
        $credited = 0;
        $rows = DB::table('treasury_held_cash_assignments')->where('liability_id', $lot->id)
            ->orderBy('operation_uuid')->lockForUpdate()->get();
        foreach ($rows as $row) {
            $minor = SignedMinor::parse($row->amount_minor);
            $this->need($minor > 0 && $row->effective_on >= $posting->effective_on, 'held_cash_assignment_corrupt');
            $this->registered($row->operation_uuid, $row->effective_on, $this->legs($row, $lot), true);
            $assigned = SignedMinor::add($assigned, $minor);
            $this->need($assigned <= $total, 'held_cash_capacity_exceeded');
            $owner = (string) $row->subscriber_id;
            $cashPaid = $this->paidForAssignment($row, $lot, $posting, true);
            $accountCredit = $this->creditedForAssignment($row, $lot, true);
            $released = SignedMinor::add($cashPaid, $accountCredit);
            $authorized = $this->pendingAuthorized($row, $posting);
            $this->need(SignedMinor::add($released, $authorized) <= $minor, 'held_cash_credit_capacity');
            $pending = SignedMinor::add($pending, $authorized);
            $paid = SignedMinor::add($paid, $cashPaid);
            $credited = SignedMinor::add($credited, $accountCredit);
            $owners[$owner] = SignedMinor::add($owners[$owner] ?? 0, SignedMinor::subtract($minor, $released));
        }
        return ['liability_id' => (int) $lot->id, 'held_minor' => $total,
            'identified_minor' => SignedMinor::subtract(SignedMinor::subtract($assigned, $paid), $credited), 'paid_minor' => $paid,
            'credited_minor' => $credited, 'outstanding_minor' => SignedMinor::subtract(SignedMinor::subtract($total, $paid), $credited), 'unidentified_minor' => SignedMinor::subtract($total, $assigned),
            'identified_by_subscriber' => $owners, 'pending_authorized_minor' => $pending, 'cash_movement_created' => false,
            'discharge_created' => false, 'certified_balance' => false];
    }

    private function verifyLot(object $lot, bool $periodProof = true): array
    {
        [$adjustment, $posting, $leg, $operation] = $this->source((int) $lot->treasury_cash_adjustment_id, $periodProof);
        $this->need((int) $lot->treasury_posting_id === (int) $posting->id
            && $lot->operation_uuid === $operation->operation_uuid
            && SignedMinor::parse($lot->amount_minor) === $leg['amount_minor']
            && hash_equals($lot->source_hash, $leg['source_hash']), 'held_cash_recognition_conflict');
        return [$adjustment, $posting];
    }

    private function source(int $id, bool $periodProof = true): array
    {
        $adjustment = DB::table('treasury_cash_adjustments')->where('id', $id)->lockForUpdate()->first();
        $this->need($adjustment !== null && $adjustment->classification === 'unattributed_cash_held',
            'held_cash_source_classification');
        $posting = DB::table('treasury_postings')->where('treasury_cash_adjustment_id', $id)->lockForUpdate()->first();
        $this->need($posting !== null, 'held_cash_source_missing');
        $leg = $this->adjustments->leg((int) $posting->id);
        $this->need($leg['amount_minor'] > 0 && $leg['amount_minor'] === SignedMinor::parse($adjustment->amount_minor),
            'held_cash_source_amount');
        $event = DB::table('financial_posting_events')->where('scope_key', 'water')
            ->where('source_type', 'treasury_posting')->where('source_id', (string) $posting->id)
            ->where('leg', 'treasury')->lockForUpdate()->first();
        $this->need($event !== null, 'held_cash_source_unregistered');
        $operation = $this->registered($event->operation_uuid, $posting->effective_on, [$leg], true, $periodProof);
        return [$adjustment, $posting, $leg, $operation];
    }

    private function registered(string $uuid, string $date, array $legs, bool $exact, bool $periodProof = true): object
    {
        $op = DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->lockForUpdate()->first();
        $this->need($op !== null && $op->scope_key === 'water' && $op->effective_on === $date,
            'held_cash_source_unregistered');
        $events = DB::table('financial_posting_events')->where('operation_uuid', $uuid)->lockForUpdate()->get();
        $this->need(! $exact || $events->count() === count($legs), 'held_cash_registry_mismatch');
        foreach ($legs as $leg) {
            $row = $events->first(fn ($event) => $event->source_type === $leg['source_type']
                && $event->source_id === $leg['source_id'] && $event->leg === $leg['leg']);
            $this->need($row !== null && $row->scope_key === 'water', 'held_cash_registry_mismatch');
            foreach ($leg as $key => $value) {
                $this->need((string) $row->$key === (string) $value, 'held_cash_registry_mismatch');
            }
        }
        $canonical = [];
        foreach ($legs as $leg) {
            $key = $leg['source_type'].'|'.$leg['source_id'].'|'.$leg['leg'];
            $canonical[$key] = ['source_type' => $leg['source_type'], 'source_id' => $leg['source_id'],
                'leg' => $leg['leg'], 'amount_minor' => (string) $leg['amount_minor'], 'currency' => $leg['currency'],
                'source_hash' => $leg['source_hash']];
        }
        ksort($canonical, SORT_STRING);
        $bytes = json_encode(['format' => 1, 'operation_uuid' => $uuid, 'scope' => 'water',
            'period_id' => (int) $op->period_id, 'effective_on' => $date, 'epoch' => (int) $op->fence_epoch,
            'version' => (int) $op->period_version, 'legs' => array_values($canonical)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->need($bytes === $op->canonical_payload && hash_equals(hash('sha256', $bytes), $op->payload_hash),
            'held_cash_registry_mismatch');
        if ($periodProof) $this->registry->verifyPeriod('water', (int) $op->period_id);
        return $op;
    }

    private function legs(object $row, object $lot): array
    {
        $minor = SignedMinor::parse($row->amount_minor);
        $bytes = json_encode(['format' => 1, 'operation_uuid' => $row->operation_uuid,
            'liability_id' => (string) $row->liability_id, 'original_hash' => $lot->source_hash,
            'subscriber_id' => (string) $row->subscriber_id, 'approved_by' => (string) $row->approved_by,
            'amount_minor' => (string) $minor, 'effective_on' => $row->effective_on,
            'reference' => $row->reference, 'evidence' => $row->evidence, 'currency' => 'YER'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $base = ['source_type' => 'held_cash_assignment', 'source_id' => $row->operation_uuid,
            'currency' => 'YER', 'source_hash' => hash('sha256', $bytes)];
        return [$base + ['leg' => 'unidentified_liability', 'amount_minor' => SignedMinor::negate($minor)],
            $base + ['leg' => 'identified_liability', 'amount_minor' => $minor]];
    }

    private function lot(int $id): object
    {
        $row = DB::table('treasury_held_cash_liabilities')->where('id', $id)->lockForUpdate()->first();
        $this->need($row !== null, 'held_cash_liability_missing');
        return $row;
    }

    /** Same reference namespace across held documents and existing cash evidence. */
    private function assertUnusedReference(string $reference, string $reason): void
    {
        foreach ([
            'treasury_held_cash_assignments' => 'reference',
            'treasury_held_cash_payout_authorizations' => 'reference',
            'treasury_held_cash_payouts' => 'reference',
            'treasury_held_cash_credits' => 'reference',
            'treasury_accounts' => 'opening_reference',
            'treasury_postings' => 'evidence_reference',
            'cash_handovers' => 'reference',
            'payment_refunds' => 'disbursement_reference',
            'late_receipt_reviews' => 'evidence_reference',
            'cash_session_fundings' => 'reference',
            'treasury_cash_counts' => 'reference',
        ] as $table => $column) {
            $this->need(! DB::table($table)->where($column, $reference)->exists(), $reason);
        }
    }

    private function lock(): void
    {
        $this->need(DB::transactionLevel() > 0, 'held_cash_requires_gate');
        $scope = DB::table('financial_scopes')->where('scope_key', 'water')->lockForUpdate()->first();
        $this->need($scope !== null && $scope->mode === 'ACTIVE' && $scope->currency === 'YER',
            'held_cash_requires_gate');
        // Acquire period locks before business locks on the isolated branch.
        DB::table('financial_periods')->where('scope_key', 'water')->orderBy('id')->lockForUpdate()->get();
        $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null,
            'held_cash_issuance_lock');
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
