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
 * No discharge, revenue recognition, legacy adoption or closure authorization.
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
                $this->need(! DB::table('treasury_held_cash_assignments')->where('reference', $reference)->lockForUpdate()->first()
                    && ! DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->lockForUpdate()->first(),
                    'held_cash_assignment_conflict');
                $this->need($this->registry->record($context, $uuid, $legs, $now), 'held_cash_assignment_conflict');
                DB::table('treasury_held_cash_assignments')->insert($row);
                return $this->inspectLocked($lot) + ['created' => true];
            });
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
                $legs = $this->payoutLegs((object) $row, $assignment, $lot);
                $old = DB::table('treasury_held_cash_payouts')->where('operation_uuid', $uuid)->lockForUpdate()->first();
                if ($old !== null) {
                    foreach ($row as $key => $value) {
                        $this->need((string) $old->$key === (string) $value, 'held_cash_payout_conflict');
                    }
                    $this->need(! $this->registry->record($context, $uuid, $legs, $now), 'held_cash_payout_conflict');
                    return $this->inspectLocked($lot) + ['created' => false];
                }
                $paid = $this->paidForAssignment($assignment, $lot, $original, true);
                $this->need($minor <= SignedMinor::subtract(SignedMinor::parse($assignment->amount_minor), $paid),
                    'held_cash_payout_capacity');
                foreach (['payments', 'late_receipt_reviews', 'noncash_collection_claims'] as $table) {
                    $this->need(! DB::table($table)->where('sync_uuid', $uuid)->lockForUpdate()->first(), 'held_cash_uuid_reserved');
                }
                $this->need(! DB::table('treasury_held_cash_payouts')->where('reference', $reference)->exists()
                    && ! DB::table('treasury_held_cash_assignments')->where('reference', $reference)->exists()
                    && ! DB::table('treasury_accounts')->where('opening_reference', $reference)->exists()
                    && ! DB::table('treasury_postings')->where('evidence_reference', $reference)->exists()
                    && ! DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->exists(), 'held_cash_payout_conflict');
                $account = DB::table('treasury_accounts')->where('id', $original->treasury_account_id)->lockForUpdate()->first();
                $this->need($account !== null && $account->channel === 'cash', 'held_cash_payout_account');
                $balance = \App\Support\TreasuryMoney::document($account->opening_minor);
                foreach (DB::table('treasury_postings')->where('treasury_account_id', $account->id)->orderBy('id')->lockForUpdate()->get() as $posting) {
                    $this->need($posting->effective_on <= $date, 'held_cash_outflow_backdated');
                    $balance = SignedMinor::add($balance, SignedMinor::parse($posting->amount_minor));
                }
                // Preserve every other held claim. A shortage cannot be hidden by paying one creditor first.
                $reserved = app(HeldCashReservation::class)->outstandingForAccount((int) $account->id);
                $this->need($balance >= $reserved && $reserved >= $minor, 'held_cash_payout_insufficient_cash');
                $this->need($this->registry->record($context, $uuid, $legs, $now), 'held_cash_payout_conflict');
                DB::table('treasury_held_cash_payouts')->insert($row);
                DB::table('treasury_postings')->insert([
                    'treasury_account_id' => $account->id, 'held_cash_payout_uuid' => $uuid, 'kind' => 'held_cash_payout',
                    'amount_minor' => SignedMinor::negate($minor), 'effective_on' => $date, 'evidence_reference' => $reference,
                    'posted_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->treasury->requiredAudit('treasury.held_cash_paid', ['entity_type' => 'held_cash_payout',
                    'entity_id' => $uuid, 'actor_user_id' => $actor->id, 'after' => $row]);
                return $this->inspectLocked($lot) + ['created' => true];
            });
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
            $paid = SignedMinor::add($paid, $this->paidForAssignment($assignment, $lot, $original, false));
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
            $this->paidForAssignment($assignment, $lot, $original, false);
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
            $this->registered($row->operation_uuid, $row->effective_on, $this->payoutLegs($row, $assignment, $lot), true, $periodProof);
            $paid = SignedMinor::add($paid, $minor);
            $this->need($paid <= SignedMinor::parse($assignment->amount_minor), 'held_cash_payout_capacity');
        }
        return $paid;
    }

    private function payoutLegs(object $row, object $assignment, object $lot): array
    {
        $minor = SignedMinor::parse($row->amount_minor);
        $hash = hash('sha256', json_encode(['format' => 1, 'uuid' => $row->operation_uuid,
            'assignment_uuid' => $row->assignment_uuid, 'assignment_hash' => $this->legs($assignment, $lot)[0]['source_hash'],
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
        $held = 0;
        $identified = 0;
        $paid = 0;
        foreach ($lots as $lot) {
            $report = $this->inspectLocked($lot);
            $held = SignedMinor::add($held, $report['held_minor']);
            $identified = SignedMinor::add($identified, $report['identified_minor']);
            $paid = SignedMinor::add($paid, $report['paid_minor']);
        }
        return ['liabilities' => $lots->count(), 'held_minor' => $held,
            'identified_minor' => $identified, 'paid_minor' => $paid,
            'outstanding_minor' => SignedMinor::subtract($held, $paid),
            'unidentified_minor' => SignedMinor::subtract(SignedMinor::subtract($held, $identified), $paid),
            'scope' => 'held_cash_only', 'source_coverage_verified' => false, 'closure_authorized' => false];
    }

    private function inspectLocked(object $lot): array
    {
        [, $posting] = $this->verifyLot($lot);
        $total = SignedMinor::parse($lot->amount_minor);
        $assigned = 0;
        $owners = [];
        $paid = 0;
        $rows = DB::table('treasury_held_cash_assignments')->where('liability_id', $lot->id)
            ->orderBy('operation_uuid')->lockForUpdate()->get();
        foreach ($rows as $row) {
            $minor = SignedMinor::parse($row->amount_minor);
            $this->need($minor > 0 && $row->effective_on >= $posting->effective_on, 'held_cash_assignment_corrupt');
            $this->registered($row->operation_uuid, $row->effective_on, $this->legs($row, $lot), true);
            $assigned = SignedMinor::add($assigned, $minor);
            $this->need($assigned <= $total, 'held_cash_capacity_exceeded');
            $owner = (string) $row->subscriber_id;
            $released = $this->paidForAssignment($row, $lot, $posting, true);
            $paid = SignedMinor::add($paid, $released);
            $owners[$owner] = SignedMinor::add($owners[$owner] ?? 0, SignedMinor::subtract($minor, $released));
        }
        return ['liability_id' => (int) $lot->id, 'held_minor' => $total,
            'identified_minor' => SignedMinor::subtract($assigned, $paid), 'paid_minor' => $paid,
            'outstanding_minor' => SignedMinor::subtract($total, $paid), 'unidentified_minor' => SignedMinor::subtract($total, $assigned),
            'identified_by_subscriber' => $owners, 'cash_movement_created' => false,
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
        $this->need(hash_equals(hash('sha256', $op->canonical_payload), $op->payload_hash), 'held_cash_registry_mismatch');
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
