<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TreasuryCollectionReturnService;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TreasuryCollectionReturnServiceTest extends TestCase
{
    private const AS_OF = '2026-11-25';

    private static function original(): array
    {
        return [
            'payment_id' => 10, 'subscriber_id' => 20, 'collection_id' => 30,
            'bank_account_id' => 40, 'credit_line_id' => 50, 'amount_minor' => 10001,
            'submitted_by' => 1, 'instrument_reference' => 'CHECK-123',
            'cleared_on' => '2026-11-20', 'method' => 'check',
            'historical_proof_valid' => true, 'cash_session_id' => null,
        ];
    }

    private static function debit(): array
    {
        return [
            'line_id' => 51, 'bank_account_id' => 40, 'imported_by' => 2,
            'bank_reference' => 'DEBIT-51', 'booked_on' => '2026-11-22',
            'proof_valid' => true, 'amount_minor' => -10001,
            'return_link_verified' => true, 'linked_collection_id' => 30,
            'linked_instrument_reference' => 'CHECK-123',
        ];
    }

    private static function decision(): array
    {
        return [
            'request_uuid' => '27b7cb50-8b2e-4324-a763-3d9c0cc8a443',
            'reference' => 'RETURN-123', 'evidence' => 'إثبات بنكي مستقل لارتجاع الشيك',
            'requested_by' => 3, 'approved_by' => 4, 'authorized' => true,
        ];
    }

    private static function state(): array
    {
        return [
            'line_consumed' => false, 'reference_consumed' => false, 'request_uuid_consumed' => false,
            'payment_reversed' => false, 'period_open' => true,
            'has_refund' => false, 'has_account_transfer' => false,
            'has_other_correction' => false, 'has_pending_void' => false,
            'existing_plan' => null,
        ];
    }

    private static function claim(): array
    {
        return [
            'id' => 60, 'amount_minor' => 10001, 'submitted_by' => 1,
            'instrument_reference' => 'CHECK-123', 'instrument_on' => '2026-11-19',
            'method' => 'check', 'status' => 'pending',
            'has_payment' => false, 'has_financial_effect' => false,
        ];
    }

    private static function rejection(): array
    {
        return self::decision() + [
            'rejected_on' => '2026-11-20', 'rejection_proof_valid' => true,
        ];
    }

    public function test_full_return_is_exact_and_preserves_the_source(): void
    {
        $original = self::original();
        $debit = self::debit();
        $decision = self::decision();
        $state = self::state();
        $planner = new TreasuryCollectionReturnService();
        $result = $planner->planFullReturn($original, $debit, $decision, $state, self::AS_OF);
        $p = $result['plan'];
        self::assertSame('planned', $result['status']);
        self::assertSame('full_check_return', $p['kind']);
        self::assertSame(10001, $p['amount_minor']);
        self::assertSame(-10001, $p['subscriber_delta_minor']);
        self::assertSame(-10001, $p['bank_delta_minor']);
        self::assertSame(0, $original['amount_minor'] + $p['subscriber_delta_minor']);
        self::assertSame(0, $original['amount_minor'] + $p['bank_delta_minor']);
        self::assertSame('returned', $p['next_state']);
        self::assertFalse($p['create_payment']);
        self::assertTrue($p['preserve_original_proof']);
        self::assertTrue($p['reallocate_invoices']);
        self::assertSame(10, $p['payment_id']);
        self::assertSame(20, $p['subscriber_id']);
        self::assertSame(30, $p['collection_id']);
        self::assertSame(50, $p['credit_line_id']);
        self::assertSame(51, $p['return_line_id']);
        self::assertSame('CHECK-123', $p['instrument_reference']);
        self::assertSame('2026-11-22', $p['returned_on']);
        self::assertSame(self::original(), $original);
        self::assertSame(self::debit(), $debit);
        self::assertSame(self::decision(), $decision);
        self::assertSame(self::state(), $state);
        self::assertSame($result, $planner->planFullReturn($original, $debit, $decision, $state, self::AS_OF));
    }

    public function test_largest_integer_and_same_day_return_are_exact(): void
    {
        $p = (new TreasuryCollectionReturnService())->planFullReturn(
            array_replace(self::original(), ['amount_minor' => PHP_INT_MAX]),
            array_replace(self::debit(), ['amount_minor' => -PHP_INT_MAX, 'booked_on' => '2026-11-20']),
            self::decision(), self::state(), self::AS_OF,
        )['plan'];
        self::assertSame(-PHP_INT_MAX, $p['subscriber_delta_minor']);
        self::assertSame(-PHP_INT_MAX, $p['bank_delta_minor']);
        self::assertSame(0, PHP_INT_MAX + $p['bank_delta_minor']);
    }

    public function test_pending_rejection_has_no_financial_effect(): void
    {
        $p = (new TreasuryCollectionReturnService())->planUnclearedRejection(self::claim(), self::rejection(), self::AS_OF);
        self::assertSame('rejected', $p['next_state']);
        self::assertSame('reject_uncleared_check', $p['kind']);
        self::assertSame(60, $p['claim_id']);
        self::assertSame(10001, $p['amount_minor']);
        self::assertSame(0, $p['subscriber_delta_minor']);
        self::assertSame(0, $p['bank_delta_minor']);
        self::assertFalse($p['create_payment']);
    }

    public static function rejectedFullReturns(): iterable
    {
        yield 'not a check' => ['original', 'method', 'bank_transfer', 'not_check'];
        yield 'invalid historic proof' => ['original', 'historical_proof_valid', false, 'invalid_original_proof'];
        yield 'cash session linked' => ['original', 'cash_session_id', 7, 'cash_session_link_requires_review'];
        yield 'zero amount' => ['original', 'amount_minor', 0, 'invalid_amount_minor'];
        yield 'negative amount' => ['original', 'amount_minor', -1, 'invalid_amount_minor'];
        yield 'float amount' => ['original', 'amount_minor', 10001.0, 'invalid_amount_minor'];
        yield 'numeric string amount' => ['original', 'amount_minor', '10001', 'invalid_amount_minor'];
        yield 'positive credit line' => ['debit', 'amount_minor', 10001, 'not_full_return'];
        yield 'partial return' => ['debit', 'amount_minor', -10000, 'not_full_return'];
        yield 'fee included' => ['debit', 'amount_minor', -10002, 'not_full_return'];
        yield 'float debit' => ['debit', 'amount_minor', -10001.0, 'not_full_return'];
        yield 'numeric string debit' => ['debit', 'amount_minor', '-10001', 'not_full_return'];
        yield 'invalid debit proof' => ['debit', 'proof_valid', false, 'invalid_debit_proof'];
        yield 'different account' => ['debit', 'bank_account_id', 41, 'wrong_bank_line'];
        yield 'same original line' => ['debit', 'line_id', 50, 'wrong_bank_line'];
        yield 'unverified link' => ['debit', 'return_link_verified', false, 'unproven_return_link'];
        yield 'wrong collection' => ['debit', 'linked_collection_id', 31, 'unproven_return_link'];
        yield 'wrong instrument' => ['debit', 'linked_instrument_reference', 'CHECK-999', 'unproven_return_link'];
        yield 'before clearance' => ['debit', 'booked_on', '2026-11-19', 'invalid_return_date'];
        yield 'future debit' => ['debit', 'booked_on', '2026-11-26', 'invalid_return_date'];
        yield 'impossible date' => ['debit', 'booked_on', '2026-02-30', 'invalid_date'];
        yield 'noncanonical date' => ['debit', 'booked_on', '2026-11-2', 'invalid_date'];
        yield 'year zero' => ['debit', 'booked_on', '0000-11-22', 'invalid_date'];
        yield 'unauthorized' => ['decision', 'authorized', false, 'unauthorized'];
        yield 'issuer approves' => ['decision', 'approved_by', 1, 'self_approval'];
        yield 'importer approves' => ['decision', 'approved_by', 2, 'self_approval'];
        yield 'requester approves' => ['decision', 'approved_by', 3, 'self_approval'];
        yield 'malformed uuid' => ['decision', 'request_uuid', str_repeat('a', 36), 'invalid_uuid'];
        yield 'empty reference' => ['decision', 'reference', '', 'invalid_reference'];
        yield 'short evidence' => ['decision', 'evidence', '123', 'invalid_evidence'];
        yield 'line consumed' => ['state', 'line_consumed', true, 'already_consumed'];
        yield 'reference consumed' => ['state', 'reference_consumed', true, 'already_consumed'];
        yield 'uuid consumed' => ['state', 'request_uuid_consumed', true, 'already_consumed'];
        yield 'already reversed' => ['state', 'payment_reversed', true, 'already_consumed'];
        yield 'closed period' => ['state', 'period_open', false, 'period_not_open'];
        yield 'refund exists' => ['state', 'has_refund', true, 'compound_case_requires_review'];
        yield 'account transfer exists' => ['state', 'has_account_transfer', true, 'compound_case_requires_review'];
        yield 'other correction exists' => ['state', 'has_other_correction', true, 'compound_case_requires_review'];
        yield 'void pending' => ['state', 'has_pending_void', true, 'compound_case_requires_review'];
        yield 'boolean not coerced' => ['state', 'period_open', 'true', 'invalid_period_open'];
    }

    #[DataProvider('rejectedFullReturns')]
    public function test_invalid_full_returns_fail_closed(string $group, string $field, mixed $value, string $code): void
    {
        $args = ['original' => self::original(), 'debit' => self::debit(), 'decision' => self::decision(), 'state' => self::state()];
        $args[$group][$field] = $value;
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage($code);
        (new TreasuryCollectionReturnService())->planFullReturn(...array_values($args), asOf: self::AS_OF);
    }

    public static function rejectedPendingClaims(): iterable
    {
        yield 'not pending' => ['claim', 'status', 'cleared', 'claim_not_pending'];
        yield 'payment exists' => ['claim', 'has_payment', true, 'claim_already_posted'];
        yield 'financial effect exists' => ['claim', 'has_financial_effect', true, 'claim_already_posted'];
        yield 'wrong method' => ['claim', 'method', 'bank_transfer', 'not_check'];
        yield 'bad evidence link' => ['decision', 'rejection_proof_valid', false, 'invalid_rejection_proof'];
        yield 'self approval' => ['decision', 'approved_by', 1, 'self_approval'];
        yield 'future rejection' => ['decision', 'rejected_on', '2026-11-26', 'invalid_rejection_date'];
        yield 'before instrument' => ['decision', 'rejected_on', '2026-11-18', 'invalid_rejection_date'];
    }

    #[DataProvider('rejectedPendingClaims')]
    public function test_invalid_pending_rejections_fail_closed(string $group, string $field, mixed $value, string $code): void
    {
        $args = ['claim' => self::claim(), 'decision' => self::rejection()];
        $args[$group][$field] = $value;
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage($code);
        (new TreasuryCollectionReturnService())->planUnclearedRejection(...array_values($args), asOf: self::AS_OF);
    }

    private function replayState(): array
    {
        $plan = (new TreasuryCollectionReturnService())->planFullReturn(self::original(), self::debit(), self::decision(), self::state(), self::AS_OF)['plan'];
        return array_replace(self::state(), [
            'existing_plan' => $plan, 'existing_effects_valid' => true,
            'line_consumed' => true, 'reference_consumed' => true, 'request_uuid_consumed' => true, 'payment_reversed' => true,
        ]);
    }

    public function test_exact_replay_returns_original_plan_even_after_period_closes(): void
    {
        $state = array_replace($this->replayState(), ['period_open' => false]);
        $result = (new TreasuryCollectionReturnService())->planFullReturn(self::original(), self::debit(), self::decision(), $state, self::AS_OF);
        self::assertSame('replay', $result['status']);
        self::assertSame($state['existing_plan'], $result['plan']);
    }

    public static function badReplays(): iterable
    {
        yield 'different request uuid' => ['decision', 'request_uuid', '27b7cb50-8b2e-4324-a763-3d9c0cc8a444'];
        yield 'different reference' => ['decision', 'reference', 'RETURN-999'];
        yield 'changed approval' => ['decision', 'approved_by', 5];
        yield 'changed evidence' => ['decision', 'evidence', 'Changed evidence for replay'];
        yield 'different debit line' => ['debit', 'line_id', 52];
        yield 'unconsumed line' => ['state', 'line_consumed', false];
        yield 'unconsumed reference' => ['state', 'reference_consumed', false];
        yield 'unconsumed UUID' => ['state', 'request_uuid_consumed', false];
        yield 'missing reversal' => ['state', 'payment_reversed', false];
        yield 'invalid persisted effects' => ['state', 'existing_effects_valid', false];
        yield 'corrupted plan' => ['state', 'existing_plan', ['amount_minor' => 10001]];
    }

    #[DataProvider('badReplays')]
    public function test_replay_with_conflicting_payload_or_effects_is_rejected(string $group, string $field, mixed $value): void
    {
        $args = ['original' => self::original(), 'debit' => self::debit(), 'decision' => self::decision(), 'state' => $this->replayState()];
        $args[$group][$field] = $value;
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('return_replay_conflict');
        (new TreasuryCollectionReturnService())->planFullReturn(...array_values($args), asOf: self::AS_OF);
    }
}
