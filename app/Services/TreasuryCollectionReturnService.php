<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DomainException;

/**
 * Isolated A-G1A-01 decision planner. No I/O, database access or ledger writes.
 * The future adapter must build facts from locked, independently verified records.
 */
final class TreasuryCollectionReturnService
{
    /**
     * A pre-clearance rejection cannot create either a receipt or a financial leg.
     * No state transition is persisted by this method.
     *
     * @return array<string, mixed>
     */
    public function planUnclearedRejection(array $claim, array $decision, string $asOf): array
    {
        $this->date($asOf);
        $claimId = $this->positive($claim, 'id');
        $amount = $this->positive($claim, 'amount_minor');
        $issuer = $this->positive($claim, 'submitted_by');
        $instrument = $this->text($claim, 'instrument_reference', 1, 150);
        $instrumentOn = $this->date($claim['instrument_on'] ?? null);
        $this->require(($claim['method'] ?? null) === 'check', 'not_check');
        $this->require(($claim['status'] ?? null) === 'pending', 'claim_not_pending');
        $this->require($this->flag($claim, 'has_payment') === false
            && $this->flag($claim, 'has_financial_effect') === false, 'claim_already_posted');
        $this->require($this->flag($decision, 'rejection_proof_valid'), 'invalid_rejection_proof');
        $approval = $this->decision($decision, $issuer);
        $rejectedOn = $this->date($decision['rejected_on'] ?? null);
        $this->require($rejectedOn >= $instrumentOn && $rejectedOn <= $asOf, 'invalid_rejection_date');

        return [
            'kind' => 'reject_uncleared_check',
            'claim_id' => $claimId,
            'instrument_reference' => $instrument,
            'amount_minor' => $amount,
            'rejected_on' => $rejectedOn,
            'approval' => $approval,
            'next_state' => 'rejected',
            'subscriber_delta_minor' => 0,
            'bank_delta_minor' => 0,
            'create_payment' => false,
        ];
    }

    /**
     * Plan a FULL return of a previously cleared cheque. A successful result is
     * a candidate plan, not authorization to post it. Existing plan is persisted
     * provenance supplied by the future adapter, never a client-supplied payload.
     *
     * @return array{status: string, plan: array<string, mixed>}
     */
    public function planFullReturn(
        array $original,
        array $debit,
        array $decision,
        array $state,
        string $asOf,
    ): array {
        $this->date($asOf);
        $paymentId = $this->positive($original, 'payment_id');
        $subscriberId = $this->positive($original, 'subscriber_id');
        $collectionId = $this->positive($original, 'collection_id');
        $accountId = $this->positive($original, 'bank_account_id');
        $creditLineId = $this->positive($original, 'credit_line_id');
        $amount = $this->positive($original, 'amount_minor');
        $issuer = $this->positive($original, 'submitted_by');
        $instrument = $this->text($original, 'instrument_reference', 1, 150);
        $clearedOn = $this->date($original['cleared_on'] ?? null);
        $this->require(($original['method'] ?? null) === 'check', 'not_check');
        $this->require($this->flag($original, 'historical_proof_valid'), 'invalid_original_proof');
        // Do not touch closed-till or legacy cash-session corrections in this slice.
        $this->require(array_key_exists('cash_session_id', $original)
            && $original['cash_session_id'] === null, 'cash_session_link_requires_review');

        $lineId = $this->positive($debit, 'line_id');
        $lineAccount = $this->positive($debit, 'bank_account_id');
        $importer = $this->positive($debit, 'imported_by');
        $bankReference = $this->text($debit, 'bank_reference', 1, 150);
        $bookedOn = $this->date($debit['booked_on'] ?? null);
        $this->require($this->flag($debit, 'proof_valid'), 'invalid_debit_proof');
        $this->require($lineId !== $creditLineId && $lineAccount === $accountId, 'wrong_bank_line');
        // Equal amount and account are insufficient: require evidence that this
        // debit is the return of this specific cleared instrument.
        $this->require($this->flag($debit, 'return_link_verified')
            && $this->positive($debit, 'linked_collection_id') === $collectionId
            && ($debit['linked_instrument_reference'] ?? null) === $instrument, 'unproven_return_link');
        $this->require($bookedOn >= $clearedOn && $bookedOn <= $asOf, 'invalid_return_date');
        // No conversion, rounding, subtraction of fees or partial allocation.
        $this->require(is_int($debit['amount_minor'] ?? null)
            && $debit['amount_minor'] === -$amount, 'not_full_return');

        $approval = $this->decision($decision, $issuer, $importer);
        $plan = [
            'kind' => 'full_check_return',
            'payment_id' => $paymentId,
            'subscriber_id' => $subscriberId,
            'collection_id' => $collectionId,
            'bank_account_id' => $accountId,
            'credit_line_id' => $creditLineId,
            'return_line_id' => $lineId,
            'instrument_reference' => $instrument,
            'bank_reference' => $bankReference,
            'original_submitted_by' => $issuer,
            'debit_imported_by' => $importer,
            'cleared_on' => $clearedOn,
            'returned_on' => $bookedOn,
            'amount_minor' => $amount,
            'approval' => $approval,
            // Subscriber ledger uses positive payment credit and negative reversal.
            'subscriber_delta_minor' => -$amount,
            'bank_delta_minor' => -$amount,
            'next_state' => 'returned',
            'create_payment' => false,
            'preserve_original_proof' => true,
            'reallocate_invoices' => true,
        ];

        $lineUsed = $this->flag($state, 'line_consumed');
        $referenceUsed = $this->flag($state, 'reference_consumed');
        $uuidUsed = $this->flag($state, 'request_uuid_consumed');
        $reversed = $this->flag($state, 'payment_reversed');
        $periodOpen = $this->flag($state, 'period_open');
        $refund = $this->flag($state, 'has_refund');
        $transfer = $this->flag($state, 'has_account_transfer');
        $correction = $this->flag($state, 'has_other_correction');
        $void = $this->flag($state, 'has_pending_void');
        $this->require(array_key_exists('existing_plan', $state), 'missing_existing_plan');

        if ($state['existing_plan'] !== null) {
            // Compare the complete canonical plan, not just the UUID or amount.
            // A read-only replay remains possible after the posting period closes.
            $this->require($state['existing_plan'] === $plan
                && $lineUsed && $referenceUsed && $uuidUsed && $reversed
                && $this->flag($state, 'existing_effects_valid'), 'return_replay_conflict');

            return ['status' => 'replay', 'plan' => $plan];
        }

        $this->require(!$lineUsed && !$referenceUsed && !$uuidUsed && !$reversed, 'already_consumed');
        $this->require($periodOpen, 'period_not_open');
        $this->require(!$refund && !$transfer && !$correction && !$void, 'compound_case_requires_review');

        return ['status' => 'planned', 'plan' => $plan];
    }

    /** @return array<string, mixed> */
    private function decision(array $decision, int $issuer, ?int $importer = null): array
    {
        $actor = $this->positive($decision, 'approved_by');
        $initiator = $this->positive($decision, 'requested_by');
        $this->require($this->flag($decision, 'authorized'), 'unauthorized');
        $this->require($actor !== $issuer && $actor !== $initiator && $actor !== $importer, 'self_approval');
        $uuid = $this->text($decision, 'request_uuid', 36, 36);
        $this->require(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1, 'invalid_uuid');

        return [
            'request_uuid' => $uuid,
            'reference' => $this->text($decision, 'reference', 3, 150),
            'evidence' => $this->text($decision, 'evidence', 10, 2000),
            'requested_by' => $initiator,
            'approved_by' => $actor,
        ];
    }

    private function positive(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        $this->require(is_int($value) && $value > 0, 'invalid_'.$key);

        return $value;
    }

    private function flag(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;
        $this->require(is_bool($value), 'invalid_'.$key);

        return $value;
    }

    private function text(array $row, string $key, int $min, int $max): string
    {
        $value = $row[$key] ?? null;
        $this->require(is_string($value) && trim($value) === $value
            && mb_check_encoding($value, 'UTF-8') && mb_strlen($value) >= $min
            && mb_strlen($value) <= $max, 'invalid_'.$key);

        return $value;
    }

    private function date(mixed $value): string
    {
        $this->require(is_string($value) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value) === 1, 'invalid_date');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $this->require($parsed !== false && $parsed->format('Y-m-d') === $value && substr($value, 0, 4) !== '0000', 'invalid_date');

        return $value;
    }

    private function require(bool $valid, string $code): void
    {
        if (!$valid) {
            throw new DomainException($code);
        }
    }
}
