<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\FinancialPostingRegistry;
use App\Services\FinancialWriteGate;
use App\Models\User;
use App\Models\AuditEvent;
use App\Services\TreasuryRegisterService;
use App\Services\TreasuryTransferService;
use App\Services\TreasuryTransferEvidence;
use App\Services\GatedCashReceiptWriter;
use App\Services\PaymentService;
use App\Models\Subscriber;
use App\Models\Square;
use App\Models\Payment;
use App\Services\GatedCashCountAdjustmentWriter;
use App\Services\TreasuryCashCountService;
use App\Services\GatedTreasuryOpeningFundingWriter;
use App\Services\CashSessionService;
use App\Services\FinancialLedgerRegistryVerifier;
use DomainException;
use Spatie\Permission\Models\Role;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class GatedCashCountAdjustmentWriterTest extends TestCase
{
    private const UUID = '11111111-1111-4111-8111-111111111111';
    private const OTHER = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing') || ! (
            (DB::getDriverName() === 'sqlite' && DB::getDatabaseName() === ':memory:')
            || (DB::getDriverName() === 'mysql' && DB::getDatabaseName() === 'water_test')
        )) {
            $this->markTestSkipped('Requires disposable isolated ledger evidence database.');
        }
        $this->travelTo(\Carbon\Carbon::instance($this->nowAt()));
        RefreshDatabaseState::$migrated = false;
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        DB::table('financial_scopes')->insert([
            'scope_key' => 'water', 'currency' => 'YER', 'timezone' => 'Asia/Aden',
            'mode' => 'ACTIVE', 'calendar_version' => 1, 'fence_epoch' => 5,
        ]);
        DB::table('financial_periods')->insert([
            'scope_key' => 'water', 'month' => '2026-11', 'start_on' => '2026-11-01',
            'next_on' => '2026-12-01', 'state' => 'OPEN', 'version' => 2,
        ]);
    }

    private function nowAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-25T08:30:00.123456Z');
    }

    private function gate(callable $writer): mixed
    {
        return app(FinancialWriteGate::class)->withinOpenPeriod('water', '2026-11-25', $this->nowAt(), $writer);
    }

    private function actor(): User
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));

        return $actor;
    }

    private const ADJUST = '33333333-3333-4333-8333-333333333333';
    private const NEXT = '44444444-4444-4444-8444-444444444444';
    private const REASON = 'Independent investigation confirmed the cash variance';
    private const EVIDENCE = 'Independent documented physical cash investigation';

    private function fixture(string $counted = '99.75', bool $registered = true,
        ?bool $accepted = true, bool $funded = false, bool $registeredFunding = true): array
    {
        $counter = $this->actor();
        $reviewer = $this->actor();
        $data = ['code' => 'QA-COUNT', 'name' => 'Synthetic counted treasury', 'channel' => 'cash',
            'opening_on' => '2026-11-25', 'opening_amount' => '100.00',
            'opening_reference' => 'QA-COUNT-OPEN', 'opening_evidence' => 'Independent opening cash count'];
        $writer = app(GatedTreasuryOpeningFundingWriter::class);
        $account = $registered ? $writer->open($counter, $data, self::UUID, $this->nowAt())['account']
            : app(TreasuryRegisterService::class)->open($counter, $data);
        if ($funded) {
            $collector = $this->actor();
            if ($registeredFunding) {
                $writer->fund($collector, $counter, (int) $account['id'], '5.00',
                    'QA-COUNT-FUND', 'Independent physical funding proof', self::OTHER, $this->nowAt());
            } else {
                app(CashSessionService::class)->openFunded($collector, $counter, (int) $account['id'],
                    '5.00', 'QA-COUNT-FUND', 'Independent physical funding proof');
            }
        }
        $counts = app(TreasuryCashCountService::class);
        $report = $counts->record($counter, (int) $account['id'], $counted, 'QA-COUNT-01', self::EVIDENCE);
        if ($accepted !== null) {
            $report = $counts->review($reviewer, (int) $report['count']['id'], $accepted,
                'Independent review of the count and physical evidence');
        }
        return [$counter, $reviewer, $account, $report['count']];
    }

    private function postAdjustment(User $actor, array $count, string $classification = 'confirmed_cash_loss',
        string $uuid = self::ADJUST, string $reason = self::REASON): array
    {
        return app(GatedCashCountAdjustmentWriter::class)->adjust($actor, (int) $count['id'],
            'QA-ADJUST-01', $classification, $reason, self::EVIDENCE, $uuid, $this->nowAt());
    }

    private function rejects(string $reason, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected rejection.');
        } catch (DomainException $error) {
            $this->assertSame($reason, $error->getMessage());
        }
    }

    public function test_confirmed_shortage_posts_exact_negative_variance_and_replays_once(): void
    {
        [, $reviewer, , $count] = $this->fixture();
        $original = (array) DB::table('treasury_cash_counts')->find($count['id']);
        $review = (array) DB::table('treasury_cash_count_reviews')->where('treasury_cash_count_id', $count['id'])->sole();
        $result = $this->postAdjustment($reviewer, $count);
        $this->assertTrue($result['registry_created']);
        $this->assertSame(-25, (int) $result['posting']['amount_minor']);
        $this->assertSame('confirmed_cash_loss', $result['adjustment']['classification']);
        $this->assertSame(9975, $result['observation']['current_recorded_minor']);
        $this->assertSame('posted', $result['observation']['adjustment_status']);
        $this->assertFalse($result['observation']['certified_balance']);
        $this->assertFalse($result['observation']['period_closed']);
        $this->assertSame($original, (array) DB::table('treasury_cash_counts')->find($count['id']));
        $this->assertSame($review, (array) DB::table('treasury_cash_count_reviews')->find($review['id']));
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => self::ADJUST,
            'source_type' => 'treasury_posting', 'source_id' => (string) $result['posting']['id'], 'amount_minor' => -25]);
        $again = $this->postAdjustment($reviewer, $count);
        $this->assertFalse($again['registry_created']);
        $this->assertSame($result['posting']['id'], $again['posting']['id']);
        $this->assertDatabaseCount('treasury_cash_adjustments', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('financial_posting_events', 2);
        $this->assertSame(1, AuditEvent::query()->where('event', 'treasury.cash_count_adjusted')->count());
        $this->gate(function (array $context): void {
            $verified = app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']);
            $this->assertSame(1, $verified['registered_treasury_legs_verified']);
            $this->assertSame(1, $verified['registered_opening_legs_verified']);
            $this->assertFalse($verified['source_coverage_verified']);
        });
    }

    public function test_surplus_is_held_unattributed_after_registered_funding(): void
    {
        [, $reviewer, , $count] = $this->fixture('95.25', funded: true);
        $this->rejects('gated_cash_count_classification', fn () => $this->postAdjustment($reviewer, $count));
        $result = $this->postAdjustment($reviewer, $count, 'unattributed_cash_held');
        $this->assertTrue($result['registry_created']);
        $this->assertSame(25, (int) $result['posting']['amount_minor']);
        $this->assertSame('unattributed_cash_held', $result['adjustment']['classification']);
        $this->assertSame(9525, $result['observation']['current_recorded_minor']);
        $this->assertSame(-475, (int) DB::table('treasury_postings')->sum('amount_minor'));
        $this->assertDatabaseCount('treasury_postings', 2);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('financial_posting_events', 3);
    }

    public function test_zero_variance_and_self_approval_are_rejected(): void
    {
        [$counter, $reviewer, , $count] = $this->fixture('100.00');
        try {
            $this->postAdjustment($counter, $count);
            $this->fail('Self approval accepted.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->rejects('gated_cash_count_classification', fn () => $this->postAdjustment($reviewer, $count));
        $this->rejects('gated_cash_count_classification', fn () => $this->postAdjustment($reviewer, $count, 'unattributed_cash_held'));
        $this->assertDatabaseCount('treasury_cash_adjustments', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
    }

    public function test_pending_and_rejected_observations_do_not_post(): void
    {
        [, $reviewer, , $count] = $this->fixture(accepted: null);
        $this->rejects('gated_cash_count_review_required', fn () => $this->postAdjustment($reviewer, $count));
        app(TreasuryCashCountService::class)->review($reviewer, (int) $count['id'], false,
            'Independent review rejected the observation');
        $this->rejects('gated_cash_count_review_required', fn () => $this->postAdjustment($reviewer, $count));
        $this->assertDatabaseCount('treasury_cash_adjustments', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
    }

    public function test_unregistered_opening_is_not_adopted(): void
    {
        [, $reviewer, , $count] = $this->fixture(registered: false);
        $this->rejects('gated_cash_count_prerequisite_unregistered', fn () => $this->postAdjustment($reviewer, $count));
        $this->assertDatabaseCount('treasury_cash_adjustments', 0);
        $this->assertDatabaseCount('financial_posting_events', 0);
    }

    public function test_unregistered_preceding_funding_cannot_be_treated_as_a_loss(): void
    {
        [, $reviewer, , $count] = $this->fixture('94.75', funded: true, registeredFunding: false);
        $this->rejects('gated_cash_count_prior_unregistered', fn () => $this->postAdjustment($reviewer, $count));
        $this->assertDatabaseCount('treasury_cash_adjustments', 0);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertDatabaseCount('financial_posting_events', 1);
    }

    public function test_changed_snapshot_after_review_blocks_adjustment(): void
    {
        [$counter, $reviewer, $account, $count] = $this->fixture();
        app(GatedTreasuryOpeningFundingWriter::class)->fund($this->actor(), $counter, (int) $account['id'],
            '5.00', 'QA-COUNT-LATER', 'Independent physical funding after count', self::OTHER, $this->nowAt());
        $this->rejects('gated_cash_count_snapshot_mismatch', fn () => $this->postAdjustment($reviewer, $count));
        $this->assertDatabaseCount('treasury_cash_adjustments', 0);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertDatabaseCount('financial_posting_events', 2);
    }

    public function test_closed_frozen_and_tampered_observation_are_rejected(): void
    {
        [, $reviewer, , $count] = $this->fixture();
        $post = fn () => $this->postAdjustment($reviewer, $count);
        DB::table('financial_periods')->update(['state' => 'CLOSED']);
        $this->rejects('financial_period_not_open', $post);
        DB::table('financial_periods')->update(['state' => 'OPEN']);
        DB::table('financial_scopes')->update(['active_freeze_uuid' => self::ADJUST]);
        $this->rejects('financial_scope_not_active', $post);
        DB::table('financial_scopes')->update(['active_freeze_uuid' => null]);
        DB::table('treasury_cash_counts')->where('id', $count['id'])->update(['evidence' => 'Changed physical count evidence']);
        $this->rejects('gated_cash_count_audit_mismatch', $post);
        $this->assertDatabaseCount('treasury_cash_adjustments', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
    }

    public function test_legacy_adjustment_and_conflicting_replay_are_not_adopted(): void
    {
        [, $reviewer, , $count] = $this->fixture();
        app(TreasuryCashCountService::class)->adjust($reviewer, (int) $count['id'], 'QA-ADJUST-01',
            'confirmed_cash_loss', self::REASON, self::EVIDENCE);
        $this->rejects('gated_cash_count_operation_conflict', fn () => $this->postAdjustment($reviewer, $count));
        $this->assertDatabaseCount('treasury_cash_adjustments', 1);
        $this->assertDatabaseCount('financial_posting_events', 1);
    }

    public function test_registry_failure_rolls_back_adjustment_and_audit_then_retries(): void
    {
        [, $reviewer, , $count] = $this->fixture();
        $audit = AuditEvent::query()->count();
        $before = app(TreasuryCashCountService::class)->inspect($reviewer, (int) $count['id']);
        $trigger = DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER qa_registry_failure BEFORE INSERT ON financial_posting_events BEGIN SELECT RAISE(ABORT, 'synthetic_registry_failure'); END"
            : "CREATE TRIGGER qa_registry_failure BEFORE INSERT ON financial_posting_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic_registry_failure'";
        DB::unprepared($trigger);
        try {
            $this->postAdjustment($reviewer, $count);
            $this->fail('Registry insert should fail.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('synthetic_registry_failure', $error->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS qa_registry_failure');
        }
        $this->assertEquals($before, app(TreasuryCashCountService::class)->inspect($reviewer, (int) $count['id']));
        $this->assertSame($audit, AuditEvent::query()->count());
        $this->assertDatabaseCount('treasury_cash_adjustments', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertDatabaseCount('financial_posting_operations', 1);
        $this->assertDatabaseCount('financial_posting_events', 1);
        $this->assertTrue($this->postAdjustment($reviewer, $count)['registry_created']);
        $this->rejects('gated_cash_count_operation_conflict', fn () => $this->postAdjustment($reviewer, $count, uuid: self::NEXT));
        $this->assertDatabaseCount('treasury_cash_adjustments', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertDatabaseCount('financial_posting_events', 2);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 0);
    }
    private function held(): array
    {
        [$counter, $reviewer, $account, $count] = $this->fixture('100.25');
        $result = $this->postAdjustment($reviewer, $count, 'unattributed_cash_held');
        $subscriber = Subscriber::create(['name' => 'Synthetic identified owner',
            'square_id' => Square::create(['name' => 'Synthetic held cash square'])->id, 'status' => 'active']);
        return [$counter, $reviewer, $account, $count, $result['held_liability_id'], $subscriber];
    }

    private function assignHeld(User $actor, int $lot, Subscriber $subscriber, int $minor,
        string $uuid = self::NEXT, string $reference = 'QA-HELD-OWNER'): array
    {
        return app(\App\Services\TreasuryHeldCashLiabilityService::class)->assign($actor, $lot,
            (int) $subscriber->id, $minor, $reference, 'Independent proof of ownership of counted cash',
            $uuid, '2026-11-25', $this->nowAt());
    }

    public function test_held_cash_owner_assignment_conserves_cash_and_total_liability(): void
    {
        [, $reviewer, , $count, $lot, $subscriber] = $this->held();
        $actor = $this->actor();
        $this->assertSame($lot, $this->postAdjustment($reviewer, $count, 'unattributed_cash_held')['held_liability_id']);
        $result = $this->assignHeld($actor, $lot, $subscriber, 20);
        $this->assertSame(25, $result['held_minor']);
        $this->assertSame(20, $result['identified_minor']);
        $this->assertSame(5, $result['unidentified_minor']);
        $this->assertFalse($result['cash_movement_created']);
        $this->assertFalse($result['discharge_created']);
        $this->assertFalse($this->assignHeld($actor, $lot, $subscriber, 20)['created']);
        $this->assertDatabaseCount('treasury_held_cash_liabilities', 1);
        $this->assertDatabaseCount('treasury_held_cash_assignments', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame(0, (int) DB::table('financial_posting_events')
            ->where('operation_uuid', self::NEXT)->sum('amount_minor'));
        $coverage = $this->gate(fn () => app(\App\Services\TreasuryHeldCashLiabilityService::class)->verifyCoverage());
        $this->assertSame(25, $coverage['held_minor']);
        $this->assertFalse($coverage['closure_authorized']);
    }

    public function test_held_cash_rejects_excess_conflicting_replay_and_self_assignment(): void
    {
        [, $reviewer, , , $lot, $subscriber] = $this->held();
        $actor = $this->actor();
        $this->rejects('held_cash_independent_approval', fn () => $this->assignHeld($reviewer, $lot, $subscriber, 1));
        $this->assignHeld($actor, $lot, $subscriber, 25);
        $this->rejects('held_cash_assignment_conflict', fn () => $this->assignHeld($actor, $lot, $subscriber, 24));
        $this->rejects('held_cash_capacity_exceeded', fn () =>
            $this->assignHeld($actor, $lot, $subscriber, 1, self::OTHER, 'QA-HELD-EXCESS'));
        $this->assertDatabaseCount('treasury_held_cash_assignments', 1);
    }

    public function test_held_cash_coverage_detects_missing_assignment_and_modified_amount(): void
    {
        [, , , , $lot, $subscriber] = $this->held();
        $this->assignHeld($this->actor(), $lot, $subscriber, 20);
        $verify = fn () => $this->gate(fn () => app(\App\Services\TreasuryHeldCashLiabilityService::class)->verifyCoverage());
        // Deliberately simulate an administrator bypass of the source triggers
        // in this disposable fixture only; independent reconstruction must
        // still detect corruption even if storage protections are defeated.
        DB::unprepared('DROP TRIGGER held_assignment_reject_update');
        DB::unprepared('DROP TRIGGER held_assignment_reject_delete');
        try {
            DB::table('treasury_held_cash_assignments')->where('operation_uuid', self::NEXT)->update(['amount_minor' => 19]);
            $this->rejects('held_cash_registry_mismatch', $verify);
            DB::table('treasury_held_cash_assignments')->where('operation_uuid', self::NEXT)->delete();
            $this->rejects('held_cash_coverage_orphan_event', $verify);
        } finally {
            app(\App\Services\HeldCashDocumentProtection::class)->install();
        }
    }

    public function test_held_cash_recognition_failure_rolls_back_cash_and_registry(): void
    {
        [, $reviewer, , $count] = $this->fixture('100.25');
        $trigger = DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER qa_held_failure BEFORE INSERT ON treasury_held_cash_liabilities BEGIN SELECT RAISE(ABORT, 'synthetic_held_failure'); END"
            : "CREATE TRIGGER qa_held_failure BEFORE INSERT ON treasury_held_cash_liabilities FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic_held_failure'";
        DB::unprepared($trigger);
        try {
            $this->postAdjustment($reviewer, $count, 'unattributed_cash_held');
            $this->fail('Expected synthetic recognition failure.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('synthetic_held_failure', $error->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS qa_held_failure');
        }
        $this->assertDatabaseCount('treasury_cash_adjustments', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertDatabaseCount('treasury_held_cash_liabilities', 0);
        $this->assertDatabaseCount('financial_posting_operations', 1);
        $this->assertTrue($this->postAdjustment($reviewer, $count, 'unattributed_cash_held')['registry_created']);
    }

    public function test_held_cash_reservation_rejects_funding_that_would_consume_held_cash(): void
    {
        [, , $account, , , ] = $this->held();
        $issuer = $this->actor();
        $collector = $this->actor();
        $this->rejects('held_cash_reserved', fn () => app(CashSessionService::class)->openFunded(
            $collector, $issuer, (int) $account['id'], '100.01', 'QA-RESERVED-FUND',
            'Independent physical funding evidence'));
        $this->assertDatabaseCount('cash_session_fundings', 0);
        $this->assertDatabaseCount('treasury_postings', 1);
        app(CashSessionService::class)->openFunded($collector, $issuer, (int) $account['id'],
            '100.00', 'QA-FREE-FUND', 'Independent physical funding evidence');
        $this->assertDatabaseCount('cash_session_fundings', 1);
        $this->assertSame(-9975, (int) DB::table('treasury_postings')->sum('amount_minor'));
        $this->rejects('held_cash_reserved', fn () => app(CashSessionService::class)->openFunded(
            $this->actor(), $issuer, (int) $account['id'], '0.01', 'QA-RESERVED-FUND-2',
            'Independent physical funding evidence'));
    }

    public function test_assignment_does_not_release_cash_reservation(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $guard = app(\App\Services\HeldCashReservation::class);
        DB::transaction(fn () => $guard->assertOrdinaryOutflow((int) $account['id'], 10000, '2026-11-25'));
        $this->rejects('held_cash_reserved', fn () => DB::transaction(fn () =>
            $guard->assertOrdinaryOutflow((int) $account['id'], 10001, '2026-11-25')));
        $this->rejects('held_cash_outflow_backdated', fn () => DB::transaction(fn () =>
            $guard->assertOrdinaryOutflow((int) $account['id'], 1, '2026-11-24')));
    }

    public function test_held_cash_reservation_protects_transfer_dispatch_and_exact_replay(): void
    {
        [, , $account] = $this->held();
        $sender = $this->actor();
        $register = app(TreasuryRegisterService::class);
        $destination = $register->open($sender, ['code' => 'QA-HELD-BANK', 'name' => 'Synthetic destination bank',
            'channel' => 'bank', 'opening_on' => '2026-11-25', 'opening_amount' => '0.00',
            'opening_reference' => 'QA-HELD-BANK-OPEN', 'opening_evidence' => 'Independent opening statement']);
        $transfer = app(TreasuryTransferService::class);
        $data = ['from_account_id' => $account['id'], 'to_account_id' => $destination['id'],
            'amount' => '100.01', 'reference' => 'QA-HELD-TRANSFER', 'dispatched_on' => '2026-11-25',
            'evidence' => 'Independent actual cash dispatch evidence'];
        $this->rejects('held_cash_reserved', fn () => $transfer->dispatch($sender, $data));
        $this->assertDatabaseCount('treasury_transfers', 0);
        $this->assertDatabaseCount('treasury_postings', 1);
        $data['amount'] = '100.00';
        $sent = $transfer->dispatch($sender, $data);
        $this->assertSame('in_transit', $sent['status']);
        $this->assertSame($sent['id'], $transfer->dispatch($sender, $data)['id']);
        $this->assertDatabaseCount('treasury_transfers', 1);
        $this->assertDatabaseCount('treasury_postings', 2);
        $this->assertSame(25, $register->inspect($sender, (int) $account['id'])['provisional_recorded_balance_minor']);
    }

    public function test_refund_posting_reservation_does_not_erase_already_recorded_refund_source(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $actor = $this->actor();
        $payment = app(PaymentService::class)->collectPayment($subscriber, '200.00');
        $refund = app(\App\Services\PaymentRefundService::class)->record($payment, $actor, '100.01',
            'QA-HELD-REFUND', 'treasury_cash', '2026-11-25',
            'Synthetic refund of excess subscriber credit', 'Independent evidence of recorded actual cash disbursement');
        $register = app(TreasuryRegisterService::class);
        $this->rejects('held_cash_reserved', fn () => $register->post($actor, (int) $account['id'],
            'payment_refund', (int) $refund->id));
        $this->assertDatabaseCount('payment_refunds', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
        $report = $register->inspect($actor, (int) $account['id']);
        $this->assertSame(1, $report['channel_unposted_refund_count']);
        $this->assertFalse($report['certified_balance']);
        $this->assertSame(10025, $report['provisional_recorded_balance_minor']);
        // This is a visible reconciliation incident, not proof that the
        // original physical disbursement was prevented by the posting guard.
    }

    private function authorizeHeld(User $authorizer, User $executor, int $minor, string $uuid = self::OTHER): array
    {
        return app(\App\Services\TreasuryHeldCashLiabilityService::class)->authorizePayout($authorizer, $executor,
            self::NEXT, $minor, 'QA-HELD-AUTH-'.$uuid,
            'Independent prior approval of the identified owner claim and designated cash executor',
            $uuid, '2026-11-25', $this->nowAt());
    }

    private function payoutHeld(User $actor, int $minor, string $uuid = self::OTHER, bool $authorize = true): array
    {
        if ($authorize && ! DB::table('treasury_held_cash_payout_authorizations')->where('operation_uuid', $uuid)->exists()) {
            $this->authorizeHeld($this->actor(), $actor, $minor, $uuid);
        }
        return app(\App\Services\TreasuryHeldCashLiabilityService::class)->payout($actor, self::NEXT, $minor,
            'QA-HELD-PAYOUT-'.$uuid, 'Independent signed acknowledgement of returned cash by identified owner',
            $uuid, '2026-11-25', $this->nowAt());
    }

    public function test_held_payout_requires_prior_independent_authorization_and_blocks_competing_credit(): void
    {
        [, , , , $lot, $subscriber] = $this->held();
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $executor = $this->actor();
        $authorizer = $this->actor();
        $this->rejects('held_cash_payout_authorization_required', fn () => $this->payoutHeld($executor, 20, authorize: false));
        $this->rejects('held_cash_authorization_independence', fn () => $this->authorizeHeld($executor, $executor, 20));
        $auth = $this->authorizeHeld($authorizer, $executor, 20);
        $this->assertTrue($auth['created']);
        $this->assertSame('authorized', $auth['state']);
        $this->assertFalse($this->authorizeHeld($authorizer, $executor, 20)['created']);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertDatabaseMissing('financial_posting_operations', ['operation_uuid' => self::OTHER]);
        $inspect = $this->gate(fn () => app(\App\Services\TreasuryHeldCashLiabilityService::class)->inspect($lot));
        $this->assertSame(20, $inspect['pending_authorized_minor']);
        $this->assertSame(25, $inspect['outstanding_minor']);
        $this->rejects('held_cash_credit_capacity', fn () => $this->creditHeld($authorizer, 6, '55555555-5555-4555-8555-555555555555'));
        $this->rejects('held_cash_payout_authorization_mismatch', fn () => $this->payoutHeld($executor, 19, authorize: false));
        $this->rejects('held_cash_payout_authorization_mismatch', fn () => $this->payoutHeld($authorizer, 20, authorize: false));
        $result = $this->payoutHeld($executor, 20, authorize: false);
        $this->assertSame(5, $result['outstanding_minor']);
        $this->assertSame(0, $result['pending_authorized_minor']);
        $this->assertSame('paid', $this->authorizeHeld($authorizer, $executor, 20)['state']);
    }

    public function test_held_payout_authorization_can_be_cancelled_without_discharge_or_reuse(): void
    {
        [, , , , $lot, $subscriber] = $this->held();
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $executor = $this->actor();
        $authorizer = $this->actor();
        $this->authorizeHeld($authorizer, $executor, 25);
        $service = app(\App\Services\TreasuryHeldCashLiabilityService::class);
        $evidence = 'Independent reconciliation confirms that no cash was physically handed over';
        $cancel = fn (User $actor) => $service->cancelPayoutAuthorization($actor, self::OTHER, $evidence, '2026-11-25', $this->nowAt());
        $this->rejects('held_cash_cancel_independence', fn () => $cancel($executor));
        $this->assertTrue($cancel($authorizer)['created']);
        $this->assertFalse($cancel($authorizer)['created']);
        $this->assertSame('cancelled', $this->authorizeHeld($authorizer, $executor, 25)['state']);
        $this->rejects('held_cash_payout_authorization_mismatch', fn () => $this->payoutHeld($executor, 25, authorize: false));
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertSame(25, $this->gate(fn () => $service->inspect($lot))['outstanding_minor']);
        $this->assertSame(0, $this->creditHeld($authorizer, 25, '55555555-5555-4555-8555-555555555555')['outstanding_minor']);
    }

    public function test_held_owner_payout_reduces_cash_and_liability_once_and_preserves_unidentified_cash(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        $assigner = $this->actor();
        $this->assignHeld($assigner, $lot, $subscriber, 20);
        $this->rejects('held_cash_payout_independence', fn () => $this->payoutHeld($assigner, 1));
        $payer = $this->actor();
        $result = $this->payoutHeld($payer, 15);
        $this->assertTrue($result['created']);
        $this->assertTrue($result['discharge_created']);
        $this->assertSame(15, $result['paid_minor']);
        $this->assertSame(10, $result['outstanding_minor']);
        $this->assertSame(5, $result['identified_minor']);
        $this->assertSame(5, $result['unidentified_minor']);
        $this->assertFalse($this->payoutHeld($payer, 15)['created']);
        $this->rejects('held_cash_payout_capacity', fn () =>
            $this->payoutHeld($payer, 6, '55555555-5555-4555-8555-555555555555'));
        $report = app(TreasuryRegisterService::class)->inspect($payer, (int) $account['id']);
        $this->assertSame(10010, $report['provisional_recorded_balance_minor']);
        $this->assertSame(10, $report['held_cash_outstanding_minor']);
        $this->assertSame([], $report['issues']);
        $this->assertDatabaseCount('treasury_held_cash_payouts', 1);
        $this->assertDatabaseCount('treasury_postings', 2);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 0);
        $guard = app(\App\Services\HeldCashReservation::class);
        DB::transaction(fn () => $guard->assertOrdinaryOutflow((int) $account['id'], 10000, '2026-11-25'));
        $this->rejects('held_cash_reserved', fn () => DB::transaction(fn () =>
            $guard->assertOrdinaryOutflow((int) $account['id'], 10001, '2026-11-25')));
    }

    public function test_held_payout_posting_failure_rolls_back_all_effects_and_allows_retry(): void
    {
        [, , , , $lot, $subscriber] = $this->held();
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $payer = $this->actor();
        $trigger = DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER qa_payout_failure BEFORE INSERT ON treasury_postings BEGIN SELECT RAISE(ABORT, 'synthetic_payout_failure'); END"
            : "CREATE TRIGGER qa_payout_failure BEFORE INSERT ON treasury_postings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic_payout_failure'";
        DB::unprepared($trigger);
        try {
            $this->payoutHeld($payer, 25);
            $this->fail('Expected payout posting failure.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('synthetic_payout_failure', $error->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS qa_payout_failure');
        }
        $this->assertDatabaseCount('treasury_held_cash_payouts', 0);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertDatabaseMissing('financial_posting_operations', ['operation_uuid' => self::OTHER]);
        $result = $this->payoutHeld($payer, 25);
        $this->assertSame(0, $result['outstanding_minor']);
        $this->assertSame(25, $result['paid_minor']);
    }

    public function test_missing_payout_audit_blocks_release_and_coverage(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->payoutHeld($this->actor(), 25);
        DB::table('audit_events')->where('event', 'treasury.held_cash_paid')->delete();
        $guard = app(\App\Services\HeldCashReservation::class);
        $this->rejects('held_cash_payout_audit', fn () => DB::transaction(fn () =>
            $guard->assertOrdinaryOutflow((int) $account['id'], 1, '2026-11-25')));
        $this->rejects('held_cash_payout_audit', fn () => $this->gate(fn () =>
            app(\App\Services\TreasuryHeldCashLiabilityService::class)->verifyCoverage()));
    }

    private function creditHeld(User $actor, int $minor, string $uuid = self::OTHER): array
    {
        return app(\App\Services\TreasuryHeldCashLiabilityService::class)->credit($actor, self::NEXT, $minor,
            'QA-HELD-CREDIT-'.$uuid, 'Independent documented owner consent to apply held cash against subscriber account',
            $uuid, '2026-11-25', $this->nowAt());
    }

    public function test_held_credit_settles_invoice_and_preserves_cash_and_original_allocation(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        \App\Models\Tariff::create(['name' => 'Synthetic credit tariff', 'unit_price' => '1.00', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-HELD-CREDIT']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $invoice = app(\App\Services\InvoiceService::class)->generateInvoice($subscriber, $meter);
        app(PaymentService::class)->collectPayment($subscriber, '0.10', $invoice->id);
        $prior = DB::table('payment_allocation_revisions')->where('subscriber_id', $subscriber->id)->orderByDesc('revision')->first();
        $oldRows = DB::table('payment_allocations')->where('revision_id', $prior->id)->get()->toArray();
        $assigner = $this->actor();
        $this->assignHeld($assigner, $lot, $subscriber, 25);
        $this->rejects('held_cash_credit_independence', fn () => $this->creditHeld($assigner, 1));
        $actor = $this->actor();
        $result = $this->creditHeld($actor, 20);
        $this->assertTrue($result['subscriber_credit_created']);
        $this->assertFalse($result['cash_movement_created']);
        $this->assertSame(20, $result['credited_minor']);
        $this->assertSame(5, $result['outstanding_minor']);
        $this->assertSame('0.30', $invoice->fresh()->amount_paid);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertSame(10025, app(TreasuryRegisterService::class)->inspect($actor, (int) $account['id'])['provisional_recorded_balance_minor']);
        $this->assertSame(5, app(TreasuryRegisterService::class)->inspect($actor, (int) $account['id'])['held_cash_outstanding_minor']);
        $this->assertFalse($this->creditHeld($actor, 20)['created']);
        $this->assertEquals($oldRows, DB::table('payment_allocations')->where('revision_id', $prior->id)->get()->toArray());
        $this->assertTrue(app(\App\Services\AllocationSnapshotIntegrity::class)->valid($prior));
        $this->assertTrue(app(\App\Services\PaymentAllocationReadService::class)->reconcile($subscriber)['matches']);
        $projection = app(\App\Services\PaymentAllocationReadService::class)->forInvoice($invoice);
        $this->assertSame(30, $projection['allocated_minor']);
        $creditRow = collect($projection['rows'])->first(fn ($row) => $row['held_cash_credit_id'] !== null);
        $this->assertNotNull($creditRow);
        $this->assertNull($creditRow['payment_id']);
        $this->assertSame(20, $creditRow['amount_minor']);
        $proof = $this->gate(fn () => app(\App\Services\HeldCashCreditAllocationEvidence::class)->inspectAccount((int) $account['id']));
        $this->assertSame(20, $proof['applied_minor']);
        $this->assertSame(0, $proof['unapplied_minor']);
        $this->assertSame(20, $proof['protected_minor']);
        $this->assertFalse($proof['spending_release_authorized']);
        $report = app(\App\Services\FinancialReportingService::class)->movementsFromMinor('2026-11-25', '2026-11-25', null, 10);
        $this->assertEquals(0.2, $report['held_cash_credits']);
        $this->assertEquals(0.1, $report['gross_valid_receipts']);
        $this->rejects('held_cash_payout_capacity', fn () =>
            $this->payoutHeld($actor, 6, '55555555-5555-4555-8555-555555555555'));
        $result = $this->payoutHeld($actor, 5, '55555555-5555-4555-8555-555555555555');
        $this->assertSame(0, $result['outstanding_minor']);
        $this->assertSame(5, $result['paid_minor']);
        $this->gate(function (array $context): void {
            $result = app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']);
            $this->assertSame(5, $result['registered_held_legs_verified']);
            $this->assertFalse($result['source_coverage_verified']);
        });
    }

    public function test_held_credit_allocation_failure_rolls_back_document_ledger_registry_and_reservation(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $actor = $this->actor();
        $trigger = DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER qa_held_credit_failure BEFORE INSERT ON payment_allocations BEGIN SELECT RAISE(ABORT, 'synthetic_credit_allocation_failure'); END"
            : "CREATE TRIGGER qa_held_credit_failure BEFORE INSERT ON payment_allocations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic_credit_allocation_failure'";
        DB::unprepared($trigger);
        try {
            $this->creditHeld($actor, 25);
            $this->fail('Expected allocation failure.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('synthetic_credit_allocation_failure', $error->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS qa_held_credit_failure');
        }
        $this->assertDatabaseCount('treasury_held_cash_credits', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('payment_allocation_revisions', 0);
        $this->assertDatabaseMissing('financial_posting_operations', ['operation_uuid' => self::OTHER]);
        $this->assertSame(25, app(TreasuryRegisterService::class)->inspect($actor, (int) $account['id'])['held_cash_outstanding_minor']);
        $this->assertSame(0, $this->creditHeld($actor, 25)['outstanding_minor']);
        $this->assertSame(25, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
    }

    public function test_held_credit_missing_audit_prevents_release_and_new_settlement(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $actor = $this->actor();
        $this->creditHeld($actor, 25);
        DB::table('audit_events')->where('event', 'treasury.held_cash_credited')->delete();
        $this->rejects('held_cash_credit_audit', fn () =>
            DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->assertOrdinaryOutflow((int) $account['id'], 1, '2026-11-25')));
        $this->rejects('held_cash_credit_audit', fn () =>
            $this->gate(fn () => app(\App\Services\TreasuryHeldCashLiabilityService::class)->verifyCoverage()));
    }

    public function test_unapplied_subscriber_credit_remains_protected_even_after_held_liability_is_zero(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $actor = $this->actor();
        $this->creditHeld($actor, 25);
        $register = app(TreasuryRegisterService::class);
        $report = $register->inspect($actor, (int) $account['id']);
        $this->assertSame(0, $report['held_cash_outstanding_minor']);
        $this->assertSame(25, $report['held_cash_subscriber_credit_reserve_minor']);
        $this->assertSame(25, $report['held_cash_total_protected_minor']);
        $this->assertFalse($report['held_cash_credit_release_verified']);
        $proof = $this->gate(fn () => app(\App\Services\HeldCashCreditAllocationEvidence::class)->inspectAccount((int) $account['id']));
        $this->assertSame(0, $proof['applied_minor']);
        $this->assertSame(25, $proof['unapplied_minor']);
        $this->assertSame(25, $proof['protected_minor']);
        $this->assertFalse($proof['spending_release_authorized']);
        $guard = app(\App\Services\HeldCashReservation::class);
        DB::transaction(fn () => $guard->assertOrdinaryOutflow((int) $account['id'], 10000, '2026-11-25'));
        $this->rejects('held_cash_reserved', fn () => DB::transaction(fn () =>
            $guard->assertOrdinaryOutflow((int) $account['id'], 10001, '2026-11-25')));
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertSame(25, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
    }

    public function test_missing_preapproval_audit_blocks_credit_and_cash_execution(): void
    {
        [, , , , $lot, $subscriber] = $this->held();
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $executor = $this->actor();
        $this->authorizeHeld($this->actor(), $executor, 25);
        DB::table('audit_events')->where('event', 'treasury.held_cash_payout_authorized')->delete();
        $this->rejects('held_cash_authorization_audit', fn () => $this->payoutHeld($executor, 25, authorize: false));
        $this->rejects('held_cash_authorization_audit', fn () =>
            $this->creditHeld($executor, 1, '55555555-5555-4555-8555-555555555555'));
        $this->assertDatabaseCount('treasury_held_cash_payouts', 0);
        $this->assertDatabaseCount('treasury_held_cash_credits', 0);
        $this->assertDatabaseCount('treasury_postings', 1);
    }

    public function test_held_source_documents_are_append_only_even_through_raw_database_writes(): void
    {
        [, , , , $lot, $subscriber] = $this->held();
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $executor = $this->actor();
        $authorizer = $this->actor();
        $cancelUuid = '77777777-7777-4777-8777-777777777777';
        $this->authorizeHeld($authorizer, $executor, 5, $cancelUuid);
        app(\App\Services\TreasuryHeldCashLiabilityService::class)->cancelPayoutAuthorization($authorizer, $cancelUuid,
            'Independent proof that this cash authorization was not executed', '2026-11-25', $this->nowAt());
        $this->payoutHeld($executor, 5);
        $this->creditHeld($authorizer, 20, '55555555-5555-4555-8555-555555555555');
        $protection = app(\App\Services\HeldCashDocumentProtection::class);
        $this->assertTrue($protection->active());

        foreach ([
            'treasury_held_cash_liabilities' => 'id',
            'treasury_held_cash_assignments' => 'operation_uuid',
            'treasury_held_cash_payout_authorizations' => 'operation_uuid',
            'treasury_held_cash_payout_cancellations' => 'operation_uuid',
            'treasury_held_cash_payouts' => 'operation_uuid',
            'treasury_held_cash_credits' => 'id',
        ] as $table => $key) {
            $before = DB::table($table)->orderBy($key)->get()->map(fn ($row) => (array) $row)->all();
            $this->assertNotEmpty($before);
            $row = $before[0];
            $attempts = [
                fn () => DB::table($table)->where($key, $row[$key])->update([$key => $row[$key]]),
                fn () => DB::table($table)->where($key, $row[$key])->delete(),
            ];
            if (DB::getDriverName() === 'sqlite') {
                $columns = implode(', ', array_keys($row));
                $placeholders = implode(', ', array_fill(0, count($row), '?'));
                $attempts[] = fn () => DB::statement("INSERT OR REPLACE INTO {$table} ({$columns}) VALUES ({$placeholders})", array_values($row));
            }
            foreach ($attempts as $attempt) {
                try {
                    $attempt();
                    $this->fail("Direct mutation succeeded for {$table}");
                } catch (\Illuminate\Database\QueryException $error) {
                    $this->assertStringContainsString('Held cash document is immutable', $error->getMessage());
                }
            }
            $this->assertSame($before, DB::table($table)->orderBy($key)->get()->map(fn ($item) => (array) $item)->all());
        }
        $this->assertSame(0, $this->gate(fn () => app(\App\Services\TreasuryHeldCashLiabilityService::class)->inspect($lot))['outstanding_minor']);
    }

    public function test_cancelling_a_paid_invoice_restores_credit_without_releasing_or_duplicating_cash(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        \App\Models\Tariff::create(['name' => 'Synthetic cancellation tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-HELD-CANCEL']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $invoice = app(\App\Services\InvoiceService::class)->generateInvoice($subscriber, $meter);
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $actor = $this->actor();
        $this->creditHeld($actor, 25);
        $inspect = fn () => $this->gate(fn () => app(\App\Services\HeldCashCreditAllocationEvidence::class)->inspectAccount((int) $account['id']));
        $before = $inspect();
        $this->assertSame(10, $before['applied_minor']);
        $this->assertSame(15, $before['unapplied_minor']);
        $this->assertSame(25, $before['protected_minor']);
        $this->assertSame('paid', $invoice->fresh()->status);
        $cash = DB::table('treasury_postings')->orderBy('id')->get()->toArray();
        $oldRevision = DB::table('payment_allocation_revisions')->where('id', $before['credits'][0]['revision_id'])->sole();

        $this->assertIssuancePrecedesSubscriber(fn () => app(\App\Services\InvoiceCancellationService::class)->cancel($invoice, 'Synthetic independently documented invoice cancellation', (int) $actor->id));
        $after = $inspect();
        $this->assertSame(0, $after['applied_minor']);
        $this->assertSame(25, $after['unapplied_minor']);
        $this->assertSame(25, $after['protected_minor']);
        $this->assertFalse($after['spending_release_authorized']);
        $this->assertNotSame($before['credits'][0]['revision_id'], $after['credits'][0]['revision_id']);
        $this->assertTrue(app(\App\Services\AllocationSnapshotIntegrity::class)->valid($oldRevision));
        $this->assertEquals($cash, DB::table('treasury_postings')->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('treasury_held_cash_credits', 1);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(25, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        $this->rejects('held_cash_reserved', fn () => DB::transaction(fn () =>
            app(\App\Services\HeldCashReservation::class)->assertOrdinaryOutflow((int) $account['id'], 10001, '2026-11-25')));
    }

    public function test_held_settlement_reference_cannot_be_reused_across_document_families(): void
    {
        [, , , , $lot, $subscriber] = $this->held();
        $this->assignHeld($this->actor(), $lot, $subscriber, 20, self::NEXT, 'QA-HELD-SHARED');
        $authorizer = $this->actor();
        $executor = $this->actor();
        $service = app(\App\Services\TreasuryHeldCashLiabilityService::class);
        $this->rejects('held_cash_authorization_conflict', fn () => $service->authorizePayout($authorizer, $executor,
            self::NEXT, 5, 'QA-HELD-SHARED', 'Independent documented authorization of held claim',
            self::OTHER, '2026-11-25', $this->nowAt()));
        $this->authorizeHeld($authorizer, $executor, 5);
        $this->rejects('held_cash_credit_conflict', fn () => $service->credit($authorizer, self::NEXT, 1,
            'QA-HELD-AUTH-'.self::OTHER, 'Independent documented owner consent for credit settlement',
            '55555555-5555-4555-8555-555555555555', '2026-11-25', $this->nowAt()));
        $this->rejects('held_cash_payout_conflict', fn () => $service->payout($executor, self::NEXT, 5,
            'QA-HELD-SHARED', 'Independent acknowledgement of returned cash to owner',
            self::OTHER, '2026-11-25', $this->nowAt()));
        $this->assertDatabaseCount('treasury_held_cash_payouts', 0);
        $this->assertDatabaseCount('treasury_held_cash_credits', 0);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertSame(20, $this->gate(fn () => $service->inspect($lot))['identified_minor']);
    }
    public function test_unposted_cash_refunds_reduce_held_cash_spending_capacity_without_double_counting(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        $actor = $this->actor();
        $receipt = app(PaymentService::class)->collectPayment($subscriber, '200.00');
        $refund = app(\App\Services\PaymentRefundService::class)->record($receipt, $actor, '40.00',
            'QA-UNPOSTED-REFUND', 'treasury_cash', '2026-11-25',
            'Synthetic refund of excess subscriber credit', 'Independent evidence of actual cash disbursement');
        $guard = app(\App\Services\HeldCashReservation::class);
        $register = app(TreasuryRegisterService::class);
        $report = $register->inspect($actor, (int) $account['id']);
        $this->assertSame(4000, $report['channel_unposted_cash_refund_minor']);
        $this->assertSame(0, $report['held_cash_possible_shortfall_minor']);
        DB::transaction(fn () => $guard->assertOrdinaryOutflow((int) $account['id'], 6000, '2026-11-25'));
        $this->rejects('held_cash_reserved', fn () => DB::transaction(fn () =>
            $guard->assertOrdinaryOutflow((int) $account['id'], 6001, '2026-11-25')));
        $posted = $register->post($actor, (int) $account['id'], 'payment_refund', (int) $refund->id);
        $this->assertSame((int) $posted['id'], (int) $register->post($actor, (int) $account['id'], 'payment_refund', (int) $refund->id)['id']);
        $after = $register->inspect($actor, (int) $account['id']);
        $this->assertSame(0, $after['channel_unposted_cash_refund_minor']);
        $this->assertSame(6025, $after['provisional_recorded_balance_minor']);
        $this->assertSame(25, $after['held_cash_total_protected_minor']);
        DB::transaction(fn () => $guard->assertOrdinaryOutflow((int) $account['id'], 6000, '2026-11-25'));
        $this->rejects('held_cash_reserved', fn () => DB::transaction(fn () =>
            $guard->assertOrdinaryOutflow((int) $account['id'], 6001, '2026-11-25')));
        $this->assertDatabaseCount('treasury_postings', 2);
    }

    public function test_unposted_cash_refund_shortfall_blocks_held_owner_payout_authorization(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $actor = $this->actor();
        $receipt = app(PaymentService::class)->collectPayment($subscriber, '200.00');
        app(\App\Services\PaymentRefundService::class)->record($receipt, $actor, '100.01',
            'QA-UNPOSTED-SHORTFALL', 'treasury_cash', '2026-11-25',
            'Synthetic refund of excess subscriber credit', 'Independent evidence of actual cash disbursement');
        $report = app(TreasuryRegisterService::class)->inspect($actor, (int) $account['id']);
        $this->assertSame(10001, $report['channel_unposted_cash_refund_minor']);
        $this->assertSame(1, $report['held_cash_possible_shortfall_minor']);
        $this->assertContains('held_cash_possible_liquidity_shortfall', array_column($report['issues'], 'code'));
        $executor = $this->actor();
        $this->rejects('held_cash_payout_insufficient_cash', fn () => $this->authorizeHeld($actor, $executor, 1));
        $this->assertDatabaseCount('treasury_held_cash_payout_authorizations', 0);
        $this->assertDatabaseCount('payment_refunds', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
    }
    public function test_unmatched_cash_refund_posting_cannot_inflate_available_cash(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        $actor = $this->actor();
        $receipt = app(PaymentService::class)->collectPayment($subscriber, '200.00');
        $refund = app(\App\Services\PaymentRefundService::class)->record($receipt, $actor, '40.00',
            'QA-CORRUPT-REFUND', 'treasury_cash', '2026-11-25',
            'Synthetic refund of excess subscriber credit', 'Independent evidence of actual cash disbursement');
        $posting = app(TreasuryRegisterService::class)->post($actor, (int) $account['id'], 'payment_refund', (int) $refund->id);
        DB::table('treasury_postings')->where('id', $posting['id'])->update(['amount_minor' => 4000]);
        $guard = app(\App\Services\HeldCashReservation::class);
        $this->rejects('custody_evidence_source_mismatch', fn () => DB::transaction(fn () =>
            $guard->assertOrdinaryOutflow((int) $account['id'], 1, '2026-11-25')));
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->rejects('custody_evidence_source_mismatch', fn () => $this->authorizeHeld($actor, $this->actor(), 1));
        $this->assertDatabaseCount('treasury_held_cash_payout_authorizations', 0);
        $this->assertDatabaseCount('payment_refunds', 1);
        $this->assertDatabaseCount('treasury_postings', 2);
    }

    public function test_missing_opening_audit_blocks_new_outflows_and_owner_payout(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $actor = $this->actor();
        AuditEvent::query()->where('event', 'treasury.opened')->where('entity_id', (string) $account['id'])->delete();
        $guard = app(\App\Services\HeldCashReservation::class);
        $this->rejects('held_cash_liquidity_opening_audit', fn () => DB::transaction(fn () =>
            $guard->assertOrdinaryOutflow((int) $account['id'], 1, '2026-11-25')));
        $this->rejects('held_cash_liquidity_opening_audit', fn () => $this->authorizeHeld($actor, $this->actor(), 1));
        $this->assertDatabaseCount('treasury_held_cash_payout_authorizations', 0);
        $this->assertDatabaseCount('treasury_postings', 1);
    }
    public function test_changed_held_classification_cannot_hide_the_reservation(): void
    {
        [, , $account, , $lot] = $this->held();
        $source = DB::table('treasury_held_cash_liabilities')->where('id', $lot)->sole();
        DB::table('treasury_cash_adjustments')->where('id', $source->treasury_cash_adjustment_id)
            ->update(['classification' => 'confirmed_cash_loss']);
        $guard = app(\App\Services\HeldCashReservation::class);
        $this->rejects('held_cash_reservation_source', fn () => DB::transaction(fn () =>
            $guard->assertOrdinaryOutflow((int) $account['id'], 1, '2026-11-25')));
        $this->rejects('held_cash_reservation_source', fn () => DB::transaction(fn () =>
            $guard->protectedForAccount((int) $account['id'])));
        $this->assertDatabaseCount('treasury_held_cash_liabilities', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
    }

    public function test_relocated_dispatch_posting_cannot_inflate_original_account_cash(): void
    {
        [, , $account] = $this->held();
        $actor = $this->actor();
        $destination = app(TreasuryRegisterService::class)->open($actor, [
            'code' => 'QA-MOVED-CASH', 'name' => 'Synthetic other cash account', 'channel' => 'cash',
            'opening_on' => '2026-11-25', 'opening_amount' => '0.00',
            'opening_reference' => 'QA-MOVED-OPEN', 'opening_evidence' => 'Independent opening cash count',
        ]);
        $dispatch = app(TreasuryTransferService::class)->dispatch($actor, [
            'from_account_id' => $account['id'], 'to_account_id' => $destination['id'],
            'amount' => '20.00', 'reference' => 'QA-MOVED-DISPATCH', 'dispatched_on' => '2026-11-25',
            'evidence' => 'Independent actual dispatch evidence',
        ]);
        $guard = app(\App\Services\HeldCashReservation::class);
        DB::transaction(fn () => $guard->assertOrdinaryOutflow((int) $account['id'], 8000, '2026-11-25'));
        $this->rejects('held_cash_reserved', fn () => DB::transaction(fn () =>
            $guard->assertOrdinaryOutflow((int) $account['id'], 8001, '2026-11-25')));
        DB::table('treasury_postings')->where('treasury_transfer_id', $dispatch['id'])
            ->update(['treasury_account_id' => $destination['id']]);
        $this->rejects('held_cash_liquidity_missing_posting', fn () => DB::transaction(fn () =>
            $guard->assertOrdinaryOutflow((int) $account['id'], 1, '2026-11-25')));
        $this->assertDatabaseCount('treasury_transfers', 1);
        $this->assertDatabaseCount('treasury_postings', 2);
    }

    public function test_payout_verifier_rejects_a_modified_caller_snapshot(): void
    {
        [, , , , $lot, $subscriber] = $this->held();
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->payoutHeld($this->actor(), 5);
        $service = app(\App\Services\TreasuryHeldCashLiabilityService::class);
        $posting = DB::table('treasury_postings')->where('kind', 'held_cash_payout')->sole();
        $this->assertTrue(DB::transaction(fn () => $service->checkPayoutPosting($posting)));
        $wrong = clone $posting;
        $wrong->amount_minor = -6;
        $this->assertFalse(DB::transaction(fn () => $service->checkPayoutPosting($wrong)));
        $this->assertSame(-5, (int) DB::table('treasury_postings')->where('kind', 'held_cash_payout')->sole()->amount_minor);
    }
    /** Deterministic SQL-order check, not a concurrent execution proof. */
    private function assertIssuancePrecedesSubscriber(callable $operation): mixed
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $result = $operation();
            $queries = array_map(static fn ($entry) => strtolower(str_replace(['"', chr(96)], '', $entry['query'])), DB::getQueryLog());
            $issuance = $subscriber = null;
            foreach ($queries as $index => $query) {
                if (! str_starts_with(ltrim($query), 'select')) continue;
                if ($issuance === null && preg_match('/\bfrom\s+payment_issuance_locks\b/', $query)) $issuance = $index;
                if ($subscriber === null && preg_match('/\bfrom\s+subscribers\b/', $query)) $subscriber = $index;
            }
            $this->assertNotNull($issuance, 'Missing issuance lock read.');
            $this->assertNotNull($subscriber, 'Missing subscriber read.');
            $this->assertLessThan($subscriber, $issuance, 'Issuance must precede subscriber.');
            return $result;
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_collection_and_cash_refund_acquire_issuance_before_subscriber(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $actor = $this->actor();
        $receipt = $this->assertIssuancePrecedesSubscriber(fn () =>
            app(PaymentService::class)->collectPayment($subscriber, '200.00'));
        $refund = $this->assertIssuancePrecedesSubscriber(fn () =>
            app(\App\Services\PaymentRefundService::class)->record($receipt, $actor, '40.00',
                'QA-ISSUANCE-ORDER-REFUND', 'treasury_cash', '2026-11-25',
                'Synthetic refund of excess subscriber credit', 'Independent evidence of actual cash disbursement'));
        $this->assertSame((int) $receipt->id, (int) $refund->payment_id);
        $this->assertDatabaseCount('payment_refunds', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertSame(4000, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->unpostedCashRefundMinor()));
    }
    public function test_invoice_and_direct_allocation_take_issuance_before_subscriber(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        \App\Models\Tariff::create(['name' => 'Synthetic fence tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-HELD-ISSUE-FENCE']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 25);
        $invoice = $this->assertIssuancePrecedesSubscriber(fn () =>
            app(\App\Services\InvoiceService::class)->generateInvoice($subscriber, $meter));
        $this->assertIssuancePrecedesSubscriber(fn () => app(PaymentService::class)->recalculateInvoices($subscriber));
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(15, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        $proof = $this->gate(fn () => app(\App\Services\HeldCashCreditAllocationEvidence::class)->inspectAccount((int) $account['id']));
        $this->assertSame(10, $proof['applied_minor']);
        $this->assertSame(15, $proof['unapplied_minor']);
        $this->assertSame(25, $proof['protected_minor']);
        $this->assertFalse($proof['spending_release_authorized']);
    }

    public function test_direct_invoice_creation_rolls_back_allocation_on_created_event_failure(): void
    {
        [, , , , $lot, $subscriber] = $this->held();
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 25);
        $before = DB::table('payment_allocation_revisions')->where('subscriber_id', $subscriber->id)->orderBy('id')->get()->toArray();
        $allocations = DB::table('payment_allocations')->orderBy('id')->get()->toArray();
        $candidate = new \App\Models\Invoice([
            'subscriber_id' => $subscriber->id, 'invoice_type' => 'meter',
            'bill_number' => 'QA-FAILED-DIRECT-CREATE', 'bill_date' => '2026-11-25',
            'billing_period_start' => '2026-11-01', 'billing_period_end' => '2026-11-24',
            'consumption_fee' => '0.10', 'previous_balance' => '0.00', 'credit_applied' => '0.10',
            'total_amount' => '0.10', 'amount_paid' => '0.00', 'status' => 'issued',
        ]);
        $dispatcher = \App\Models\Invoice::getEventDispatcher();
        $this->assertNotNull($dispatcher);
        \App\Models\Invoice::setEventDispatcher(clone $dispatcher);
        try {
            \App\Models\Invoice::created(static function (): void {
                throw new \DomainException('qa_invoice_created_failure');
            });
            $this->rejects('qa_invoice_created_failure', fn () => $candidate->save());
        } finally {
            \App\Models\Invoice::setEventDispatcher($dispatcher);
        }
        $this->assertDatabaseCount('invoices', 0);
        $this->assertEquals($before, DB::table('payment_allocation_revisions')->where('subscriber_id', $subscriber->id)->orderBy('id')->get()->toArray());
        $this->assertEquals($allocations, DB::table('payment_allocations')->orderBy('id')->get()->toArray());
        $this->assertSame(25, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
    }
    public function test_meter_replacement_preserves_held_credit_allocation_and_cash_reserve(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        \App\Models\Tariff::create(['name' => 'Synthetic retirement tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-HELD-RETIRE']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $invoice = app(\App\Services\InvoiceService::class)->generateInvoice($subscriber, $meter);
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $actor = $this->actor();
        $this->creditHeld($actor, 25);
        $proof = fn () => $this->gate(fn () => app(\App\Services\HeldCashCreditAllocationEvidence::class)->inspectAccount((int) $account['id']));
        $before = $proof();
        $postings = DB::table('treasury_postings')->orderBy('id')->get()->toArray();
        $replacement = $this->assertIssuancePrecedesSubscriber(fn () =>
            app(\App\Services\SingleMeterService::class)->replace($meter, ['unique_code' => 'QA-HELD-REPLACEMENT',
                'initial_reading' => '0.00'], '2026-11-25', 'Synthetic physical replacement', $actor->id))['current_meter'];
        $this->assertDatabaseMissing('meters', ['id' => $meter->id]);
        $this->assertSame(1, \App\Models\Meter::where('subscriber_id', $subscriber->id)->count());
        $this->assertNotSame($meter->id, $replacement->id);
        $this->assertNull($invoice->fresh()->meter_id);
        $this->assertSame((int) $meter->id, (int) $invoice->fresh()->retired_meter_id);
        $this->assertSame('0.10', $invoice->fresh()->amount_paid);
        $this->assertSame(15, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        $this->assertEquals($before, $proof());
        $this->assertEquals($postings, DB::table('treasury_postings')->orderBy('id')->get()->toArray());
        $this->assertSame(25, DB::transaction(fn () =>
            app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $account['id'])));
        $this->assertDatabaseCount('treasury_held_cash_credits', 1);
        $this->assertDatabaseCount('transactions', 2);
    }
    public function test_allocation_history_rejects_replace_by_primary_and_composite_keys(): void
    {
        [, , , , $lot, $subscriber] = $this->held();
        app(PaymentService::class)->recalculateInvoices($subscriber);
        $empty = DB::table('payment_allocation_revisions')->where('subscriber_id', $subscriber->id)->sole();
        $this->assertDatabaseMissing('payment_allocations', ['revision_id' => $empty->id]);
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 25);
        $latest = DB::table('payment_allocation_revisions')->where('subscriber_id', $subscriber->id)->orderByDesc('revision')->first();
        $row = DB::table('payment_allocations')->where('revision_id', $latest->id)->sole();
        $this->assertTrue(app(\App\Services\AllocationHistoryProtection::class)->active());
        $before = DB::table('payment_allocation_revisions')->orderBy('id')->get()->toArray();
        $rowsBefore = DB::table('payment_allocations')->orderBy('id')->get()->toArray();

        $replace = static function (string $table, array $values): void {
            $columns = array_keys($values);
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            DB::statement('REPLACE INTO '.$table.' ('.implode(', ', array_map(
                static fn ($column) => chr(96).$column.chr(96), $columns
            )).') VALUES ('.$placeholders.')', array_values($values));
        };
        $badRevision = array_replace((array) $empty, ['available_minor' => 1]);
        $badCompositeRevision = array_replace($badRevision, ['id' => (int) $latest->id + 1000]);
        $badRow = array_replace((array) $row, ['amount_minor' => 1]);
        $badCompositeRow = array_replace($badRow, ['id' => (int) $row->id + 1000]);
        foreach ([
            ['payment_allocation_revisions', $badRevision],
            ['payment_allocation_revisions', $badCompositeRevision],
            ['payment_allocations', $badRow],
            ['payment_allocations', $badCompositeRow],
        ] as [$table, $values]) {
            try {
                DB::transaction(fn () => $replace($table, $values));
                $this->fail('Saved allocation history was replaced.');
            } catch (\Illuminate\Database\QueryException $error) {
                $this->assertStringContainsString('Allocation history is append-only', $error->getMessage());
            }
        }
        $this->assertEquals($before, DB::table('payment_allocation_revisions')->orderBy('id')->get()->toArray());
        $this->assertEquals($rowsBefore, DB::table('payment_allocations')->orderBy('id')->get()->toArray());
        $this->assertTrue(app(\App\Services\AllocationSnapshotIntegrity::class)->valid($empty));
        $this->assertTrue(app(\App\Services\AllocationSnapshotIntegrity::class)->valid($latest));
        $this->assertSame(25, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
    }
    public function test_self_consistent_forged_allocation_is_not_a_valid_held_cash_release_proof(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        \App\Models\Tariff::create(['name' => 'Synthetic policy tariff', 'unit_price' => '0.25', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-HELD-FORGED']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $invoice = app(\App\Services\InvoiceService::class)->generateInvoice($subscriber, $meter);
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 25);
        app(PaymentService::class)->collectPayment($subscriber, '0.25');
        $audit = app(\App\Services\FinancialReconciliationService::class);
        $this->assertTrue($audit->inspect($subscriber)['matches']);
        $latest = DB::table('payment_allocation_revisions')->where('subscriber_id', $subscriber->id)->orderByDesc('revision')->first();
        $integrity = app(\App\Services\AllocationSnapshotIntegrity::class);
        $original = DB::table('payment_allocations')->where('revision_id', $latest->id)->orderBy('position')->get();
        $this->assertCount(2, $original);
        $this->assertSame(25, (int) $original[0]->amount_minor);
        $this->assertSame(25, (int) $original[1]->amount_minor);
        $this->assertNotSame($original[0]->invoice_id, $original[1]->invoice_id);
        // Swap destinations while preserving each source amount, invoice paid
        // total, outstanding credit, ledger balance and a valid new fingerprint.
        $rows = $original->map(function ($row, $index) use ($original) {
            $source = ['payment_id' => $row->payment_id === null ? null : (int) $row->payment_id,
                'account_transfer_id' => $row->account_transfer_id === null ? null : (int) $row->account_transfer_id];
            if ($row->held_cash_credit_id !== null) $source['held_cash_credit_id'] = (int) $row->held_cash_credit_id;
            $other = $original[1 - $index]->invoice_id;
            return $source + ['invoice_id' => $other === null ? null : (int) $other, 'amount_minor' => (int) $row->amount_minor];
        })->all();
        $forged = DB::table('payment_allocation_revisions')->insertGetId([
            'subscriber_id' => $subscriber->id, 'revision' => (int) $latest->revision + 1,
            'allocation_hash' => $integrity->fingerprint((int) $subscriber->id, 50, 25, 25, $rows),
            'available_minor' => 50, 'allocated_minor' => 25, 'unallocated_minor' => 25, 'created_at' => now(),
        ]);
        foreach ($rows as $position => $row) {
            DB::table('payment_allocations')->insert($row + ['held_cash_credit_id' => null,
                'revision_id' => $forged, 'position' => $position + 1]);
        }
        $snapshot = DB::table('payment_allocation_revisions')->where('id', $forged)->sole();
        $this->assertTrue($integrity->valid($snapshot));
        $this->assertTrue($integrity->valid($latest));
        $this->assertSame('0.25', $invoice->fresh()->amount_paid);
        $this->assertSame(25, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        $result = $audit->inspect($subscriber);
        $this->assertFalse($result['matches']);
        $this->assertSame(['allocation_policy_mismatch'], array_column($result['issues'], 'code'));
        $this->assertFalse(app(\App\Services\PaymentAllocationReadService::class)->reconcile($subscriber)['matches']);
        $this->rejects('held_credit_allocation_reconciliation', fn () =>
            $this->gate(fn () => app(\App\Services\HeldCashCreditAllocationEvidence::class)->inspectAccount((int) $account['id'])));
        $this->assertSame($forged, DB::table('payment_allocation_revisions')->where('subscriber_id', $subscriber->id)->orderByDesc('revision')->value('id'));
        $this->assertDatabaseCount('treasury_held_cash_credits', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
    }
    public function test_release_preview_distinguishes_applied_credit_from_authorized_cash(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        \App\Models\Tariff::create(['name' => 'Synthetic release preview tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-RELEASE-PREVIEW']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $invoice = app(\App\Services\InvoiceService::class)->generateInvoice($subscriber, $meter);
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $actor = $this->actor();
        $this->creditHeld($actor, 20);
        $preview = app(\App\Services\HeldCashReleaseReadiness::class);
        $before = DB::table('treasury_postings')->orderBy('id')->get()->toArray();
        $result = $preview->preview((int) $account['id'], $this->nowAt());
        $this->assertSame(10025, $result['recorded_balance_minor']);
        $this->assertSame(5, $result['outstanding_minor']);
        $this->assertSame(20, $result['credited_minor']);
        $this->assertSame(10, $result['applied_minor']);
        $this->assertSame(10, $result['unapplied_minor']);
        $this->assertSame(25, $result['protected_minor']);
        $this->assertSame(10000, $result['margin_minor']);
        $this->assertSame(10, $result['candidate_additional_release_minor']);
        $this->assertSame(15, $result['candidate_protected_minor']);
        $this->assertSame(10010, $result['candidate_margin_minor']);
        $this->assertFalse($result['spending_release_authorized']);
        $this->assertFalse($result['source_coverage_verified']);
        $this->assertFalse($result['closure_authorized']);
        $this->rejects('held_cash_reserved', fn () => DB::transaction(fn () =>
            app(\App\Services\HeldCashReservation::class)->assertOrdinaryOutflow((int) $account['id'], 10001, '2026-11-25')));
        app(\App\Services\InvoiceCancellationService::class)->cancel($invoice, 'Synthetic independently documented cancellation', $actor->id);
        $after = $preview->preview((int) $account['id'], $this->nowAt());
        $this->assertSame(0, $after['candidate_additional_release_minor']);
        $this->assertSame(25, $after['protected_minor']);
        $this->assertSame(10000, $after['margin_minor']);
        $this->assertEquals($before, DB::table('treasury_postings')->orderBy('id')->get()->toArray());
    }

    public function test_release_policy_restores_per_credit_and_never_nets_unrelated_allocations(): void
    {
        $policy = app(\App\Support\HeldCashReleasePolicy::class);
        $before = [
            ['credit_id' => 1, 'amount_minor' => 25, 'applied_minor' => 20, 'released_minor' => 15],
            ['credit_id' => 2, 'amount_minor' => 25, 'applied_minor' => 5, 'released_minor' => 0],
        ];
        $after = [
            ['credit_id' => 1, 'amount_minor' => 25, 'applied_minor' => 10],
            ['credit_id' => 2, 'amount_minor' => 25, 'applied_minor' => 15],
        ];
        $result = $policy->reinstate(40, 0, 0, $before, $after);
        $this->assertSame(5, $result['restored_reserve_minor']);
        $this->assertSame(10, $result['released_minor']);
        $this->assertSame(40, $result['protected_minor']);
        $this->assertSame(0, $result['margin_minor']);
        $this->assertTrue($result['optional_change_has_liquidity']);
        $this->assertSame(0, $result['credits'][1]['released_minor']);
        $deficit = $policy->reinstate(39, 0, 0, $before, $after);
        $this->assertSame(1, $deficit['shortfall_minor']);
        $this->assertFalse($deficit['optional_change_has_liquidity']);
        $this->assertTrue($deficit['actual_event_must_be_recorded']);
        $this->assertSame(0, $deficit['candidate_additional_release_minor']);
        $this->assertFalse($deficit['spending_release_authorized']);
        $this->rejects('held_release_credit_bound', fn () => $policy->evaluate(100, 0, 0,
            [['credit_id' => 1, 'amount_minor' => 25, 'applied_minor' => 10, 'released_minor' => 11]]));
        $this->rejects('held_release_credit_identity', fn () => $policy->reinstate(100, 0, 0, $before, [$after[0]]));
        $this->assertSame(0, $policy->evaluate(100, 101, 0, [])['candidate_additional_release_minor']);
    }
    public function test_release_proposal_and_withdrawal_are_immutable_and_never_reduce_actual_reserve(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        \App\Models\Tariff::create(['name' => 'Synthetic proposal tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-RELEASE-PROPOSAL']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $invoice = app(\App\Services\InvoiceService::class)->generateInvoice($subscriber, $meter);
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $creditActor = $this->actor();
        $this->creditHeld($creditActor, 20);
        $credit = DB::table('treasury_held_cash_credits')->sole();
        $actor = $this->actor();
        $reviewer = $this->actor();
        $service = app(\App\Services\HeldCashReleaseProposalService::class);
        $uuid = '66666666-6666-4666-8666-666666666666';
        $propose = fn ($who, $minor) => $service->propose($who, (int) $account['id'], (int) $credit->id,
            $minor, $uuid, 'QA-RELEASE-PROPOSAL', 'Independent review of allocation evidence', $this->nowAt());
        $this->rejects('held_release_proposal_independence', fn () => $propose($creditActor, 10));
        $this->rejects('held_release_proposal_capacity', fn () => $propose($actor, 11));
        $result = $propose($actor, 10);
        $this->assertTrue($result['created']);
        $this->assertFalse($result['spending_release_authorized']);
        $this->assertFalse($propose($actor, 10)['created']);
        $coverage = $service->inspect((int) $account['id'], $this->nowAt());
        $this->assertSame(1, $coverage['current']);
        $this->assertTrue($coverage['document_coverage_verified']);
        $this->assertFalse($coverage['spending_release_authorized']);
        $this->rejects('held_release_proposal_conflict', fn () => $propose($actor, 9));
        $this->assertDatabaseCount('held_cash_release_proposals', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->rejects('held_cash_reserved', fn () => DB::transaction(fn () =>
            app(\App\Services\HeldCashReservation::class)->assertOrdinaryOutflow((int) $account['id'], 10001, '2026-11-25')));
        app(\App\Services\InvoiceCancellationService::class)->cancel($invoice, 'Synthetic independently documented cancellation', $reviewer->id);
        $this->rejects('held_release_proposal_conflict', fn () => $propose($actor, 10));
        $this->assertSame(1, $service->inspect((int) $account['id'], $this->nowAt())['stale']);
        $withdraw = fn ($who) => $service->withdraw($who, (int) $account['id'], $uuid,
            'Independent review of changed allocation evidence', $this->nowAt());
        $this->rejects('held_release_withdrawal_identity', fn () => $withdraw($actor));
        $cancel = $withdraw($reviewer);
        $this->assertTrue($cancel['created']);
        $this->assertSame(0, $cancel['applied_minor']);
        $this->assertFalse($cancel['spending_release_authorized']);
        $this->assertFalse($withdraw($reviewer)['created']);
        $this->assertSame(1, $service->inspect((int) $account['id'], $this->nowAt())['withdrawn']);
        foreach ([
            fn () => DB::table('held_cash_release_proposals')->where('operation_uuid', $uuid)->update(['amount_minor' => 1]),
            fn () => DB::table('held_cash_release_proposals')->where('operation_uuid', $uuid)->delete(),
            fn () => DB::table('held_cash_release_withdrawals')->where('proposal_uuid', $uuid)->update(['applied_minor' => 10]),
            fn () => DB::table('held_cash_release_withdrawals')->where('proposal_uuid', $uuid)->delete(),
        ] as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('Saved proposal evidence was mutable.');
            } catch (\Illuminate\Database\QueryException $error) {
                $this->assertStringContainsString('Held release proposal is immutable', $error->getMessage());
            }
        }
        $this->assertDatabaseCount('held_cash_release_withdrawals', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $account['id'])));
        $this->assertSame(20, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
    }
    public function test_release_coverage_rejects_orphan_audit_without_changing_reserve(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $actor = $this->actor();
        \App\Models\AuditEvent::create(['actor_user_id' => $actor->id,
            'event' => 'treasury.held_release_proposed', 'entity_type' => 'held_cash_release_proposal',
            'entity_id' => '77777777-7777-4777-8777-777777777777', 'after' => [], 'source' => 'internal', 'occurred_at' => now()]);
        $this->rejects('held_release_coverage', fn () =>
            app(\App\Services\HeldCashReleaseProposalService::class)->inspect((int) $account['id'], $this->nowAt()));
        $this->assertDatabaseCount('held_cash_release_proposals', 0);
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $account['id'])));
    }
    public function test_release_journal_records_exact_shadow_release_and_reinstatement_without_spending(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        \App\Models\Tariff::create(['name' => 'Synthetic journal tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-RELEASE-JOURNAL']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $invoice = app(\App\Services\InvoiceService::class)->generateInvoice($subscriber, $meter);
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $creditActor = $this->actor();
        $this->creditHeld($creditActor, 20);
        $credit = DB::table('treasury_held_cash_credits')->sole();
        $proposer = $this->actor();
        $reviewer = $this->actor();
        $service = app(\App\Services\HeldCashReleaseProposalService::class);
        $accountId = (int) $account['id'];
        $proposal = '66666666-6666-4666-8666-666666666666';
        $release = '77777777-7777-4777-8777-777777777777';
        $restore = '88888888-8888-4888-8888-888888888888';
        $service->propose($proposer, $accountId, (int) $credit->id, 10, $proposal,
            'QA-JOURNAL-PROPOSAL', 'Independent review of allocation evidence', $this->nowAt());
        $record = fn ($actor) => $service->recordRelease($actor, $accountId, $proposal, $release,
            'Independent approval of shadow release', $this->nowAt());
        $this->rejects('held_release_journal_proposal', fn () => $record($proposer));
        $this->rejects('held_release_proposal_independence', fn () => $record($creditActor));
        $result = $record($reviewer);
        $this->assertSame(10, $result['after_minor']);
        $this->assertFalse($result['spending_release_authorized']);
        $this->rejects('held_release_journal_duplicate', fn () => $record($reviewer));
        $this->assertSame(10, $service->inspectJournal($accountId, $this->nowAt())['shadow_released_minor']);
        // Inject failure only in the new atomic evidence write.
        $trigger = DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER qa_shadow_restore_failure BEFORE INSERT ON held_cash_shadow_reinstatements BEGIN SELECT RAISE(ABORT, 'synthetic_shadow_restore_failure'); END"
            : "CREATE TRIGGER qa_shadow_restore_failure BEFORE INSERT ON held_cash_shadow_reinstatements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic_shadow_restore_failure'";
        DB::unprepared($trigger);
        $revision = DB::table('payment_allocation_revisions')->where('subscriber_id', $subscriber->id)->orderByDesc('revision')->value('id');
        try {
            app(\App\Services\InvoiceCancellationService::class)->cancel($invoice, 'Synthetic independently documented cancellation', $reviewer->id);
            $this->fail('Cancellation committed without reinstatement evidence.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('synthetic_shadow_restore_failure', $error->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS qa_shadow_restore_failure');
        }
        $this->assertNotSame('cancelled', $invoice->fresh()->status);
        $this->assertSame($revision, DB::table('payment_allocation_revisions')->where('subscriber_id', $subscriber->id)->orderByDesc('revision')->value('id'));
        $this->assertDatabaseCount('held_cash_shadow_reinstatements', 0);
        $this->assertSame(10, $service->inspectJournal($accountId, $this->nowAt())['shadow_released_minor']);
        app(\App\Services\InvoiceCancellationService::class)->cancel($invoice->fresh(), 'Synthetic independently documented cancellation', $reviewer->id);
        $event = DB::table('held_cash_shadow_reinstatements')->sole();
        $this->assertSame(10, (int) $event->before_minor);
        $this->assertSame(0, (int) $event->after_minor);
        $this->assertSame(0, $service->inspectJournal($accountId, $this->nowAt())['reinstatement_due_minor']);
        $this->assertSame(0, $service->inspectJournal($accountId, $this->nowAt())['shadow_released_minor']);
        $this->rejects('held_release_journal_no_reinstatement', fn () => $service->recordReinstatement(
            $reviewer, $accountId, (int) $credit->id, $restore, 'Independent review of changed allocation evidence', $this->nowAt()));
        app(PaymentService::class)->recalculateInvoices($subscriber);
        $this->assertDatabaseCount('held_cash_shadow_reinstatements', 1);
        $this->assertSame(25, $service->inspectJournal($accountId, $this->nowAt())['actual_protected_minor']);
        $this->assertDatabaseCount('held_cash_release_journal', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
        try {
            DB::transaction(fn () => DB::table('held_cash_release_journal')->where('operation_uuid', $release)->update(['amount_minor' => 1]));
            $this->fail('Release journal was mutable.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('Held release journal is immutable', $error->getMessage());
        }
    }
    public function test_round122_corrections_restore_shadow_release_and_preserve_exact_account_balances(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        \App\Models\Tariff::create(['name' => 'Synthetic correction tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-SHADOW-CORRECTIONS']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $invoice = app(\App\Services\InvoiceService::class)->generateInvoice($subscriber, $meter);
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 20);
        $credit = DB::table('treasury_held_cash_credits')->sole();
        $service = app(\App\Services\HeldCashReleaseProposalService::class);
        $accountId = (int) $account['id'];
        $proposal = '66666666-6666-4666-8666-666666666666';
        $service->propose($this->actor(), $accountId, (int) $credit->id, 10, $proposal,
            'QA-CORRECTION-PROPOSAL', 'Independent review of allocation evidence', $this->nowAt());
        $actor = $this->actor();
        $service->recordRelease($actor, $accountId, $proposal, '77777777-7777-4777-8777-777777777777',
            'Independent approval of shadow release', $this->nowAt());
        $payments = app(PaymentService::class);
        $ledger = app(\App\Services\TransactionService::class);
        $receipt = $payments->collectPayment($subscriber, '0.30', paymentDate: '2026-11-25');
        $this->assertSame(40, $ledger->accountBalanceMinor($subscriber));
        // Same-day receipts precede held credit in the canonical allocator.
        // The new receipt covers the invoice; the formerly applied held credit
        // is unapplied and the prior shadow release must be fully reinstated.
        $event = DB::table('held_cash_shadow_reinstatements')->sole();
        $this->assertSame(10, (int) $event->before_minor);
        $this->assertSame(0, (int) $event->applied_minor);
        $this->assertSame(0, (int) $event->after_minor);
        $this->assertSame(0, $service->inspectJournal($accountId, $this->nowAt())['shadow_released_minor']);
        $refund = app(\App\Services\PaymentRefundService::class)->record($receipt, $actor, '0.10',
            'QA-SHADOW-REFUND', 'treasury_cash', '2026-11-25', 'Synthetic independently evidenced refund', 'Independent proof of completed disbursement');
        $this->assertSame(30, $ledger->accountBalanceMinor($subscriber));
        $this->assertSame(0, $service->inspectJournal($accountId, $this->nowAt())['shadow_released_minor']);
        $transferReceipt = $payments->collectPayment($subscriber, '0.15');
        $destination = Subscriber::create(['name' => 'Synthetic destination', 'square_id' => $subscriber->square_id, 'status' => 'active']);
        app(\App\Services\PaymentAccountTransferService::class)->transfer($transferReceipt, (int) $destination->id, $actor,
            'Synthetic documented receipt correction', 'Independent evidence of correct subscriber');
        $this->assertSame(30, $ledger->accountBalanceMinor($subscriber));
        $this->assertSame(15, $ledger->accountBalanceMinor($destination));
        $voidReceipt = $payments->collectPayment($subscriber, '0.05');
        $payments->voidPayment($voidReceipt, 'Synthetic documented receipt cancellation', $actor->id);
        $this->assertSame(30, $ledger->accountBalanceMinor($subscriber));
        $this->assertSame('0.10', $invoice->fresh()->amount_paid);
        $this->assertTrue(app(\App\Services\FinancialReconciliationService::class)->inspect($subscriber)['matches']);
        $this->assertTrue(app(\App\Services\FinancialReconciliationService::class)->inspect($destination)['matches']);
        $report = $service->inspectJournal($accountId, $this->nowAt());
        $this->assertSame(0, $report['shadow_released_minor']);
        $this->assertSame(0, $report['reinstatement_due_minor']);
        $this->assertSame(25, $report['actual_protected_minor']);
        $this->assertFalse($report['spending_release_authorized']);
        $this->assertDatabaseCount('held_cash_shadow_reinstatements', 1);
        $this->assertDatabaseCount('payment_refunds', 1);
        $this->assertDatabaseCount('payment_account_transfers', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
    }

    public function test_round121_missing_intermediate_shadow_reinstatement_is_detected(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        \App\Models\Tariff::create(['name' => 'Synthetic intermediate tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-SHADOW-INTERMEDIATE']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $invoice = app(\App\Services\InvoiceService::class)->generateInvoice($subscriber, $meter);
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 20);
        $credit = DB::table('treasury_held_cash_credits')->sole();
        $service = app(\App\Services\HeldCashReleaseProposalService::class);
        $accountId = (int) $account['id'];
        $proposal = '66666666-6666-4666-8666-666666666666';
        $service->propose($this->actor(), $accountId, (int) $credit->id, 10, $proposal,
            'QA-INTERMEDIATE-PROPOSAL', 'Independent review of allocation evidence', $this->nowAt());
        $reviewer = $this->actor();
        $service->recordRelease($reviewer, $accountId, $proposal, '77777777-7777-4777-8777-777777777777',
            'Independent approval of shadow release', $this->nowAt());
        app(\App\Services\InvoiceCancellationService::class)->cancel($invoice, 'Synthetic independently documented cancellation', $reviewer->id);
        app(\App\Services\InvoiceService::class)->generateInvoice($subscriber, $meter);
        $this->assertSame(0, $service->inspectJournal($accountId, $this->nowAt())['shadow_released_minor']);
        // Deliberate corruption of disposable test data, never production.
        DB::unprepared('DROP TRIGGER shadow_restore_reject_delete');
        DB::table('held_cash_shadow_reinstatements')->delete();
        // Restore the guard before testing independent missing-evidence detection.
        $body = DB::getDriverName() === 'sqlite'
            ? "BEGIN SELECT RAISE(ABORT, 'Shadow reinstatement is immutable'); END"
            : "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Shadow reinstatement is immutable'";
        DB::unprepared("CREATE TRIGGER shadow_restore_reject_delete BEFORE DELETE ON held_cash_shadow_reinstatements {$body}");
        $this->rejects('shadow_restore_missing_event', fn () => $service->inspectJournal($accountId, $this->nowAt()));
        $this->rejects('shadow_restore_missing_event', fn () => app(PaymentService::class)->recalculateInvoices($subscriber));
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount($accountId)));
    }
    public function test_round123_missing_latest_shadow_reinstatement_is_not_silently_repaired(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        \App\Models\Tariff::create(['name' => 'Synthetic intermediate tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-SHADOW-INTERMEDIATE']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $invoice = app(\App\Services\InvoiceService::class)->generateInvoice($subscriber, $meter);
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 20);
        $credit = DB::table('treasury_held_cash_credits')->sole();
        $service = app(\App\Services\HeldCashReleaseProposalService::class);
        $accountId = (int) $account['id'];
        $proposal = '66666666-6666-4666-8666-666666666666';
        $service->propose($this->actor(), $accountId, (int) $credit->id, 10, $proposal,
            'QA-INTERMEDIATE-PROPOSAL', 'Independent review of allocation evidence', $this->nowAt());
        $reviewer = $this->actor();
        $service->recordRelease($reviewer, $accountId, $proposal, '77777777-7777-4777-8777-777777777777',
            'Independent approval of shadow release', $this->nowAt());
        app(\App\Services\InvoiceCancellationService::class)->cancel($invoice, 'Synthetic independently documented cancellation', $reviewer->id);
        $this->assertSame(0, $service->inspectJournal($accountId, $this->nowAt())['shadow_released_minor']);
        // Deliberate corruption of disposable test data, never production.
        DB::unprepared('DROP TRIGGER shadow_restore_reject_delete');
        DB::table('held_cash_shadow_reinstatements')->delete();
        // Restore the guard before testing independent missing-evidence detection.
        $body = DB::getDriverName() === 'sqlite'
            ? "BEGIN SELECT RAISE(ABORT, 'Shadow reinstatement is immutable'); END"
            : "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Shadow reinstatement is immutable'";
        DB::unprepared("CREATE TRIGGER shadow_restore_reject_delete BEFORE DELETE ON held_cash_shadow_reinstatements {$body}");
        $this->rejects('shadow_restore_missing_event', fn () => $service->inspectJournal($accountId, $this->nowAt()));
        $this->rejects('shadow_restore_missing_event', fn () => app(PaymentService::class)->recalculateInvoices($subscriber));
        $this->assertDatabaseCount('held_cash_shadow_reinstatements', 0);
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount($accountId)));
    }

    public function test_round125_actual_loss_keeps_full_held_reserve_and_blocks_outflow(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        \App\Models\Tariff::create(['name' => 'Synthetic current loss tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-ROUND125-LOSS']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $invoice = app(\App\Services\InvoiceService::class)->generateInvoice($subscriber, $meter);
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 20);
        $credit = DB::table('treasury_held_cash_credits')->sole();
        $accountId = (int) $account['id'];
        $service = app(\App\Services\HeldCashReleaseProposalService::class);
        $preview = app(\App\Services\HeldCashReleaseReadiness::class);
        $proposal = '66666666-6666-4666-8666-666666666666';
        $service->propose($this->actor(), $accountId, (int) $credit->id, 5, $proposal,
            'QA-LOSS-SHADOW', 'Independent review of current allocation evidence', $this->nowAt());
        $service->recordRelease($this->actor(), $accountId, $proposal, '77777777-7777-4777-8777-777777777777',
            'Independent approval of shadow release', $this->nowAt());
        $before = $preview->preview($accountId, $this->nowAt());
        $this->assertSame(10025, $before['recorded_balance_minor']);
        $this->assertSame(25, $before['protected_minor']);
        $this->assertSame(0, $before['shortfall_minor']);
        $this->assertSame(5, $service->inspectJournal($accountId, $this->nowAt())['shadow_released_minor']);
        $revision = DB::table('payment_allocation_revisions')->where('subscriber_id', $subscriber->id)->orderByDesc('revision')->value('id');
        $ledger = app(\App\Services\TransactionService::class);
        $subscriberBalance = $ledger->accountBalanceMinor($subscriber);
        $counts = app(TreasuryCashCountService::class);
        $counter = $this->actor();
        $reviewer = $this->actor();
        $observation = $counts->record($counter, $accountId, '0.24', 'QA-ROUND125-COUNT', self::EVIDENCE);
        $countId = (int) $observation['count']['id'];
        $counts->review($reviewer, $countId, true, 'Independent review of newly observed physical loss');
        $lossUuid = '88888888-8888-4888-8888-888888888888';
        $post = fn () => app(GatedCashCountAdjustmentWriter::class)->adjust($reviewer, $countId,
            'QA-ROUND125-ADJUST', 'confirmed_cash_loss', self::REASON, self::EVIDENCE, $lossUuid, $this->nowAt());
        $loss = $post();
        $this->assertTrue($loss['registry_created']);
        $this->assertSame(-10001, (int) $loss['posting']['amount_minor']);
        $this->assertSame(24, $loss['observation']['current_recorded_minor']);
        $after = $preview->preview($accountId, $this->nowAt());
        $this->assertSame(24, $after['recorded_balance_minor']);
        $this->assertSame(5, $after['outstanding_minor']);
        $this->assertSame(20, $after['credited_minor']);
        $this->assertSame(25, $after['protected_minor']);
        $this->assertSame(1, $after['shortfall_minor']);
        $this->assertSame(0, $after['candidate_additional_release_minor']);
        $this->assertFalse($after['spending_release_authorized']);
        $journal = $service->inspectJournal($accountId, $this->nowAt());
        $this->assertSame(5, $journal['shadow_released_minor']);
        $this->assertSame(25, $journal['actual_protected_minor']);
        $this->assertFalse($journal['spending_release_authorized']);
        $report = app(TreasuryRegisterService::class)->inspect($reviewer, $accountId);
        $this->assertSame(1, $report['held_cash_possible_shortfall_minor']);
        $this->assertContains(['code' => 'held_cash_possible_liquidity_shortfall', 'amount_minor' => 1], $report['issues']);
        $this->rejects('held_release_proposal_capacity', fn () => $service->propose($this->actor(), $accountId,
            (int) $credit->id, 1, '99999999-9999-4999-8999-999999999999',
            'QA-LOSS-NEW-RELEASE', 'Independent review after actual loss', $this->nowAt()));
        $this->rejects('held_cash_reserved', fn () => app(CashSessionService::class)->openFunded(
            $this->actor(), $reviewer, $accountId, '0.01', 'QA-LOSS-FUND', 'Independent physical funding evidence'));
        $this->assertDatabaseCount('cash_session_fundings', 0);
        $again = $post();
        $this->assertFalse($again['registry_created']);
        $this->assertSame($loss['posting']['id'], $again['posting']['id']);
        $this->assertSame($subscriberBalance, $ledger->accountBalanceMinor($subscriber));
        $this->assertSame('0.10', $invoice->fresh()->amount_paid);
        $this->assertSame($revision, DB::table('payment_allocation_revisions')->where('subscriber_id', $subscriber->id)->orderByDesc('revision')->value('id'));
        $this->assertDatabaseCount('held_cash_release_journal', 1);
        $this->assertDatabaseCount('held_cash_shadow_reinstatements', 0);
        $this->assertDatabaseCount('treasury_cash_adjustments', 2);
        $this->assertDatabaseCount('treasury_postings', 2);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $lossUuid,
            'source_type' => 'treasury_posting', 'source_id' => (string) $loss['posting']['id'], 'amount_minor' => -10001]);
    }

    public function test_round126_release_journal_missing_protection_blocks_read_and_approval(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        \App\Models\Tariff::create(['name' => 'Synthetic journal protection tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-ROUND126-PROTECTION']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        app(\App\Services\InvoiceService::class)->generateInvoice($subscriber, $meter);
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 20);
        $credit = DB::table('treasury_held_cash_credits')->sole();
        $accountId = (int) $account['id'];
        $service = app(\App\Services\HeldCashReleaseProposalService::class);
        $protection = app(\App\Services\HeldCashReleaseDocumentProtection::class);
        $proposal = '66666666-6666-4666-8666-666666666666';
        $service->propose($this->actor(), $accountId, (int) $credit->id, 5, $proposal,
            'QA-ROUND126-PROPOSAL', 'Independent review of current allocation evidence', $this->nowAt());
        $reviewer = $this->actor();
        $approve = fn () => $service->recordRelease($reviewer, $accountId, $proposal,
            '77777777-7777-4777-8777-777777777777', 'Independent approval of shadow release', $this->nowAt());
        $this->assertTrue($protection->journalActive());
        $this->assertSame(0, $service->inspectJournal($accountId, $this->nowAt())['shadow_released_minor']);
        $auditCount = AuditEvent::query()->count();
        $body = DB::getDriverName() === 'sqlite'
            ? "BEGIN SELECT RAISE(ABORT, 'Held release journal is immutable'); END"
            : "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Held release journal is immutable'";
        foreach (['UPDATE', 'DELETE'] as $event) {
            $name = 'release_journal_reject_'.strtolower($event);
            DB::unprepared("DROP TRIGGER {$name}");
            try {
                $this->assertTrue($protection->active());
                $this->assertFalse($protection->journalActive());
                $this->rejects('held_release_protection_missing', fn () => $service->inspectJournal($accountId, $this->nowAt()));
                $this->rejects('held_release_protection_missing', $approve);
                $this->assertDatabaseCount('held_cash_release_journal', 0);
                $this->assertSame($auditCount, AuditEvent::query()->count());
            } finally {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON held_cash_release_journal {$body}");
            }
        }
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER release_journal_reject_replace');
            try {
                $this->assertFalse($protection->journalActive());
                $this->rejects('held_release_protection_missing', $approve);
                $this->assertDatabaseCount('held_cash_release_journal', 0);
            } finally {
                DB::unprepared("CREATE TRIGGER release_journal_reject_replace BEFORE INSERT ON held_cash_release_journal
                    FOR EACH ROW WHEN EXISTS (SELECT 1 FROM held_cash_release_journal j WHERE j.id = NEW.id
                    OR j.operation_uuid = NEW.operation_uuid OR j.proposal_uuid = NEW.proposal_uuid) {$body}");
            }
        }
        $this->assertTrue($protection->journalActive());
        $result = $approve();
        $this->assertSame(5, $result['after_minor']);
        $this->assertFalse($result['spending_release_authorized']);
        $this->assertSame(25, $service->inspectJournal($accountId, $this->nowAt())['actual_protected_minor']);
        $this->assertDatabaseCount('held_cash_release_journal', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
    }

    public function test_round127_reinstatement_protection_rolls_back_current_cancellation(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        \App\Models\Tariff::create(['name' => 'Synthetic reinstatement protection tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-ROUND127-PROTECTION']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $invoice = app(\App\Services\InvoiceService::class)->generateInvoice($subscriber, $meter);
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 20);
        $credit = DB::table('treasury_held_cash_credits')->sole();
        $accountId = (int) $account['id'];
        $service = app(\App\Services\HeldCashReleaseProposalService::class);
        $protection = app(\App\Services\HeldCashReleaseDocumentProtection::class);
        $proposal = '66666666-6666-4666-8666-666666666666';
        $service->propose($this->actor(), $accountId, (int) $credit->id, 10, $proposal,
            'QA-ROUND127-PROPOSAL', 'Independent review of current allocation evidence', $this->nowAt());
        $reviewer = $this->actor();
        $approve = fn () => $service->recordRelease($reviewer, $accountId, $proposal,
            '77777777-7777-4777-8777-777777777777', 'Independent approval of shadow release', $this->nowAt());
        $sqlite = DB::getDriverName() === 'sqlite';
        $body = $sqlite ? "BEGIN SELECT RAISE(ABORT, 'Shadow reinstatement is immutable'); END"
            : "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Shadow reinstatement is immutable'";
        $this->assertTrue($protection->reinstatementActive());
        DB::unprepared('DROP TRIGGER shadow_restore_reject_update');
        try {
            $this->assertFalse($protection->reinstatementActive());
            $this->rejects('held_release_protection_missing', $approve);
            $this->assertDatabaseCount('held_cash_release_journal', 0);
        } finally {
            DB::unprepared("CREATE TRIGGER shadow_restore_reject_update BEFORE UPDATE ON held_cash_shadow_reinstatements {$body}");
        }
        $approve();
        $beforeInvoice = $invoice->fresh()->getRawOriginal();
        $revisionCount = DB::table('payment_allocation_revisions')->count();
        $auditCount = AuditEvent::query()->count();
        $transactionCount = DB::table('transactions')->count();
        $cancel = fn () => app(\App\Services\InvoiceCancellationService::class)->cancel(
            $invoice->fresh(), 'Synthetic independently documented cancellation', $reviewer->id);
        $noop = $sqlite ? "BEGIN SELECT 'Shadow reinstatement is immutable'; END"
            : "FOR EACH ROW SET @qa_shadow_noop = 'Shadow reinstatement is immutable'";
        DB::unprepared('DROP TRIGGER shadow_restore_reject_delete');
        try {
            $this->assertFalse($protection->reinstatementActive());
            $this->rejects('held_release_protection_missing', $cancel);
            DB::unprepared("CREATE TRIGGER shadow_restore_reject_delete BEFORE DELETE ON held_cash_shadow_reinstatements {$noop}");
            $this->assertFalse($protection->reinstatementActive());
            $this->rejects('held_release_protection_missing', fn () => $service->inspectJournal($accountId, $this->nowAt()));
            $this->rejects('held_release_protection_missing', $cancel);
            $this->assertSame($beforeInvoice, $invoice->fresh()->getRawOriginal());
            $this->assertSame($revisionCount, DB::table('payment_allocation_revisions')->count());
            $this->assertSame($auditCount, AuditEvent::query()->count());
            $this->assertSame($transactionCount, DB::table('transactions')->count());
            $this->assertDatabaseCount('held_cash_shadow_reinstatements', 0);
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS shadow_restore_reject_delete');
            DB::unprepared("CREATE TRIGGER shadow_restore_reject_delete BEFORE DELETE ON held_cash_shadow_reinstatements {$body}");
        }
        if ($sqlite) {
            DB::unprepared('DROP TRIGGER shadow_restore_reject_replace');
            try {
                $this->assertFalse($protection->reinstatementActive());
                $this->rejects('held_release_protection_missing', $cancel);
                $this->assertDatabaseCount('held_cash_shadow_reinstatements', 0);
            } finally {
                DB::unprepared("CREATE TRIGGER shadow_restore_reject_replace BEFORE INSERT ON held_cash_shadow_reinstatements
                    FOR EACH ROW WHEN EXISTS (SELECT 1 FROM held_cash_shadow_reinstatements s WHERE s.id = NEW.id
                    OR (s.journal_id = NEW.journal_id AND s.revision_id = NEW.revision_id)) {$body}");
            }
        }
        $this->assertTrue($protection->reinstatementActive());
        $cancel();
        $this->assertSame('cancelled', $invoice->fresh()->status);
        $event = DB::table('held_cash_shadow_reinstatements')->sole();
        $this->assertSame(10, (int) $event->before_minor);
        $this->assertSame(0, (int) $event->after_minor);
        $journal = $service->inspectJournal($accountId, $this->nowAt());
        $this->assertSame(0, $journal['shadow_released_minor']);
        $this->assertSame(25, $journal['actual_protected_minor']);
        $this->assertFalse($journal['spending_release_authorized']);
        $this->assertDatabaseCount('treasury_postings', 1);
    }

    public function test_round128_counterfeit_journal_guards_reject_approval_and_allocation(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        \App\Models\Tariff::create(['name' => 'Synthetic journal guard tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-ROUND128-GUARD']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $invoice = app(\App\Services\InvoiceService::class)->generateInvoice($subscriber, $meter);
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 20);
        $credit = DB::table('treasury_held_cash_credits')->sole();
        $accountId = (int) $account['id'];
        $service = app(\App\Services\HeldCashReleaseProposalService::class);
        $protection = app(\App\Services\HeldCashReleaseDocumentProtection::class);
        $proposal = '66666666-6666-4666-8666-666666666666';
        $service->propose($this->actor(), $accountId, (int) $credit->id, 10, $proposal,
            'QA-ROUND128-PROPOSAL', 'Independent review of current allocation evidence', $this->nowAt());
        $reviewer = $this->actor();
        $approve = fn () => $service->recordRelease($reviewer, $accountId, $proposal,
            '77777777-7777-4777-8777-777777777777', 'Independent approval of shadow release', $this->nowAt());
        $sqlite = DB::getDriverName() === 'sqlite';
        $body = $sqlite ? "BEGIN SELECT RAISE(ABORT, 'Held release journal is immutable'); END"
            : "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Held release journal is immutable'";
        $noop = $sqlite ? "BEGIN SELECT 'Held release journal is immutable'; END"
            : "FOR EACH ROW SET @qa_journal_noop = 'Held release journal is immutable'";
        $this->assertTrue($protection->journalActive());
        $auditCount = AuditEvent::query()->count();
        DB::unprepared('DROP TRIGGER release_journal_reject_update');
        try {
            DB::unprepared("CREATE TRIGGER release_journal_reject_update BEFORE UPDATE ON held_cash_release_journal {$noop}");
            $this->assertFalse($protection->journalActive());
            $this->rejects('held_release_protection_missing', $approve);
            $this->assertDatabaseCount('held_cash_release_journal', 0);
            $this->assertSame($auditCount, AuditEvent::query()->count());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS release_journal_reject_update');
            DB::unprepared("CREATE TRIGGER release_journal_reject_update BEFORE UPDATE ON held_cash_release_journal {$body}");
        }
        $this->assertTrue($protection->journalActive());
        $approve();
        $beforeInvoice = $invoice->fresh()->getRawOriginal();
        $revisionCount = DB::table('payment_allocation_revisions')->count();
        $auditCount = AuditEvent::query()->count();
        $transactionCount = DB::table('transactions')->count();
        $cancel = fn () => app(\App\Services\InvoiceCancellationService::class)->cancel(
            $invoice->fresh(), 'Synthetic independently documented cancellation', $reviewer->id);
        DB::unprepared('DROP TRIGGER release_journal_reject_delete');
        try {
            DB::unprepared("CREATE TRIGGER release_journal_reject_delete BEFORE DELETE ON held_cash_release_journal {$noop}");
            $this->assertFalse($protection->journalActive());
            $this->rejects('held_release_protection_missing', fn () => $service->inspectJournal($accountId, $this->nowAt()));
            $this->rejects('held_release_protection_missing', $cancel);
            $this->assertSame($beforeInvoice, $invoice->fresh()->getRawOriginal());
            $this->assertSame($revisionCount, DB::table('payment_allocation_revisions')->count());
            $this->assertSame($auditCount, AuditEvent::query()->count());
            $this->assertSame($transactionCount, DB::table('transactions')->count());
            $this->assertDatabaseCount('held_cash_shadow_reinstatements', 0);
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS release_journal_reject_delete');
            DB::unprepared("CREATE TRIGGER release_journal_reject_delete BEFORE DELETE ON held_cash_release_journal {$body}");
        }
        if ($sqlite) {
            DB::unprepared('DROP TRIGGER release_journal_reject_replace');
            try {
                // A guard with the expected error but an incomplete unique-key predicate.
                DB::unprepared("CREATE TRIGGER release_journal_reject_replace BEFORE INSERT ON held_cash_release_journal
                    FOR EACH ROW WHEN EXISTS (SELECT 1 FROM held_cash_release_journal j WHERE j.id = NEW.id) {$body}");
                $this->assertFalse($protection->journalActive());
                $this->rejects('held_release_protection_missing', $cancel);
                $this->assertDatabaseCount('held_cash_shadow_reinstatements', 0);
            } finally {
                DB::unprepared('DROP TRIGGER IF EXISTS release_journal_reject_replace');
                DB::unprepared("CREATE TRIGGER release_journal_reject_replace BEFORE INSERT ON held_cash_release_journal
                    FOR EACH ROW WHEN EXISTS (SELECT 1 FROM held_cash_release_journal j WHERE j.id = NEW.id
                    OR j.operation_uuid = NEW.operation_uuid OR j.proposal_uuid = NEW.proposal_uuid) {$body}");
            }
        }
        $this->assertTrue($protection->journalActive());
        $cancel();
        $this->assertSame('cancelled', $invoice->fresh()->status);
        $event = DB::table('held_cash_shadow_reinstatements')->sole();
        $this->assertSame(10, (int) $event->before_minor);
        $this->assertSame(0, (int) $event->after_minor);
        $journal = $service->inspectJournal($accountId, $this->nowAt());
        $this->assertSame(0, $journal['shadow_released_minor']);
        $this->assertSame(25, $journal['actual_protected_minor']);
        $this->assertFalse($journal['spending_release_authorized']);
        $this->assertDatabaseCount('held_cash_release_journal', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
    }

    public function test_round129_proposal_and_withdrawal_guards_reject_counterfeit_definitions(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        \App\Models\Tariff::create(['name' => 'Synthetic proposal guard tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-ROUND129-GUARD']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        app(\App\Services\InvoiceService::class)->generateInvoice($subscriber, $meter);
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 20);
        $credit = DB::table('treasury_held_cash_credits')->sole();
        $accountId = (int) $account['id'];
        $service = app(\App\Services\HeldCashReleaseProposalService::class);
        $protection = app(\App\Services\HeldCashReleaseDocumentProtection::class);
        $actor = $this->actor();
        $reviewer = $this->actor();
        $uuid = '66666666-6666-4666-8666-666666666666';
        $propose = fn () => $service->propose($actor, $accountId, (int) $credit->id, 10, $uuid,
            'QA-ROUND129-PROPOSAL', 'Independent review of current allocation evidence', $this->nowAt());
        $withdraw = fn () => $service->withdraw($reviewer, $accountId, $uuid,
            'Independent withdrawal of proposed release', $this->nowAt());
        $inspect = fn () => $service->inspect($accountId, $this->nowAt());
        $approve = fn () => $service->recordRelease($reviewer, $accountId, $uuid,
            '77777777-7777-4777-8777-777777777777', 'Independent approval of shadow release', $this->nowAt());
        $sqlite = DB::getDriverName() === 'sqlite';
        $noop = $sqlite ? "BEGIN SELECT 'Held release proposal is immutable'; END"
            : "FOR EACH ROW SET @qa_proposal_noop = 'Held release proposal is immutable'";
        $this->assertTrue($protection->active());
        $auditCount = AuditEvent::query()->count();
        DB::unprepared('DROP TRIGGER release_plan_reject_update');
        try {
            DB::unprepared("CREATE TRIGGER release_plan_reject_update BEFORE UPDATE ON held_cash_release_proposals {$noop}");
            $this->assertFalse($protection->active());
            $this->rejects('held_release_protection_missing', $propose);
            $this->rejects('held_release_protection_missing', $inspect);
            $this->assertDatabaseCount('held_cash_release_proposals', 0);
            $this->assertSame($auditCount, AuditEvent::query()->count());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS release_plan_reject_update');
            $protection->install();
        }
        $this->assertTrue($propose()['created']);
        $before = (array) DB::table('held_cash_release_proposals')->sole();
        $auditCount = AuditEvent::query()->count();
        DB::unprepared('DROP TRIGGER release_withdraw_reject_delete');
        try {
            DB::unprepared("CREATE TRIGGER release_withdraw_reject_delete BEFORE DELETE ON held_cash_release_withdrawals {$noop}");
            $this->assertFalse($protection->active());
            $this->rejects('held_release_protection_missing', $withdraw);
            $this->rejects('held_release_protection_missing', $approve);
            $this->rejects('held_release_protection_missing', $inspect);
            $this->assertDatabaseCount('held_cash_release_withdrawals', 0);
            $this->assertDatabaseCount('held_cash_release_journal', 0);
            $this->assertSame($auditCount, AuditEvent::query()->count());
            $this->assertSame($before, (array) DB::table('held_cash_release_proposals')->sole());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS release_withdraw_reject_delete');
            $protection->install();
        }
        if ($sqlite) {
            DB::unprepared('DROP TRIGGER release_plan_reject_replace');
            try {
                DB::unprepared("CREATE TRIGGER release_plan_reject_replace BEFORE INSERT ON held_cash_release_proposals FOR EACH ROW
                    WHEN EXISTS (SELECT 1 FROM held_cash_release_proposals d WHERE d.operation_uuid = NEW.operation_uuid)
                    BEGIN SELECT RAISE(ABORT, 'Held release proposal is immutable'); END");
                $this->assertFalse($protection->active());
                $this->rejects('held_release_protection_missing', $withdraw);
                $this->assertDatabaseCount('held_cash_release_withdrawals', 0);
            } finally {
                DB::unprepared('DROP TRIGGER IF EXISTS release_plan_reject_replace');
                $protection->install();
            }
        }
        $this->assertTrue($protection->active());
        $this->assertTrue($withdraw()['created']);
        $this->assertFalse($withdraw()['created']);
        $this->assertSame(1, $inspect()['withdrawn']);
        $this->rejects('held_release_journal_proposal', $approve);
        $this->assertDatabaseCount('held_cash_release_proposals', 1);
        $this->assertDatabaseCount('held_cash_release_withdrawals', 1);
        $this->assertDatabaseCount('held_cash_release_journal', 0);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount($accountId)));
    }

    public function test_round130_transfer_destination_failure_rolls_back_both_accounts_and_held_evidence(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        \App\Models\Tariff::create(['name' => 'Synthetic transfer atomicity tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-ROUND130-TRANSFER']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $invoice = app(\App\Services\InvoiceService::class)->generateInvoice($subscriber, $meter);
        $receipt = app(PaymentService::class)->collectPayment($subscriber, '0.05', paymentDate: '2026-11-25');
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 20);
        $credit = DB::table('treasury_held_cash_credits')->sole();
        $accountId = (int) $account['id'];
        $release = app(\App\Services\HeldCashReleaseProposalService::class);
        $proposal = '66666666-6666-4666-8666-666666666666';
        $release->propose($this->actor(), $accountId, (int) $credit->id, 5, $proposal,
            'QA-ROUND130-PROPOSAL', 'Independent review of current allocation evidence', $this->nowAt());
        $release->recordRelease($this->actor(), $accountId, $proposal, '77777777-7777-4777-8777-777777777777',
            'Independent approval of shadow release', $this->nowAt());
        $destination = Subscriber::create(['name' => 'Synthetic transfer destination',
            'square_id' => $subscriber->square_id, 'status' => 'active']);
        $actor = $this->actor();
        $ledger = app(\App\Services\TransactionService::class);
        $inspect = fn () => $this->gate(fn () => app(\App\Services\HeldCashCreditAllocationEvidence::class)->inspectAccount($accountId));
        $move = fn () => app(\App\Services\PaymentAccountTransferService::class)->transfer($receipt,
            (int) $destination->id, $actor, 'Synthetic independently documented receipt transfer', 'Independent evidence of correct subscriber');
        $this->assertSame(5, $inspect()['applied_minor']);
        $this->assertSame(15, $ledger->accountBalanceMinor($subscriber));
        $this->assertSame(0, $ledger->accountBalanceMinor($destination));
        $beforeInvoice = $invoice->fresh()->getRawOriginal();
        $snapshot = fn (string $table) => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $tables = ['payment_allocation_revisions', 'payment_allocations', 'transactions',
            'held_cash_release_journal', 'held_cash_shadow_reinstatements', 'treasury_postings', 'audit_events'];
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        $destinationId = (int) $destination->id;
        $body = DB::getDriverName() === 'sqlite'
            ? "WHEN NEW.subscriber_id = {$destinationId} BEGIN SELECT RAISE(ABORT, 'qa_destination_revision_failure'); END"
            : "FOR EACH ROW BEGIN IF NEW.subscriber_id = {$destinationId} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'qa_destination_revision_failure'; END IF; END";
        DB::unprepared("CREATE TRIGGER qa_round130_destination_failure BEFORE INSERT ON payment_allocation_revisions {$body}");
        try {
            try {
                $move();
                $this->fail('Transfer unexpectedly succeeded despite destination revision failure.');
            } catch (\Illuminate\Database\QueryException $error) {
                $this->assertStringContainsString('qa_destination_revision_failure', $error->getMessage());
            }
            foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
            $this->assertSame($beforeInvoice, $invoice->fresh()->getRawOriginal());
            $this->assertDatabaseCount('payment_account_transfers', 0);
            $this->assertSame(15, $ledger->accountBalanceMinor($subscriber));
            $this->assertSame(0, $ledger->accountBalanceMinor($destination));
            $this->assertSame(5, $release->inspectJournal($accountId, $this->nowAt())['shadow_released_minor']);
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS qa_round130_destination_failure');
        }
        $transfer = $move();
        $this->assertSame(10, $ledger->accountBalanceMinor($subscriber));
        $this->assertSame(5, $ledger->accountBalanceMinor($destination));
        $this->assertSame(10, $inspect()['applied_minor']);
        $this->assertSame('0.10', $invoice->fresh()->amount_paid);
        $journal = $release->inspectJournal($accountId, $this->nowAt());
        $this->assertSame(5, $journal['shadow_released_minor']);
        $this->assertSame(0, $journal['reinstatement_due_minor']);
        $this->assertSame(25, $journal['actual_protected_minor']);
        $this->assertFalse($journal['spending_release_authorized']);
        $this->assertSame($before['treasury_postings'], $snapshot('treasury_postings'));
        $this->assertDatabaseCount('held_cash_shadow_reinstatements', 0);
        $this->assertDatabaseCount('payment_account_transfers', 1);
        $this->assertTrue(app(\App\Services\FinancialReconciliationService::class)->inspect($subscriber)['matches']);
        $this->assertTrue(app(\App\Services\FinancialReconciliationService::class)->inspect($destination)['matches']);
        $revisionCount = DB::table('payment_allocation_revisions')->count();
        $transactionCount = DB::table('transactions')->count();
        $auditCount = AuditEvent::query()->count();
        $this->assertSame($transfer->id, $move()->id);
        $this->assertSame($revisionCount, DB::table('payment_allocation_revisions')->count());
        $this->assertSame($transactionCount, DB::table('transactions')->count());
        $this->assertSame($auditCount, AuditEvent::query()->count());
        $this->assertDatabaseCount('payment_account_transfers', 1);
    }

    public function test_round131_receipt_ledger_failure_rolls_back_sequence_and_shadow_reinstatement(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        \App\Models\Tariff::create(['name' => 'Synthetic receipt atomicity tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-ROUND131-RECEIPT']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $invoice = app(\App\Services\InvoiceService::class)->generateInvoice($subscriber, $meter);
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 20);
        $credit = DB::table('treasury_held_cash_credits')->sole();
        $accountId = (int) $account['id'];
        $release = app(\App\Services\HeldCashReleaseProposalService::class);
        $proposal = '66666666-6666-4666-8666-666666666666';
        $release->propose($this->actor(), $accountId, (int) $credit->id, 10, $proposal,
            'QA-ROUND131-PROPOSAL', 'Independent review of current allocation evidence', $this->nowAt());
        $release->recordRelease($this->actor(), $accountId, $proposal, '77777777-7777-4777-8777-777777777777',
            'Independent approval of shadow release', $this->nowAt());
        $ledger = app(\App\Services\TransactionService::class);
        $payments = app(PaymentService::class);
        $sync = '88888888-8888-4888-8888-888888888888';
        $collect = fn () => $payments->collectPayment($subscriber, '0.30', paymentDate: '2026-11-25', syncUuid: $sync);
        $inspect = fn () => $this->gate(fn () => app(\App\Services\HeldCashCreditAllocationEvidence::class)->inspectAccount($accountId));
        $this->assertSame(10, $ledger->accountBalanceMinor($subscriber));
        $this->assertSame(10, $inspect()['applied_minor']);
        $this->assertSame(10, $release->inspectJournal($accountId, $this->nowAt())['shadow_released_minor']);
        $beforeInvoice = $invoice->fresh()->getRawOriginal();
        $snapshot = fn (string $table) => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $sequence = fn () => DB::table('document_sequences')->orderBy('kind')->orderBy('period')->get()->map(fn ($row) => (array) $row)->all();
        $tables = ['payments', 'transactions', 'payment_allocation_revisions', 'payment_allocations',
            'held_cash_release_journal', 'held_cash_shadow_reinstatements', 'treasury_postings', 'audit_events'];
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        $beforeSequence = $sequence();
        $body = DB::getDriverName() === 'sqlite'
            ? "WHEN NEW.type = 'payment' AND EXISTS (SELECT 1 FROM held_cash_shadow_reinstatements) BEGIN SELECT RAISE(ABORT, 'qa_receipt_ledger_after_shadow'); END"
            : "FOR EACH ROW BEGIN IF NEW.type = 'payment' AND EXISTS (SELECT 1 FROM held_cash_shadow_reinstatements) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'qa_receipt_ledger_after_shadow'; END IF; END";
        DB::unprepared("CREATE TRIGGER qa_round131_receipt_ledger BEFORE INSERT ON transactions {$body}");
        try {
            try {
                $collect();
                $this->fail('Receipt unexpectedly committed despite ledger failure after reinstatement.');
            } catch (\Illuminate\Database\QueryException $error) {
                $this->assertStringContainsString('qa_receipt_ledger_after_shadow', $error->getMessage());
            }
            foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
            $this->assertSame($beforeSequence, $sequence());
            $this->assertSame($beforeInvoice, $invoice->fresh()->getRawOriginal());
            $this->assertSame(10, $ledger->accountBalanceMinor($subscriber));
            $this->assertSame(10, $release->inspectJournal($accountId, $this->nowAt())['shadow_released_minor']);
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS qa_round131_receipt_ledger');
        }
        $receipt = $collect();
        $this->assertSame('REC-202611-0001', $receipt->receipt_number);
        $this->assertSame(40, $ledger->accountBalanceMinor($subscriber));
        $this->assertSame(0, $inspect()['applied_minor']);
        $this->assertSame('0.10', $invoice->fresh()->amount_paid);
        $event = DB::table('held_cash_shadow_reinstatements')->sole();
        $this->assertSame(10, (int) $event->before_minor);
        $this->assertSame(0, (int) $event->after_minor);
        $journal = $release->inspectJournal($accountId, $this->nowAt());
        $this->assertSame(0, $journal['shadow_released_minor']);
        $this->assertSame(0, $journal['reinstatement_due_minor']);
        $this->assertSame(25, $journal['actual_protected_minor']);
        $this->assertFalse($journal['spending_release_authorized']);
        $this->assertSame($before['treasury_postings'], $snapshot('treasury_postings'));
        $this->assertTrue(app(\App\Services\FinancialReconciliationService::class)->inspect($subscriber)['matches']);
        $committed = [];
        foreach ($tables as $table) $committed[$table] = $snapshot($table);
        $committedSequence = $sequence();
        $this->assertSame($receipt->id, $collect()->id);
        foreach ($committed as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $this->assertSame($committedSequence, $sequence());
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('held_cash_shadow_reinstatements', 1);
    }

    public function test_round132_noncash_final_audit_failure_rolls_back_both_ledgers_and_shadow_evidence(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        \App\Models\Tariff::create(['name' => 'Synthetic noncash atomicity tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-ROUND132-BANK']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $invoice = app(\App\Services\InvoiceService::class)->generateInvoice($subscriber, $meter);
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 20);
        $credit = DB::table('treasury_held_cash_credits')->sole();
        $accountId = (int) $account['id'];
        $release = app(\App\Services\HeldCashReleaseProposalService::class);
        $proposal = '66666666-6666-4666-8666-666666666666';
        $release->propose($this->actor(), $accountId, (int) $credit->id, 10, $proposal,
            'QA-ROUND132-PROPOSAL', 'Independent review of current allocation evidence', $this->nowAt());
        $release->recordRelease($this->actor(), $accountId, $proposal, '77777777-7777-4777-8777-777777777777',
            'Independent approval of shadow release', $this->nowAt());
        $issuer = $this->actor();
        $reviewer = $this->actor();
        $bank = app(TreasuryRegisterService::class)->open($issuer, [
            'code' => 'QA-R132-BANK', 'name' => 'Synthetic bank', 'channel' => 'bank', 'opening_on' => '2026-11-25',
            'opening_amount' => '0.00', 'opening_reference' => 'QA-R132-OPEN', 'opening_evidence' => 'Independent opening statement',
        ]);
        $bankService = app(\App\Services\BankReconciliationService::class);
        $statement = $bankService->import($issuer, (int) $bank['id'], [
            'reference' => 'QA-R132-STATEMENT', 'from_date' => '2026-11-25', 'to_date' => '2026-11-25',
            'opening_amount' => '0.00', 'closing_amount' => '0.30', 'evidence' => 'Independent actual bank statement',
            'lines' => [['bank_reference' => 'QA-R132-IN', 'booked_on' => '2026-11-25', 'amount' => '0.30']],
        ]);
        $statementId = (int) $statement['statement']['id'];
        $lineId = (int) DB::table('bank_statement_lines')->where('bank_statement_id', $statementId)->value('id');
        $claim = app(\App\Services\NonCashClaimService::class)->submit($issuer, [
            'sync_uuid' => '88888888-8888-4888-8888-888888888888', 'subscriber_id' => $subscriber->id,
            'amount' => '0.30', 'payment_method' => 'check', 'instrument_reference' => 'QA-R132-CHECK', 'instrument_on' => '2026-11-25',
        ]);
        $clear = fn () => app(PaymentService::class)->clearNonCashClaim($reviewer, (int) $claim['id'],
            $statementId, $lineId, 'QA-R132-PROOF', 'Independent verified bank collection evidence');
        $ledger = app(\App\Services\TransactionService::class);
        $this->assertSame(10, $ledger->accountBalanceMinor($subscriber));
        $this->assertSame(10, $release->inspectJournal($accountId, $this->nowAt())['shadow_released_minor']);
        $this->assertSame(30, $bankService->inspect($reviewer, $statementId)['bank_unmatched_minor']);
        $snapshot = fn (string $table) => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $sequence = fn () => DB::table('document_sequences')->orderBy('kind')->orderBy('period')->get()->map(fn ($row) => (array) $row)->all();
        $tables = ['payments', 'transactions', 'payment_allocation_revisions', 'payment_allocations',
            'held_cash_release_journal', 'held_cash_shadow_reinstatements', 'treasury_postings', 'audit_events',
            'noncash_collection_claims', 'treasury_bank_collections', 'bank_statement_matches', 'bank_statement_lines'];
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        $beforeSequence = $sequence();
        $beforeInvoice = $invoice->fresh()->getRawOriginal();
        $condition = "NEW.event = 'noncash_claim.cleared' AND EXISTS (SELECT 1 FROM held_cash_shadow_reinstatements)
            AND EXISTS (SELECT 1 FROM bank_statement_matches) AND EXISTS (SELECT 1 FROM treasury_bank_collections)";
        $body = DB::getDriverName() === 'sqlite'
            ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'qa_noncash_final_audit_after_both_ledgers'); END"
            : "FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'qa_noncash_final_audit_after_both_ledgers'; END IF; END";
        DB::unprepared("CREATE TRIGGER qa_round132_final_audit BEFORE INSERT ON audit_events {$body}");
        // AuditLogger intentionally logs the SQL error and throws a generic
        // required-audit exception without retaining the original exception.
        \Illuminate\Support\Facades\Log::spy();
        try {
            try {
                $clear();
                $this->fail('Noncash clearance unexpectedly committed despite required final audit failure.');
            } catch (\RuntimeException $error) {
                $this->assertSame('Financial operation requires a durable audit record.', $error->getMessage());
            }
            \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->with(
                'audit.record_failed', \Mockery::on(static fn ($context) =>
                    is_array($context) && ($context['event'] ?? null) === 'noncash_claim.cleared'
                    && str_contains((string) ($context['error'] ?? ''), 'qa_noncash_final_audit_after_both_ledgers'))
            )->once();
            foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
            $this->assertSame($beforeSequence, $sequence());
            $this->assertSame($beforeInvoice, $invoice->fresh()->getRawOriginal());
            $this->assertSame(10, $ledger->accountBalanceMinor($subscriber));
            $this->assertSame(10, $release->inspectJournal($accountId, $this->nowAt())['shadow_released_minor']);
            $this->assertSame(30, $bankService->inspect($reviewer, $statementId)['bank_unmatched_minor']);
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS qa_round132_final_audit');
        }
        $result = $clear();
        $this->assertSame('cleared', $result['claim']['status']);
        $this->assertSame(30, (int) $result['bank']['posting']['amount_minor']);
        $this->assertTrue($result['bank']['reconciliation']['fully_matched']);
        $this->assertSame(40, $ledger->accountBalanceMinor($subscriber));
        $this->assertSame('0.10', $invoice->fresh()->amount_paid);
        $this->assertSame('REC-202611-0001', DB::table('payments')->where('id', $result['payment_id'])->value('receipt_number'));
        $event = DB::table('held_cash_shadow_reinstatements')->sole();
        $this->assertSame(10, (int) $event->before_minor);
        $this->assertSame(0, (int) $event->after_minor);
        $journal = $release->inspectJournal($accountId, $this->nowAt());
        $this->assertSame(0, $journal['shadow_released_minor']);
        $this->assertSame(25, $journal['actual_protected_minor']);
        $this->assertFalse($journal['spending_release_authorized']);
        $this->assertSame(1, DB::table('treasury_postings')->where('treasury_account_id', $accountId)->count());
        $this->assertTrue(app(\App\Services\FinancialReconciliationService::class)->inspect($subscriber)['matches']);
        $committed = [];
        foreach ($tables as $table) $committed[$table] = $snapshot($table);
        $committedSequence = $sequence();
        $this->assertSame($result['payment_id'], $clear()['payment_id']);
        foreach ($committed as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $this->assertSame($committedSequence, $sequence());
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('treasury_bank_collections', 1);
        $this->assertDatabaseCount('bank_statement_matches', 1);
        $this->assertDatabaseCount('held_cash_shadow_reinstatements', 1);
    }

    public function test_round133_additional_proven_cash_loss_is_recorded_without_unlocking_reserve(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 20);
        $accountId = (int) $account['id'];
        $counter = $this->actor();
        $reviewer = $this->actor();
        $counts = app(TreasuryCashCountService::class);
        $writer = app(GatedCashCountAdjustmentWriter::class);
        $register = app(TreasuryRegisterService::class);
        $observe = function (string $amount, string $ref) use ($counts, $counter, $reviewer, $accountId): int {
            $count = $counts->record($counter, $accountId, $amount, $ref, self::EVIDENCE);
            $id = (int) $count['count']['id'];
            $counts->review($reviewer, $id, true, 'Independent review of actual physical cash count');
            return $id;
        };
        $first = $observe('0.24', 'QA-R133-COUNT-1');
        $writer->adjust($reviewer, $first, 'QA-R133-LOSS-1', 'confirmed_cash_loss', self::REASON, self::EVIDENCE,
            '88888888-8888-4888-8888-888888888888', $this->nowAt());
        $before = $register->inspect($reviewer, $accountId);
        $this->assertSame(1, $before['held_cash_possible_shortfall_minor']);
        $this->assertSame(25, $before['held_cash_total_protected_minor']);
        $second = $observe('0.20', 'QA-R133-COUNT-2');
        $post = fn () => $writer->adjust($reviewer, $second, 'QA-R133-LOSS-2', 'confirmed_cash_loss',
            self::REASON, self::EVIDENCE, '99999999-9999-4999-8999-999999999999', $this->nowAt());
        $result = $post();
        $this->assertTrue($result['registry_created']);
        $this->assertSame(-4, (int) $result['posting']['amount_minor']);
        $this->assertSame(20, $result['observation']['current_recorded_minor']);
        $after = $register->inspect($reviewer, $accountId);
        $this->assertSame(5, $after['held_cash_possible_shortfall_minor']);
        $this->assertSame(25, $after['held_cash_total_protected_minor']);
        $this->assertContains(['code' => 'held_cash_possible_liquidity_shortfall', 'amount_minor' => 5], $after['issues']);
        $this->assertFalse($post()['registry_created']);
        $this->assertSame(20, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        $this->assertDatabaseCount('treasury_cash_adjustments', 3);
        $this->assertDatabaseCount('treasury_postings', 3);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => '99999999-9999-4999-8999-999999999999',
            'source_type' => 'treasury_posting', 'source_id' => (string) $result['posting']['id'], 'amount_minor' => -4]);
        $this->rejects('held_cash_reserved', fn () => app(CashSessionService::class)->openFunded(
            $this->actor(), $reviewer, $accountId, '0.01', 'QA-R133-FUND', 'Independent physical funding evidence'));
        // A new unknown surplus is not allowed to masquerade as a recovery of protected cash.
        $third = $observe('0.21', 'QA-R133-COUNT-3');
        try {
            $writer->adjust($reviewer, $third, 'QA-R133-UNKNOWN', 'unattributed_cash_held', self::REASON, self::EVIDENCE,
                'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $this->nowAt());
            $this->fail('Unknown surplus was accepted during an existing reserve shortfall.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        // Missing source evidence must remain a hard stop, even during a genuine shortfall.
        $receipt = app(PaymentService::class)->collectPayment($subscriber, '1.00');
        app(\App\Services\PaymentRefundService::class)->record($receipt, $reviewer, '0.01', 'QA-R133-PENDING-REFUND',
            'treasury_cash', '2026-11-25', 'Synthetic refund of excess credit', 'Independent documented cash disbursement');
        $fourth = $observe('0.19', 'QA-R133-COUNT-4');
        try {
            $writer->adjust($reviewer, $fourth, 'QA-R133-BLOCKED-LOSS', 'confirmed_cash_loss', self::REASON, self::EVIDENCE,
                'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $this->nowAt());
            $this->fail('A pending refund was incorrectly treated as a verified loss.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertDatabaseCount('treasury_cash_adjustments', 3);
        $this->assertDatabaseCount('treasury_postings', 3);
        $final = $register->inspect($reviewer, $accountId);
        $this->assertSame(20, $final['provisional_recorded_balance_minor']);
        $this->assertSame(25, $final['held_cash_total_protected_minor']);
        $this->assertSame(1, $final['channel_unposted_cash_refund_minor']);
        $this->assertSame(6, $final['held_cash_possible_shortfall_minor']);
    }

    public function test_round133_late_cash_handover_failure_rolls_back_receipt_and_reserve_evidence(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        \App\Models\Tariff::create(['name' => 'Synthetic late cash tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-ROUND133-LATE']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $invoice = app(\App\Services\InvoiceService::class)->generateInvoice($subscriber, $meter);
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 20);
        $credit = DB::table('treasury_held_cash_credits')->sole();
        $accountId = (int) $account['id'];
        $release = app(\App\Services\HeldCashReleaseProposalService::class);
        $proposal = '66666666-6666-4666-8666-666666666666';
        $release->propose($this->actor(), $accountId, (int) $credit->id, 10, $proposal,
            'QA-ROUND133-PROPOSAL', 'Independent review of current allocation evidence', $this->nowAt());
        $release->recordRelease($this->actor(), $accountId, $proposal, '77777777-7777-4777-8777-777777777777',
            'Independent approval of shadow release', $this->nowAt());
        $collector = $this->actor();
        $reviewer = $this->actor();
        $sessions = app(CashSessionService::class);
        $session = $sessions->decide($sessions->close($sessions->open($collector), '0.00'), true, $reviewer);
        $late = app(\App\Services\LateReceiptReviewService::class);
        $claim = $late->submit($collector, [
            'sync_uuid' => '88888888-8888-4888-8888-888888888888', 'cash_session_id' => $session->id,
            'subscriber_id' => $subscriber->id, 'amount' => '0.30', 'payment_method' => 'cash', 'payment_date' => '2026-11-25',
        ]);
        $receive = fn () => $late->receiveCash((int) $claim['id'], $reviewer, $accountId, '2026-11-25',
            'QA-R133-LATE-HANDOVER', 'Independent physical delivery of delayed receipt');
        $ledger = app(\App\Services\TransactionService::class);
        $this->assertSame(10, $ledger->accountBalanceMinor($subscriber));
        $this->assertSame(10, $release->inspectJournal($accountId, $this->nowAt())['shadow_released_minor']);
        $snapshot = fn (string $table) => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $sequence = fn () => DB::table('document_sequences')->orderBy('kind')->orderBy('period')->get()->map(fn ($row) => (array) $row)->all();
        $tables = ['payments', 'transactions', 'payment_allocation_revisions', 'payment_allocations',
            'held_cash_release_journal', 'held_cash_shadow_reinstatements', 'treasury_postings', 'audit_events',
            'late_receipt_reviews', 'treasury_late_cash_receipts', 'cash_sessions'];
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        $beforeSequence = $sequence();
        $beforeInvoice = $invoice->fresh()->getRawOriginal();
        $condition = "NEW.event = 'treasury.late_cash_received' AND EXISTS (SELECT 1 FROM held_cash_shadow_reinstatements)
            AND EXISTS (SELECT 1 FROM treasury_late_cash_receipts) AND EXISTS (SELECT 1 FROM treasury_postings WHERE kind = 'late_cash_receipt')";
        $body = DB::getDriverName() === 'sqlite'
            ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'qa_late_cash_final_audit'); END"
            : "FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'qa_late_cash_final_audit'; END IF; END";
        DB::unprepared("CREATE TRIGGER qa_round133_late_audit BEFORE INSERT ON audit_events {$body}");
        \Illuminate\Support\Facades\Log::spy();
        try {
            try {
                $receive();
                $this->fail('Late cash unexpectedly committed despite required final audit failure.');
            } catch (\RuntimeException $error) {
                $this->assertSame('Financial operation requires a durable audit record.', $error->getMessage());
            }
            \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->with(
                'audit.record_failed', \Mockery::on(static fn ($context) =>
                    is_array($context) && ($context['event'] ?? null) === 'treasury.late_cash_received'
                    && str_contains((string) ($context['error'] ?? ''), 'qa_late_cash_final_audit'))
            )->once();
            foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
            $this->assertSame($beforeSequence, $sequence());
            $this->assertSame($beforeInvoice, $invoice->fresh()->getRawOriginal());
            $this->assertSame(10, $ledger->accountBalanceMinor($subscriber));
            $this->assertSame(10, $release->inspectJournal($accountId, $this->nowAt())['shadow_released_minor']);
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS qa_round133_late_audit');
        }
        $result = $receive();
        $this->assertSame('approved', $result['review']['status']);
        $this->assertSame(30, (int) $result['posting']['amount_minor']);
        $this->assertSame(40, $ledger->accountBalanceMinor($subscriber));
        $this->assertSame('0.10', $invoice->fresh()->amount_paid);
        $this->assertSame($before['cash_sessions'], $snapshot('cash_sessions'));
        $event = DB::table('held_cash_shadow_reinstatements')->sole();
        $this->assertSame(10, (int) $event->before_minor);
        $this->assertSame(0, (int) $event->after_minor);
        $this->assertTrue($late->checkCashPosting(DB::table('treasury_postings')->find($result['posting']['id'])));
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount($accountId)));
        $this->assertTrue(app(\App\Services\FinancialReconciliationService::class)->inspect($subscriber)['matches']);
        $committed = [];
        foreach ($tables as $table) $committed[$table] = $snapshot($table);
        $committedSequence = $sequence();
        $this->assertSame($result['posting']['id'], $receive()['posting']['id']);
        foreach ($committed as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $this->assertSame($committedSequence, $sequence());
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('treasury_late_cash_receipts', 1);
        $this->assertDatabaseCount('held_cash_shadow_reinstatements', 1);
    }

    private function round134Collection(string $kind, bool $legacy = false, bool $fence = false, bool $api = false, bool $bankFence = false): void
    {
        [, , $cash, , $lot, $subscriber] = $this->held();
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 20);
        $issuer = $this->actor();
        $reviewer = $this->actor();
        $writer = app(\App\Services\GatedCollectionWriter::class);
        $late = app(\App\Services\LateReceiptReviewService::class);
        $uuid = '88888888-8888-4888-8888-888888888888';
        $date = '2026-11-25';
        $session = null;
        if ($kind !== 'claim') {
            $sessions = app(CashSessionService::class);
            $session = $sessions->decide($sessions->close($sessions->open($issuer), '0.00'), true, $reviewer);
            $claim = $late->submit($issuer, ['sync_uuid' => $uuid, 'cash_session_id' => $session->id,
                'subscriber_id' => $subscriber->id, 'amount' => '0.30',
                'payment_method' => $kind === 'late_cash' ? 'cash' : 'check',
                'payment_date' => $date, 'reference_number' => $kind === 'late_cash' ? null : 'QA-R134-CHECK']);
        } else {
            $claim = app(\App\Services\NonCashClaimService::class)->submit($issuer, [
                'sync_uuid' => $uuid, 'subscriber_id' => $subscriber->id, 'amount' => '0.30',
                'payment_method' => 'check', 'instrument_reference' => 'QA-R134-CHECK', 'instrument_on' => $date,
            ]);
        }
        $id = (int) $claim['id'];
        if ($kind === 'late_cash') {
            $accountId = (int) $cash['id'];
            $act = fn () => $writer->receiveLateCash($reviewer, $id, $accountId, $date,
                'QA-R134-CASH', self::EVIDENCE, $uuid, $this->nowAt());
            $direct = fn (?\App\Services\GatedCollectionWriter $candidate = null) => $late->receiveCash($id, $reviewer, $accountId, $date, 'QA-R134-CASH', self::EVIDENCE, $candidate);
            $directIssue = fn (?\App\Services\GatedCollectionWriter $candidate = null) => app(PaymentService::class)->collectPayment($subscriber, '0.30', userId: $issuer->id, paymentDate: $date, syncUuid: $uuid, lateReviewId: $id, settlementWriter: $candidate);
            $this->rejects('gated_collection_effective_date', fn () => $writer->receiveLateCash($reviewer, $id,
                $accountId, '2026-11-24', 'QA-R134-CASH', self::EVIDENCE, $uuid, $this->nowAt()));
        } else {
            $bank = app(GatedTreasuryOpeningFundingWriter::class)->open($issuer, [
                'code' => 'QA-R134-BANK', 'name' => 'Synthetic bank', 'channel' => 'bank',
                'opening_on' => $date, 'opening_amount' => '0.00',
                'opening_reference' => 'QA-R134-OPEN', 'opening_evidence' => 'Independent opening bank evidence',
            ], '99999999-9999-4999-8999-999999999999', $this->nowAt())['account'];
            $accountId = (int) $bank['id'];
            $statement = app(\App\Services\BankReconciliationService::class)->import($issuer, $accountId, [
                'reference' => 'QA-R134-STMT', 'from_date' => $date, 'to_date' => $date,
                'opening_amount' => '0.00', 'closing_amount' => '0.30', 'evidence' => self::EVIDENCE,
                'lines' => [['bank_reference' => 'QA-R134-IN', 'booked_on' => $date, 'amount' => '0.30']],
            ]);
            $statementId = (int) $statement['statement']['id'];
            $lineId = (int) DB::table('bank_statement_lines')->where('bank_statement_id', $statementId)->value('id');
            $act = $kind === 'claim'
                ? fn () => $writer->clearClaim($reviewer, $id, $statementId, $lineId, $date,
                    'QA-R134-PROOF', self::EVIDENCE, $uuid, $this->nowAt())
                : fn () => $writer->clearLateBank($reviewer, $id, $statementId, $lineId, $date,
                    'QA-R134-REVIEW', 'QA-R134-PROOF', self::EVIDENCE, $uuid, $this->nowAt());
            $direct = $kind === 'claim'
                ? fn (?\App\Services\GatedCollectionWriter $candidate = null) => app(PaymentService::class)->clearNonCashClaim(
                    $reviewer, $id, $statementId, $lineId, 'QA-R134-PROOF', self::EVIDENCE, $candidate)
                : fn (?\App\Services\GatedCollectionWriter $candidate = null) => app(PaymentService::class)->clearLateNonCash(
                    $reviewer, $id, $statementId, $lineId, 'QA-R134-REVIEW', 'QA-R134-PROOF', self::EVIDENCE, $candidate);
            if ($legacy) {
                app(PaymentService::class)->clearNonCashClaim($reviewer, $id, $statementId, $lineId, 'QA-R134-PROOF', self::EVIDENCE);
                $this->rejects('gated_collection_operation_conflict', $act);
                $this->assertDatabaseMissing('financial_posting_operations', ['operation_uuid' => $uuid]);
                $this->assertDatabaseCount('payments', 1);
                $this->assertDatabaseCount('treasury_bank_collections', 1);
                return;
            }
        }
        $ledger = app(\App\Services\TransactionService::class);
        $this->assertSame(20, $ledger->accountBalanceMinor($subscriber));
        $tables = ['payments', 'transactions', 'payment_allocation_revisions', 'payment_allocations',
            'treasury_postings', 'audit_events', 'noncash_collection_claims', 'late_receipt_reviews',
            'treasury_bank_collections', 'bank_statement_matches', 'treasury_late_cash_receipts',
            'cash_sessions', 'financial_posting_events'];
        $snapshot = fn (string $table) => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $ops = fn () => DB::table('financial_posting_operations')->orderBy('operation_uuid')->get()->map(fn ($row) => (array) $row)->all();
        $seq = fn () => DB::table('document_sequences')->orderBy('kind')->orderBy('period')->get()->map(fn ($row) => (array) $row)->all();
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        $beforeOps = $ops();
        $beforeSeq = $seq();
        if ($bankFence) {
            config(['financial_candidate.noncash_settlements' => true]);
            $result = $act();
            $this->assertTrue($result['registry_created']);
            $paymentId = (int) ($kind === 'claim' ? $result['claim']['payment_id'] : $result['review']['payment_id']);
            $bankService = app(\App\Services\BankReconciliationService::class);
            $confirm = fn (?\App\Services\GatedCollectionWriter $candidate = null) => $bankService->confirmCollection(
                $reviewer, $statementId, $lineId, $paymentId, 'QA-R134-PROOF', self::EVIDENCE, $candidate);
            $url = '/api/treasury/bank-statements/'.$statementId.'/collections';
            $data = ['line_id' => $lineId, 'payment_id' => $paymentId, 'reference' => 'QA-R134-PROOF', 'evidence' => self::EVIDENCE];
            \Laravel\Sanctum\Sanctum::actingAs($reviewer);
            $committed = [];
            foreach ($tables as $table) $committed[$table] = $snapshot($table);
            $committedOps = $ops();
            $committedSeq = $seq();
            $this->rejects('gated_bank_collection_writer_required', $confirm);
            $this->rejects('gated_bank_collection_writer_required', fn () => DB::transaction(fn () => $confirm($writer)));
            $this->postJson($url, $data)->assertConflict();
            $this->assertFalse($act()['registry_created']);
            config(['financial_candidate.noncash_settlements' => false]);
            $this->rejects('gated_bank_collection_writer_required', $confirm);
            $this->postJson($url, $data)->assertConflict();
            $this->assertFalse($act()['registry_created']);
            foreach ($committed as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
            $this->assertSame($committedOps, $ops());
            $this->assertSame($committedSeq, $seq());
            $events = DB::table('financial_posting_events')->where('operation_uuid', $uuid)->orderBy('leg')->get();
            $this->assertSame(['subscriber', 'treasury'], $events->pluck('leg')->all());
            $this->assertSame([30, 30], $events->map(fn ($event) => (int) $event->amount_minor)->all());
            $this->assertSame(50, $ledger->accountBalanceMinor($subscriber));
            $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $cash['id'])));
            return;
        }
        if ($api) {
            $cashMode = $kind === 'late_cash';
            $switch = $cashMode ? 'financial_candidate.late_cash_settlements' : 'financial_candidate.noncash_settlements';
            $url = match ($kind) {
                'claim' => '/api/noncash-claims/'.$id.'/clear',
                'late_bank' => '/api/late-receipt-reviews/'.$id.'/clear-bank',
                'late_cash' => '/api/late-receipt-reviews/'.$id.'/clear-cash',
            };
            $data = $cashMode ? ['treasury_account_id' => $accountId, 'received_on' => $date,
                'evidence_reference' => 'QA-R134-CASH', 'evidence' => self::EVIDENCE]
                : ['statement_id' => $statementId, 'line_id' => $lineId, 'evidence' => self::EVIDENCE] +
                    ($kind === 'claim' ? ['reference' => 'QA-R134-PROOF']
                        : ['evidence_reference' => 'QA-R134-REVIEW', 'bank_clearance_reference' => 'QA-R134-PROOF']);
            config([$switch => true]);
            \Laravel\Sanctum\Sanctum::actingAs($issuer);
            $this->postJson($url, $data)->assertForbidden();
            \Laravel\Sanctum\Sanctum::actingAs($reviewer);
            DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'CLOSED']);
            $this->postJson($url, $data)->assertConflict();
            DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'OPEN']);
            foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
            $this->assertSame($beforeOps, $ops());
            $this->assertSame($beforeSeq, $seq());
            $key = $kind === 'claim' ? 'claim' : 'review';
            $first = $this->postJson($url, $data)->assertOk()->assertJsonPath($key.'.status', $kind === 'claim' ? 'cleared' : 'approved')->json($key.'.payment_id');
            $this->assertNotNull($first);
            $this->assertSame(50, $ledger->accountBalanceMinor($subscriber));
            $events = DB::table('financial_posting_events')->where('operation_uuid', $uuid)->orderBy('leg')->get();
            $this->assertSame(['subscriber', 'treasury'], $events->pluck('leg')->all());
            $this->assertSame([30, 30], $events->map(fn ($event) => (int) $event->amount_minor)->all());
            $committed = [];
            foreach ($tables as $table) $committed[$table] = $snapshot($table);
            $committedOps = $ops();
            $committedSeq = $seq();
            $this->postJson($url, $data)->assertOk()->assertJsonPath($key.'.payment_id', $first);
            $this->postJson($url, array_replace($data, ['evidence' => 'Different independent documented evidence']))->assertConflict();
            config([$switch => false]);
            $this->postJson($url, $data)->assertConflict();
            foreach ($committed as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
            $this->assertSame($committedOps, $ops());
            $this->assertSame($committedSeq, $seq());
            $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $cash['id'])));
            return;
        }
        if ($fence) {
            $cashFence = $kind === 'late_cash';
            $switch = $cashFence ? 'financial_candidate.late_cash_settlements' : 'financial_candidate.noncash_settlements';
            $reason = $cashFence ? 'gated_late_cash_writer_required' : 'gated_collection_writer_required';
            config([$switch => true]);
            $this->rejects($reason, $direct);
            $this->rejects($reason, fn () => DB::transaction(fn () => $direct($writer)));
            if ($cashFence) {
                $this->rejects($reason, $directIssue);
                $this->rejects($reason, fn () => DB::transaction(fn () => $directIssue($writer)));
                $this->rejects($reason, fn () => $late->decide($id, $reviewer, [
                    'approve' => true, 'settlement_method' => 'already_counted',
                    'evidence_reference' => 'QA-R134-COUNTED', 'evidence' => self::EVIDENCE,
                ]));
            }
            DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'CLOSED']);
            $this->rejects('financial_period_not_open', $act);
            DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'OPEN']);
            foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
            $this->assertSame($beforeOps, $ops());
            $this->assertSame($beforeSeq, $seq());
            $result = $act();
            $this->assertTrue($result['registry_created']);
            $this->assertSame(50, $ledger->accountBalanceMinor($subscriber));
            $this->assertFalse($writer->allowsMutation($kind, $id, $uuid));
            $events = DB::table('financial_posting_events')->where('operation_uuid', $uuid)->orderBy('leg')->get();
            $this->assertSame(['subscriber', 'treasury'], $events->pluck('leg')->all());
            $this->assertSame([30, 30], $events->map(fn ($event) => (int) $event->amount_minor)->all());
            $committed = [];
            foreach ($tables as $table) $committed[$table] = $snapshot($table);
            $committedOps = $ops();
            $committedSeq = $seq();
            config([$switch => false]);
            $this->rejects($reason, $direct);
            if ($cashFence) $this->rejects($reason, $directIssue);
            $this->assertFalse($act()['registry_created']);
            foreach ($committed as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
            $this->assertSame($committedOps, $ops());
            $this->assertSame($committedSeq, $seq());
            $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $cash['id'])));
            return;
        }
        DB::table('financial_scopes')->where('scope_key', 'water')->update(['active_freeze_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']);
        $this->rejects('financial_scope_not_active', $act);
        DB::table('financial_scopes')->where('scope_key', 'water')->update(['active_freeze_uuid' => null]);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'CLOSED']);
        $this->rejects('financial_period_not_open', $act);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'OPEN']);
        $condition = "NEW.operation_uuid = '{$uuid}' AND NEW.leg = 'treasury'";
        $body = DB::getDriverName() === 'sqlite'
            ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'qa_r134_registry_failure'); END"
            : "FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'qa_r134_registry_failure'; END IF; END";
        DB::unprepared("CREATE TRIGGER qa_r134_registry BEFORE INSERT ON financial_posting_events {$body}");
        try {
            try {
                $act();
                $this->fail('Collection committed despite failure to register treasury leg.');
            } catch (\Illuminate\Database\QueryException $error) {
                $this->assertStringContainsString('qa_r134_registry_failure', $error->getMessage());
            }
            foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
            $this->assertSame($beforeOps, $ops());
            $this->assertSame($beforeSeq, $seq());
            $this->assertSame(20, $ledger->accountBalanceMinor($subscriber));
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS qa_r134_registry');
        }
        $result = $act();
        $this->assertTrue($result['registry_created']);
        $this->assertSame(50, $ledger->accountBalanceMinor($subscriber));
        $events = DB::table('financial_posting_events')->where('operation_uuid', $uuid)->orderBy('leg')->get();
        $this->assertCount(2, $events);
        $this->assertSame(['subscriber', 'treasury'], $events->pluck('leg')->all());
        $this->assertSame([30, 30], $events->map(fn ($event) => (int) $event->amount_minor)->all());
        $this->assertDatabaseHas('financial_posting_operations', ['operation_uuid' => $uuid, 'effective_on' => $date]);
        $verified = $this->gate(fn (array $context) => app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']));
        $this->assertFalse($verified['source_coverage_verified']);
        $this->assertSame(2, $verified['registered_treasury_legs_verified']);
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $cash['id'])));
        $this->assertSame($before['cash_sessions'], $snapshot('cash_sessions'));
        $committed = [];
        foreach ($tables as $table) $committed[$table] = $snapshot($table);
        $committedOps = $ops();
        $committedSeq = $seq();
        $this->assertFalse($act()['registry_created']);
        foreach ($committed as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $this->assertSame($committedOps, $ops());
        $this->assertSame($committedSeq, $seq());
    }

    public function test_release_bank_confirmation_fence_claim(): void
    {
        $this->round134Collection('claim', bankFence: true);
    }

    public function test_release_bank_confirmation_fence_late_bank(): void
    {
        $this->round134Collection('late_bank', bankFence: true);
    }

    public function test_release_settlement_api_claim(): void
    {
        $this->round134Collection('claim', api: true);
    }

    public function test_release_settlement_api_late_bank(): void
    {
        $this->round134Collection('late_bank', api: true);
    }

    public function test_release_settlement_api_late_cash(): void
    {
        $this->round134Collection('late_cash', api: true);
    }

    public function test_release_late_cash_fence_separate_handover(): void
    {
        $this->round134Collection('late_cash', false, true);
    }

    public function test_release_noncash_fence_claim(): void
    {
        $this->round134Collection('claim', false, true);
    }

    public function test_release_noncash_fence_late_bank(): void
    {
        $this->round134Collection('late_bank', false, true);
    }

    public function test_round134_noncash_claim_registers_both_legs_atomically(): void
    {
        $this->round134Collection('claim');
    }

    public function test_round134_late_bank_registers_both_legs_atomically(): void
    {
        $this->round134Collection('late_bank');
    }

    public function test_round134_late_cash_registers_both_legs_atomically(): void
    {
        $this->round134Collection('late_cash');
    }

    public function test_round134_never_adopts_unregistered_legacy_collection(): void
    {
        $this->round134Collection('claim', true);
    }

    public function test_release_reading_auto_invoice_filament_resumes_original_after_commit(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $actor = $this->actor();
        foreach (['Create:Reading', 'ViewAny:Reading', 'View:Reading', 'Create:Invoice'] as $permission) {
            $actor->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'));
        }
        \App\Models\Tariff::create(['name' => 'Synthetic web auto invoice tariff', 'unit_price' => '0.10',
            'start_date' => '2026-11-01', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-WEB-AUTO',
            'installed_at' => '2026-11-01', 'initial_reading' => '0.00']);
        config(['financial_candidate.invoices' => true]);
        \App\Models\Setting::set('auto_issue_invoice_on_reading', '1');
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        $this->actingAs($actor, 'web');
        $page = \App\Filament\Resources\ReadingResource\Pages\ListReadings::class;
        $data = ['subscriber_id' => $subscriber->id, 'meter_id' => $meter->id,
            'reading_date' => '2026-11-24', 'period_start' => '2026-11-01', 'period_end' => '2026-11-24',
            'previous_reading' => '0.00', 'current_reading' => '1.00'];
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'CLOSED']);
        \Livewire\Livewire::test($page)->callAction('create', data: $data)->assertHasNoActionErrors();
        $reading = \App\Models\Reading::where('meter_id', $meter->id)->sole();
        $audit = AuditEvent::where('event', 'financial.reading_auto_invoice_intent')->where('entity_id', (string) $reading->id)->sole();
        $uuid = $audit->after['operation_uuid'];
        $this->assertSame((int) $actor->id, (int) $reading->created_by);
        $this->assertSame((int) $actor->id, (int) $audit->actor_user_id);
        $this->assertTrue($audit->after['eligible']);
        $this->assertSame([(int) $reading->id], array_column($audit->after['pending'], 0));
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseMissing('financial_posting_operations', ['operation_uuid' => $uuid]);
        $resume = \Filament\Actions\Testing\TestAction::make('resume_auto_invoice')->table($reading);
        \Livewire\Livewire::test($page)->callAction($resume)->assertNotified('تعذر استئناف إصدار الفاتورة');
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseMissing('financial_posting_operations', ['operation_uuid' => $uuid]);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'OPEN']);
        \App\Models\Setting::set('auto_issue_invoice_on_reading', '0');
        \Livewire\Livewire::test($page)->callAction($resume)->assertNotified('تعذر استئناف إصدار الفاتورة');
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseMissing('financial_posting_operations', ['operation_uuid' => $uuid]);
        $this->assertDatabaseCount('invoices', 0);
        \App\Models\Setting::set('auto_issue_invoice_on_reading', '1');
        $other = $this->actor();
        foreach (['ViewAny:Reading', 'View:Reading', 'Create:Invoice'] as $permission) {
            $other->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($other, 'web');
        \Livewire\Livewire::test($page)->assertActionHidden($resume);
        $this->actingAs($actor, 'web');
        \Livewire\Livewire::test($page)->callAction($resume)->assertHasNoActionErrors();
        $invoice = \App\Models\Invoice::where('meter_id', $meter->id)->sole();
        $this->assertSame('0.10', $invoice->total_amount);
        $this->assertSame((int) $invoice->id, (int) $reading->fresh()->invoice_id);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $uuid, 'leg' => 'subscriber', 'amount_minor' => -10]);
        \Livewire\Livewire::test($page)->assertActionHidden($resume);
        $this->assertDatabaseCount('readings', 1);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertSame(1, DB::table('financial_posting_events')->where('operation_uuid', $uuid)->count());
        $this->assertSame(-10, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $account['id'])));
    }

    public function test_release_reading_auto_invoice_filament_issues_after_successful_observation_commit(): void
    {
        [, , , , , $subscriber] = $this->held();
        $actor = $this->actor();
        foreach (['Create:Reading', 'ViewAny:Reading', 'View:Reading', 'Create:Invoice'] as $permission) {
            $actor->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'));
        }
        \App\Models\Tariff::create(['name' => 'Synthetic web automatic tariff', 'unit_price' => '0.10',
            'start_date' => '2026-11-01', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-WEB-AUTO-SUCCESS',
            'installed_at' => '2026-11-01', 'initial_reading' => '0.00']);
        config(['financial_candidate.invoices' => true]);
        \App\Models\Setting::set('auto_issue_invoice_on_reading', '1');
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        $this->actingAs($actor, 'web');
        $page = \App\Filament\Resources\ReadingResource\Pages\ListReadings::class;
        $data = ['subscriber_id' => $subscriber->id, 'meter_id' => $meter->id,
            'reading_date' => '2026-11-24', 'period_start' => '2026-11-01', 'period_end' => '2026-11-24',
            'previous_reading' => '0.00', 'current_reading' => '1.00'];
        \Livewire\Livewire::test($page)->callAction('create', data: array_replace($data, ['current_reading' => '-1.00']))
            ->assertHasActionErrors();
        $this->assertDatabaseCount('readings', 0);
        $this->assertDatabaseMissing('audit_events', ['event' => 'financial.reading_auto_invoice_intent']);
        $this->assertDatabaseCount('invoices', 0);
        \Livewire\Livewire::test($page)->callAction('create', data: $data)->assertHasNoActionErrors();
        $reading = \App\Models\Reading::where('meter_id', $meter->id)->sole();
        $invoice = \App\Models\Invoice::where('meter_id', $meter->id)->sole();
        $audit = AuditEvent::where('event', 'financial.reading_auto_invoice_intent')->where('entity_id', (string) $reading->id)->sole();
        $this->assertSame((int) $invoice->id, (int) $reading->invoice_id);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $audit->after['operation_uuid'], 'leg' => 'subscriber', 'amount_minor' => -10]);
        $this->assertSame(-10, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_release_reading_auto_invoice_api_recovers_only_original_intent(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $actor = $this->actor();
        \App\Models\Tariff::create(['name' => 'Synthetic automatic invoice tariff', 'unit_price' => '0.10',
            'start_date' => '2026-11-01', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-AUTO-RECOVER',
            'installed_at' => '2026-11-01', 'initial_reading' => '0.00']);
        config(['financial_candidate.invoices' => true]);
        \App\Models\Setting::set('auto_issue_invoice_on_reading', '1');
        \Laravel\Sanctum\Sanctum::actingAs($actor);
        $data = ['meter_id' => $meter->id, 'reading_date' => '2026-11-24', 'current_reading' => '1.00',
            'sync_uuid' => '66666666-6666-4666-8666-666666666666', 'notes' => 'Original automatic invoice intent'];
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'CLOSED']);
        $response = $this->postJson('/api/readings', $data)->assertCreated()
            ->assertJsonPath('invoice_issued', false)->assertJsonPath('invoice_error', 'financial_period_not_open');
        $readingId = (int) $response->json('reading.id');
        $audit = AuditEvent::where('event', 'financial.reading_auto_invoice_intent')->where('entity_id', (string) $readingId)->sole();
        $uuid = $audit->after['operation_uuid'];
        $this->assertTrue($audit->after['eligible']);
        $this->assertSame([$readingId], array_column($audit->after['pending'], 0));
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseMissing('financial_posting_operations', ['operation_uuid' => $uuid]);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'OPEN']);
        $this->postJson('/api/readings', array_replace($data, ['notes' => 'Changed original intent']))
            ->assertOk()->assertJsonPath('invoice_issued', false)->assertJsonPath('invoice_error', 'gated_auto_invoice_intent_conflict');
        \App\Models\Setting::set('auto_issue_invoice_on_reading', '0');
        $this->postJson('/api/readings', $data)->assertOk()->assertJsonPath('invoice_issued', false)
            ->assertJsonPath('invoice_error', 'gated_auto_invoice_pending_changed_or_disabled');
        config(['financial_candidate.invoices' => false]);
        \App\Models\Setting::set('auto_issue_invoice_on_reading', '1');
        $this->postJson('/api/readings', $data)->assertOk()->assertJsonPath('invoice_issued', false)
            ->assertJsonPath('invoice_error', 'gated_invoice_entry_disabled');
        $this->assertDatabaseCount('invoices', 0);
        config(['financial_candidate.invoices' => true]);
        $this->postJson('/api/readings', $data)->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('invoice_issued', true);
        $invoice = \App\Models\Invoice::where('meter_id', $meter->id)->sole();
        $this->assertSame('0.10', $invoice->total_amount);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $uuid, 'leg' => 'subscriber', 'amount_minor' => -10]);
        $this->assertSame(-10, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        $this->postJson('/api/readings', $data)->assertOk()->assertJsonPath('invoice_issued', true);
        $this->assertDatabaseCount('readings', 1);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertSame(1, DB::table('financial_posting_events')->where('operation_uuid', $uuid)->count());
        $cancelUuid = '88888888-8888-4888-8888-888888888888';
        $this->postJson('/api/invoices/'.$invoice->id.'/cancel', ['reason' => 'Independent cancellation of auto invoice',
            'operation_uuid' => $cancelUuid])->assertOk();
        $this->postJson('/api/readings', $data)->assertOk()->assertJsonPath('invoice_issued', false);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertSame(0, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $account['id'])));
    }

    public function test_release_reading_auto_invoice_api_refuses_new_permission_or_changed_pending_set(): void
    {
        [, , , , , $subscriber] = $this->held();
        $actor = $this->actor();
        $reader = User::factory()->create(['role' => 'reader']);
        $reader->assignRole(Role::findOrCreate('reader', 'web'));
        \App\Models\Tariff::create(['name' => 'Synthetic automatic invoice tariff', 'unit_price' => '0.10',
            'start_date' => '2026-11-01', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-AUTO-INTENT',
            'installed_at' => '2026-11-01', 'initial_reading' => '0.00']);
        config(['financial_candidate.invoices' => true]);
        \App\Models\Setting::set('auto_issue_invoice_on_reading', '1');
        \Laravel\Sanctum\Sanctum::actingAs($reader);
        $data = ['meter_id' => $meter->id, 'reading_date' => '2026-11-22', 'current_reading' => '1.00',
            'sync_uuid' => '66666666-6666-4666-8666-666666666666'];
        $this->postJson('/api/readings', $data)->assertCreated()->assertJsonPath('invoice_issued', false)
            ->assertJsonPath('invoice_error', 'gated_auto_invoice_not_authorized_at_creation');
        $reader->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('Create:Invoice', 'web'));
        \Laravel\Sanctum\Sanctum::actingAs($reader->fresh());
        $this->postJson('/api/readings', $data)->assertOk()->assertJsonPath('invoice_issued', false)
            ->assertJsonPath('invoice_error', 'gated_auto_invoice_not_authorized_at_creation');
        \Laravel\Sanctum\Sanctum::actingAs($actor);
        $next = array_replace($data, ['reading_date' => '2026-11-23', 'current_reading' => '2.00',
            'sync_uuid' => '88888888-8888-4888-8888-888888888888']);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'CLOSED']);
        $this->postJson('/api/readings', $next)->assertCreated()->assertJsonPath('invoice_issued', false);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'OPEN']);
        \App\Models\Setting::set('auto_issue_invoice_on_reading', '0');
        $later = array_replace($next, ['reading_date' => '2026-11-24', 'current_reading' => '3.00',
            'sync_uuid' => '99999999-9999-4999-8999-999999999999']);
        $this->postJson('/api/readings', $later)->assertCreated()->assertJsonPath('invoice_issued', false);
        \App\Models\Setting::set('auto_issue_invoice_on_reading', '1');
        $this->postJson('/api/readings', $next)->assertOk()->assertJsonPath('invoice_issued', false)
            ->assertJsonPath('invoice_error', 'gated_auto_invoice_pending_changed_or_disabled');
        $this->postJson('/api/readings', $later)->assertOk()->assertJsonPath('invoice_issued', false);
        $this->assertDatabaseCount('readings', 3);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(0, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
    }

    public function test_release_reading_batch_invoice_api_preserves_partial_success_and_replay(): void
    {
        [, , $account, , , $first] = $this->held();
        $actor = $this->actor();
        $second = Subscriber::create(['name' => 'Synthetic second billing account', 'square_id' => $first->square_id, 'status' => 'active']);
        $third = Subscriber::create(['name' => 'Synthetic already billed account', 'square_id' => $first->square_id, 'status' => 'active']);
        \App\Models\Tariff::create(['name' => 'Synthetic bulk invoice tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meters = [];
        $ids = [];
        foreach ([$first, $second, $third] as $i => $subscriber) {
            $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-READ-BATCH-'.$i]);
            $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
                'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => $i + 1]);
            $meters[] = $meter;
            $ids[] = (int) \App\Models\Reading::where('meter_id', $meter->id)->sole()->id;
        }
        $writer = app(\App\Services\GatedInvoiceTransferWriter::class);
        $writer->issue($actor, (int) $third->id, (int) $meters[2]->id,
            '66666666-6666-4666-8666-666666666666', $this->nowAt());
        $batchUuid = '88888888-8888-4888-8888-888888888888';
        $data = ['ids' => $ids, 'notes' => 'Synthetic reading batch', 'operation_uuid' => $batchUuid];
        $url = '/api/readings/bulk-invoice';
        $alias = '/api/readings/bulk-issue-invoices';
        $tables = ['invoices', 'readings', 'invoice_items', 'invoice_tariff_lines', 'transactions', 'payments',
            'payment_allocation_revisions', 'payment_allocations', 'audit_events', 'document_sequences',
            'financial_posting_operations', 'financial_posting_events', 'treasury_postings'];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($r) => (array) $r)->all();
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        config(['financial_candidate.invoices' => true]);
        \Laravel\Sanctum\Sanctum::actingAs($actor);
        $this->postJson($url, array_diff_key($data, ['operation_uuid' => true]))->assertUnprocessable();
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'CLOSED']);
        $this->postJson($url, $data)->assertUnprocessable()->assertJsonPath('invoices_issued', 0);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'OPEN']);
        foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $response = $this->postJson($url, $data)->assertCreated()->assertJsonPath('invoices_issued', 2)
            ->assertJsonCount(1, 'errors')->assertJsonPath('errors.0.meter_id', $meters[2]->id);
        $this->assertDatabaseCount('invoices', 3);
        $invoiceIds = collect($response->json('invoices'))->pluck('id')->all();
        $committed = [];
        foreach ($tables as $table) $committed[$table] = $snapshot($table);
        $replay = $this->postJson($alias, array_replace($data, ['ids' => array_reverse($ids)]))
            ->assertCreated()->assertJsonPath('invoices_issued', 2)->assertJsonCount(1, 'errors');
        $this->assertSame($invoiceIds, collect($replay->json('invoices'))->pluck('id')->all());
        $this->postJson($alias, array_replace($data, ['notes' => 'Different batch request']))->assertUnprocessable()
            ->assertJsonPath('invoices_issued', 0);
        $this->postJson($url, array_replace($data, ['operation_uuid' => '99999999-9999-4999-8999-999999999999']))
            ->assertUnprocessable()->assertJsonPath('invoices_issued', 0);
        foreach ([$first, $second] as $i => $subscriber) {
            $uuid = \Ramsey\Uuid\Uuid::uuid5($batchUuid, 'water:reading-batch:meter:'.$meters[$i]->id)->toString();
            $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $uuid, 'leg' => 'subscriber',
                'amount_minor' => -10 * ($i + 1)]);
            $this->assertSame(1, DB::table('financial_posting_events')->where('operation_uuid', $uuid)->count());
            $this->rejects('gated_invoice_replay_conflict', fn () => $writer->issue($actor, (int) $subscriber->id,
                (int) $meters[$i]->id, $uuid, $this->nowAt(), $data['notes'], [$ids[2]]));
        }
        config(['financial_candidate.invoices' => false]);
        $this->postJson($url, $data)->assertConflict();
        foreach ($committed as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        foreach (['payments', 'treasury_postings'] as $table) $this->assertSame($before[$table], $snapshot($table), $table);
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $account['id'])));
    }

    public function test_release_reading_batch_invoice_filament_uses_same_meter_operation(): void
    {
        [, , , , , $subscriber] = $this->held();
        $actor = $this->actor();
        foreach (['Create:Invoice', 'ViewAny:Reading', 'View:Reading'] as $permission) {
            $actor->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'));
        }
        \App\Models\Tariff::create(['name' => 'Synthetic Filament bulk tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-FILAMENT-BULK']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $reading = \App\Models\Reading::where('meter_id', $meter->id)->sole();
        $batchUuid = '88888888-8888-4888-8888-888888888888';
        config(['financial_candidate.invoices' => true]);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        $this->actingAs($actor, 'web');
        $page = \App\Filament\Resources\ReadingResource\Pages\ListReadings::class;
        $action = \Filament\Actions\Testing\TestAction::make('issue_invoices')->table()->bulk();
        $modal = \Livewire\Livewire::test($page)->selectTableRecords([$reading->id])
            ->mountAction($action)->setActionData(['operation_uuid' => $batchUuid]);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'CLOSED']);
        $modal->callMountedAction()->assertHasActionErrors();
        $modal->assertActionDataSet(['operation_uuid' => $batchUuid]);
        $this->assertDatabaseCount('invoices', 0);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'OPEN']);
        $modal->callMountedAction()->assertHasNoActionErrors();
        $invoice = \App\Models\Invoice::where('meter_id', $meter->id)->sole();
        \Laravel\Sanctum\Sanctum::actingAs($actor);
        $this->postJson('/api/readings/bulk-invoice', ['ids' => [$reading->id], 'operation_uuid' => $batchUuid])
            ->assertCreated()->assertJsonPath('invoices.0.id', $invoice->id);
        $uuid = \Ramsey\Uuid\Uuid::uuid5($batchUuid, 'water:reading-batch:meter:'.$meter->id)->toString();
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $uuid, 'leg' => 'subscriber', 'amount_minor' => -10]);
        $this->assertSame(1, DB::table('financial_posting_events')->where('operation_uuid', $uuid)->count());
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_release_invoice_filament_create_and_modals_share_financial_identity(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $actor = $this->actor();
        foreach (['Create:Invoice', 'ViewAny:Invoice', 'View:Invoice', 'Delete:Invoice'] as $permission) {
            $actor->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'));
        }
        \App\Models\Tariff::create(['name' => 'Synthetic invoice Filament tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-INVOICE-FILAMENT']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        config(['financial_candidate.invoices' => true]);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        $this->actingAs($actor, 'web');
        $create = \App\Filament\Resources\InvoiceResource\Pages\CreateInvoice::class;
        $list = \App\Filament\Resources\InvoiceResource\Pages\ListInvoices::class;
        $component = \Livewire\Livewire::test($create);
        $uuid = $component->get('data.operation_uuid');
        $this->assertTrue(is_string($uuid) && \Illuminate\Support\Str::isUuid($uuid));
        $data = ['subscriber_id' => $subscriber->id, 'notes' => 'Synthetic protected Filament invoice', 'operation_uuid' => $uuid];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($r) => (array) $r)->all();
        $tables = ['invoices', 'readings', 'invoice_items', 'invoice_tariff_lines', 'transactions', 'payments',
            'payment_allocation_revisions', 'payment_allocations', 'audit_events', 'document_sequences',
            'financial_posting_operations', 'financial_posting_events', 'treasury_postings'];
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        $component->fillForm($data);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'CLOSED']);
        $component->call('create')->assertHasErrors();
        $this->assertSame($uuid, $component->get('data.operation_uuid'));
        foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'OPEN']);
        $component->call('create')->assertHasNoErrors();
        $invoice = \App\Models\Invoice::where('meter_id', $meter->id)->sole();
        $afterIssue = [];
        foreach ($tables as $table) $afterIssue[$table] = $snapshot($table);
        $header = \Filament\Actions\Testing\TestAction::make('generate_invoice')->table();
        \Livewire\Livewire::test($list)->callAction($header, data: $data + ['meter_id' => $meter->id])->assertHasNoActionErrors();
        foreach ($afterIssue as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $cancelUuid = '88888888-8888-4888-8888-888888888888';
        $cancelData = ['reason' => 'Independent prospective invoice cancellation', 'operation_uuid' => $cancelUuid];
        $action = \Filament\Actions\Testing\TestAction::make('cancel_invoice')->table($invoice);
        $modal = \Livewire\Livewire::test($list)->mountAction($action)->setActionData($cancelData);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'CLOSED']);
        $modal->callMountedAction()->assertHasActionErrors();
        $modal->assertActionDataSet(['operation_uuid' => $cancelUuid]);
        foreach ($afterIssue as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'OPEN']);
        $modal->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame('cancelled', $invoice->fresh()->status);
        $committed = [];
        foreach ($tables as $table) $committed[$table] = $snapshot($table);
        \Laravel\Sanctum\Sanctum::actingAs($actor);
        $this->postJson('/api/invoices/'.$invoice->id.'/cancel', $cancelData)->assertOk()
            ->assertJsonPath('invoice.id', $invoice->id);
        foreach ($committed as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $uuid, 'leg' => 'subscriber', 'amount_minor' => -10]);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $cancelUuid, 'leg' => 'subscriber', 'amount_minor' => 10]);
        $this->assertSame(1, DB::table('financial_posting_events')->where('operation_uuid', $uuid)->count());
        $this->assertSame(1, DB::table('financial_posting_events')->where('operation_uuid', $cancelUuid)->count());
        $this->assertSame(0, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        foreach (['payments', 'treasury_postings'] as $table) $this->assertSame($before[$table], $snapshot($table), $table);
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $account['id'])));
    }

    public function test_release_invoice_api_shares_issue_identity_and_cancellation_reversal(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $actor = $this->actor();
        \App\Models\Tariff::create(['name' => 'Synthetic invoice API tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-INVOICE-API']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $issueUuid = '66666666-6666-4666-8666-666666666666';
        $cancelUuid = '88888888-8888-4888-8888-888888888888';
        $data = ['subscriber_id' => $subscriber->id, 'meter_id' => $meter->id,
            'notes' => 'Synthetic protected API issuance', 'operation_uuid' => $issueUuid];
        $cancelData = ['reason' => 'Independent prospective invoice cancellation', 'operation_uuid' => $cancelUuid];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        $tables = ['invoices', 'readings', 'invoice_items', 'invoice_tariff_lines', 'transactions', 'payments',
            'payment_allocation_revisions', 'payment_allocations', 'audit_events', 'document_sequences',
            'financial_posting_operations', 'financial_posting_events', 'treasury_postings'];
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        config(['financial_candidate.invoices' => true]);
        $unauthorized = User::factory()->create(['role' => 'collector']);
        $unauthorized->assignRole(Role::findOrCreate('collector', 'web'));
        \Laravel\Sanctum\Sanctum::actingAs($unauthorized);
        $this->postJson('/api/invoices', $data)->assertForbidden();
        $this->postJson('/api/invoices/generate-meter', $data)->assertForbidden();
        \Laravel\Sanctum\Sanctum::actingAs($actor);
        $this->postJson('/api/invoices', array_diff_key($data, ['operation_uuid' => true]))->assertUnprocessable();
        $this->postJson('/api/invoices/generate-meter', array_diff_key($data, ['operation_uuid' => true]))->assertUnprocessable();
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'CLOSED']);
        $this->postJson('/api/invoices', $data)->assertConflict();
        $this->postJson('/api/invoices/generate-meter', $data)->assertConflict();
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'OPEN']);
        foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $issued = $this->postJson('/api/invoices', $data)->assertCreated();
        $id = (int) $issued->json('invoice.id');
        $this->assertGreaterThan(0, $id);
        $afterIssue = [];
        foreach ($tables as $table) $afterIssue[$table] = $snapshot($table);
        $this->postJson('/api/invoices/generate-meter', $data)->assertCreated()->assertJsonPath('invoice.id', $id);
        $this->postJson('/api/invoices/generate-meter', array_replace($data, ['notes' => 'Different issuance intent']))->assertConflict();
        \Laravel\Sanctum\Sanctum::actingAs($unauthorized);
        $this->postJson('/api/invoices/'.$id.'/cancel', $cancelData)->assertForbidden();
        \Laravel\Sanctum\Sanctum::actingAs($actor);
        $this->postJson('/api/invoices/'.$id.'/cancel', array_diff_key($cancelData, ['operation_uuid' => true]))->assertUnprocessable();
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'CLOSED']);
        $this->postJson('/api/invoices/'.$id.'/cancel', $cancelData)->assertConflict();
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'OPEN']);
        foreach ($afterIssue as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $this->postJson('/api/invoices/'.$id.'/cancel', $cancelData)->assertOk()->assertJsonPath('invoice.status', 'cancelled');
        $committed = [];
        foreach ($tables as $table) $committed[$table] = $snapshot($table);
        $this->postJson('/api/invoices/'.$id.'/cancel', $cancelData)->assertOk()->assertJsonPath('invoice.id', $id);
        $this->postJson('/api/invoices/'.$id.'/cancel', array_replace($cancelData, ['reason' => 'Different cancellation intent']))->assertConflict();
        config(['financial_candidate.invoices' => false]);
        $this->postJson('/api/invoices', $data)->assertConflict();
        $this->postJson('/api/invoices/'.$id.'/cancel', $cancelData)->assertConflict();
        $this->postJson('/api/invoices/'.$id.'/cancel', array_diff_key($cancelData, ['operation_uuid' => true]))->assertConflict();
        foreach ($committed as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $issueUuid, 'leg' => 'subscriber', 'amount_minor' => -10]);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $cancelUuid, 'leg' => 'subscriber', 'amount_minor' => 10]);
        $this->assertSame(1, DB::table('financial_posting_events')->where('operation_uuid', $issueUuid)->count());
        $this->assertSame(1, DB::table('financial_posting_events')->where('operation_uuid', $cancelUuid)->count());
        $this->assertSame(0, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        foreach (['payments', 'treasury_postings'] as $table) $this->assertSame($before[$table], $snapshot($table), $table);
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $account['id'])));
    }

    public function test_release_correction_model_fence_rejects_direct_refunds_transfers_and_ledger(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $other = Subscriber::create(['name' => 'Synthetic destination', 'square_id' => $subscriber->square_id, 'status' => 'active']);
        $issuer = $this->actor();
        $reviewer = $this->actor();
        $collect = fn (string $uuid) => app(GatedCashReceiptWriter::class)->collect($issuer, (int) $subscriber->id, '10.00', $uuid, $this->nowAt())['payment'];
        $receipt = $collect('66666666-6666-4666-8666-666666666666');
        $second = $collect('77777777-7777-4777-8777-777777777777');
        $refundWriter = app(\App\Services\GatedRefundWriter::class);
        $transferWriter = app(\App\Services\GatedInvoiceTransferWriter::class);
        $ledger = app(\App\Services\TransactionService::class);
        $reason = 'Independent documented correction';
        $refund = ['payment_id' => $receipt->id, 'subscriber_id' => $subscriber->id, 'recorded_by' => $reviewer->id,
            'amount' => '4.00', 'disbursement_reference' => 'QA-CORRECTION-FENCE', 'method' => 'treasury_cash',
            'disbursed_on' => '2026-11-25', 'reason' => $reason, 'evidence' => self::EVIDENCE];
        $transfer = ['payment_id' => $second->id, 'from_subscriber_id' => $subscriber->id,
            'to_subscriber_id' => $other->id, 'approved_by' => $reviewer->id, 'amount' => '10.00',
            'reason' => $reason, 'evidence' => self::EVIDENCE];
        $tables = ['payments', 'payment_refunds', 'payment_account_transfers', 'transactions', 'treasury_postings',
            'payment_allocations', 'payment_allocation_revisions', 'financial_posting_operations', 'financial_posting_events', 'audit_events'];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        $unchanged = function (string $error, callable $action) use ($tables, $snapshot): void {
            $before = [];
            foreach ($tables as $table) $before[$table] = $snapshot($table);
            $this->rejects($error, $action);
            foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        };
        foreach ([true, false] as $enabled) {
            config(['financial_candidate.refunds' => $enabled, 'financial_candidate.subscriber_transfers' => $enabled]);
            $unchanged('gated_refund_document_writer_required', fn () => \App\Models\PaymentRefund::create($refund));
            $unchanged('gated_refund_document_writer_required', fn () => DB::transaction(fn () =>
                (new \App\Models\PaymentRefund($refund))->saveQuietly(['financial_correction_writer' => $refundWriter])));
            $unchanged('gated_transfer_document_writer_required', fn () => \App\Models\PaymentAccountTransfer::create($transfer));
            $unchanged('gated_transfer_document_writer_required', fn () => DB::transaction(fn () =>
                (new \App\Models\PaymentAccountTransfer($transfer))->saveQuietly(['financial_correction_writer' => $transferWriter])));
        }
        $refundUuid = '88888888-8888-4888-8888-888888888888';
        $transferUuid = '99999999-9999-4999-8999-999999999999';
        $record = fn () => $refundWriter->record($reviewer, (int) $receipt->id, (int) $account['id'], '4.00',
            'QA-CORRECTION-FENCE', 'treasury_cash', '2026-11-25', $reason, self::EVIDENCE, $refundUuid, $this->nowAt());
        $move = fn () => $transferWriter->transfer($reviewer, (int) $second->id, (int) $other->id,
            $reason, self::EVIDENCE, $transferUuid, $this->nowAt());
        $r = $record();
        $t = $move();
        $this->assertTrue($r['registry_created']);
        $this->assertTrue($t['registry_created']);
        foreach ([true, false] as $enabled) {
            config(['financial_candidate.refunds' => $enabled, 'financial_candidate.subscriber_transfers' => $enabled]);
            foreach ([[$r['refund'], 'payment_refund', '-4.00', $subscriber, $refundWriter, 'refund'],
                [$t['transfer'], 'payment_transfer_in', '10.00', $other, $transferWriter, 'transfer']] as [$doc, $type, $amount, $owner, $writer, $kind]) {
                $data = ['subscriber_id' => $owner->id, 'type' => $type, 'amount' => $amount,
                    'reference_type' => get_class($doc), 'reference_id' => $doc->id];
                $entry = \App\Models\Transaction::where('reference_type', get_class($doc))->where('reference_id', $doc->id)->where('type', $type)->sole();
                $unchanged('gated_correction_ledger_writer_required', fn () => \App\Models\Transaction::create($data));
                $unchanged('gated_correction_ledger_writer_required', fn () => $ledger->recordTransaction($owner, $type, $amount, reference: $doc));
                $unchanged('gated_correction_ledger_writer_required', fn () => DB::transaction(fn () =>
                    $ledger->recordTransaction($owner, $type, $amount, reference: $doc, correctionWriter: $writer)));
                $unchanged('gated_correction_ledger_writer_required', fn () => $entry->fresh()->forceFill(['amount' => '1.00'])->saveQuietly());
                $unchanged('gated_'.$kind.'_document_writer_required', fn () => $doc->fresh()->forceFill(['amount' => '1.00'])->saveQuietly());
            }
        }
        $this->assertFalse($record()['registry_created']);
        $this->assertFalse($move()['registry_created']);
        $this->assertSame(600, $ledger->accountBalanceMinor($subscriber));
        $this->assertSame(1000, $ledger->accountBalanceMinor($other));
        $this->assertSame(2, DB::table('financial_posting_events')->where('operation_uuid', $refundUuid)->count());
        $this->assertSame(2, DB::table('financial_posting_events')->where('operation_uuid', $transferUuid)->count());
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $account['id'])));
    }

    public function test_release_receipt_mutation_fence_blocks_quiet_financial_and_void_field_changes(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $issuer = $this->actor();
        $reviewer = $this->actor();
        $session = app(CashSessionService::class)->open($issuer);
        $other = Subscriber::create(['name' => 'Synthetic other account', 'square_id' => $subscriber->square_id, 'status' => 'active']);
        config(['financial_candidate.cash_receipts' => true, 'financial_candidate.void_api' => true]);
        $receipt = app(GatedCashReceiptWriter::class)->collect($issuer, (int) $subscriber->id, '10.00',
            '66666666-6666-4666-8666-666666666666', $this->nowAt(), (int) $session->id)['payment'];
        $writer = app(\App\Services\GatedReceiptVoidWriter::class);
        $ledger = app(\App\Services\TransactionService::class);
        \App\Models\Setting::set('void_approval_threshold', '1.00');
        $reason = 'Independent documented receipt cancellation';
        $tables = ['payments', 'transactions', 'payment_allocations', 'payment_allocation_revisions',
            'financial_posting_operations', 'financial_posting_events', 'audit_events', 'treasury_postings'];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        $unchanged = function (callable $action) use ($tables, $snapshot): void {
            $before = [];
            foreach ($tables as $table) $before[$table] = $snapshot($table);
            $this->rejects('gated_receipt_document_mutation_required', $action);
            foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        };
        foreach ([true, false] as $enabled) {
            config(['financial_candidate.cash_receipts' => $enabled, 'financial_candidate.void_api' => $enabled]);
            foreach ([
                ['amount' => '20.00'], ['subscriber_id' => $other->id], ['cash_session_id' => null],
                ['sync_uuid' => '77777777-7777-4777-8777-777777777777'],
                ['voided_at' => now(), 'voided_via' => 'gated'],
                ['void_requested_at' => now(), 'void_requested_by' => $issuer->id, 'void_request_reason' => $reason],
                ['deleted_at' => now()],
            ] as $changes) {
                $unchanged(fn () => $receipt->fresh()->forceFill($changes)->saveQuietly());
            }
            $unchanged(fn () => $receipt->fresh()->forceFill(['amount' => '20.00'])->save());
            $unchanged(fn () => DB::transaction(fn () => $receipt->fresh()->forceFill(['voided_at' => now()])
                ->saveQuietly(['financial_receipt_void_writer' => $writer])));
        }
        $receipt->fresh()->forceFill(['notes' => 'Nonfinancial note'])->saveQuietly();
        $this->assertSame('Nonfinancial note', $receipt->fresh()->notes);
        $request = fn () => $writer->request($issuer, (int) $receipt->id, $reason, $this->nowAt());
        $first = $request()['request_id'];
        $this->assertTrue($receipt->fresh()->hasPendingVoidRequest());
        $unchanged(fn () => $receipt->fresh()->forceFill(['void_requested_at' => null])->saveQuietly());
        $unchanged(fn () => $receipt->fresh()->forceFill(['void_decided_by' => $reviewer->id])->saveQuietly());
        $writer->decide($reviewer, (int) $receipt->id, false, 'Independent rejection', $this->nowAt(), requestId: $first);
        $this->assertFalse($receipt->fresh()->hasPendingVoidRequest());
        $this->assertSame(1000, $ledger->accountBalanceMinor($subscriber));
        $second = $request()['request_id'];
        $uuid = '88888888-8888-4888-8888-888888888888';
        $approve = fn () => $writer->decide($reviewer, (int) $receipt->id, true, null, $this->nowAt(), $uuid, $second);
        $this->assertTrue($approve()['registry_created']);
        $this->assertFalse($approve()['registry_created']);
        $unchanged(fn () => $receipt->fresh()->forceFill(['voided_at' => null])->saveQuietly());
        $this->assertTrue($receipt->fresh()->isVoided());
        $this->assertSame(0, $ledger->accountBalanceMinor($subscriber));
        $this->assertSame(0, app(CashSessionService::class)->expectedAmountMinor($session->fresh()));
        $this->assertSame(1, DB::table('financial_posting_events')->where('operation_uuid', $uuid)->count());
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $account['id'])));
    }

    public function test_release_receipt_reversal_fence_blocks_direct_and_idle_writers(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $actor = $this->actor();
        $session = app(CashSessionService::class)->open($actor);
        $uuid = '66666666-6666-4666-8666-666666666666';
        $voidUuid = '88888888-8888-4888-8888-888888888888';
        config(['financial_candidate.cash_receipts' => true, 'financial_candidate.void_api' => true]);
        \App\Models\Setting::set('void_approval_threshold', '0.00');
        $payment = app(GatedCashReceiptWriter::class)->collect($actor, (int) $subscriber->id, '10.00',
            $uuid, $this->nowAt(), (int) $session->id)['payment'];
        $ledger = app(\App\Services\TransactionService::class);
        $void = app(\App\Services\GatedReceiptVoidWriter::class);
        $transfer = app(\App\Services\GatedInvoiceTransferWriter::class);
        $data = ['subscriber_id' => $subscriber->id, 'type' => 'payment_reversal', 'amount' => '-10.00',
            'reference_type' => Payment::class, 'reference_id' => $payment->id];
        $tables = ['payments', 'transactions', 'payment_allocations', 'payment_allocation_revisions',
            'financial_posting_operations', 'financial_posting_events', 'audit_events', 'treasury_postings'];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        foreach ([true, false] as $enabled) {
            config(['financial_candidate.cash_receipts' => $enabled, 'financial_candidate.void_api' => $enabled]);
            $this->rejects('gated_receipt_reversal_writer_required', fn () => \App\Models\Transaction::create($data));
            $this->rejects('gated_receipt_reversal_writer_required', fn () =>
                (new \App\Models\Transaction($data))->saveQuietly(['financial_receipt_reversal_writer' => $void]));
            $this->rejects('gated_receipt_reversal_writer_required', fn () =>
                $ledger->recordTransaction($subscriber, 'payment_reversal', '-10.00', reference: $payment));
            $this->rejects('gated_receipt_reversal_writer_required', fn () =>
                $ledger->reverseDocument($subscriber, $payment, 'payment'));
            $this->rejects('gated_receipt_reversal_writer_required', fn () =>
                DB::transaction(fn () => $ledger->reverseDocument($subscriber, $payment, 'payment', reversalWriter: $void)));
            $this->rejects('gated_receipt_reversal_writer_required', fn () =>
                DB::transaction(fn () => $ledger->reverseDocument($subscriber, $payment, 'payment', reversalWriter: $transfer)));
            foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        }
        $cancel = fn () => $void->request($actor, (int) $payment->id, 'Independent documented cancellation',
            $this->nowAt(), $voidUuid);
        $this->assertTrue($cancel()['registry_created']);
        $this->assertFalse($cancel()['registry_created']);
        $entry = \App\Models\Transaction::where('reference_type', Payment::class)->where('reference_id', $payment->id)
            ->where('type', 'payment_reversal')->sole();
        $this->rejects('gated_receipt_reversal_writer_required', fn () => $entry->forceFill(['amount' => '-20.00'])->saveQuietly());
        $this->assertSame('-10.00', $entry->fresh()->amount);
        $this->assertTrue($payment->fresh()->isVoided());
        $this->assertSame(0, $ledger->accountBalanceMinor($subscriber));
        $this->assertSame(0, app(CashSessionService::class)->expectedAmountMinor($session->fresh()));
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $voidUuid, 'amount_minor' => -1000]);
        $this->assertSame(1, DB::table('financial_posting_events')->where('operation_uuid', $voidUuid)->count());
        $this->assertSame($before['treasury_postings'], $snapshot('treasury_postings'));
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $account['id'])));
    }

    public function test_release_receipt_model_fence_blocks_direct_receipt_and_original_ledger(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $actor = $this->actor();
        $session = app(CashSessionService::class)->open($actor);
        $uuid = '66666666-6666-4666-8666-666666666666';
        $service = app(PaymentService::class);
        $ledger = app(\App\Services\TransactionService::class);
        $data = ['subscriber_id' => $subscriber->id, 'user_id' => $actor->id,
            'cash_session_id' => $session->id, 'receipt_number' => 'QA-DIRECT-RECEIPT',
            'amount' => '10.00', 'payment_date' => '2026-11-25', 'payment_method' => 'cash', 'sync_uuid' => $uuid];
        $tables = ['payments', 'transactions', 'payment_allocations', 'payment_allocation_revisions',
            'audit_events', 'document_sequences', 'financial_posting_operations', 'financial_posting_events', 'treasury_postings'];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        config(['financial_candidate.cash_receipts' => true]);
        $this->rejects('gated_receipt_model_writer_required', fn () => Payment::create($data));
        $this->rejects('gated_receipt_model_writer_required', fn () =>
            (new Payment($data))->saveQuietly(['financial_receipt_service' => $service]));
        $this->rejects('gated_receipt_ledger_writer_required', fn () => \App\Models\Transaction::create([
            'subscriber_id' => $subscriber->id, 'type' => 'payment', 'amount' => '10.00']));
        foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $writer = app(GatedCashReceiptWriter::class);
        $payment = $writer->collect($actor, (int) $subscriber->id, '10.00', $uuid, $this->nowAt(), (int) $session->id)['payment'];
        $entry = \App\Models\Transaction::where('reference_type', Payment::class)
            ->where('reference_id', $payment->id)->where('type', 'payment')->sole();
        $posting = ['subscriber_id' => $subscriber->id, 'type' => 'payment', 'amount' => '10.00',
            'reference_type' => Payment::class, 'reference_id' => $payment->id];
        $posted = [];
        foreach ($tables as $table) $posted[$table] = $snapshot($table);
        foreach ([true, false] as $enabled) {
            config(['financial_candidate.cash_receipts' => $enabled]);
            $this->rejects('gated_receipt_model_writer_required', fn () => Payment::create($data));
            $this->rejects('gated_receipt_ledger_writer_required', fn () => \App\Models\Transaction::create($posting));
            $this->rejects('gated_receipt_ledger_writer_required', fn () =>
                (new \App\Models\Transaction($posting))->saveQuietly(['financial_receipt_service' => $service]));
            $this->rejects('gated_receipt_ledger_writer_required', fn () =>
                $ledger->recordTransaction($subscriber, 'payment', '10.00', reference: $payment));
            $this->rejects('gated_receipt_ledger_writer_required', fn () =>
                $ledger->recordTransaction($subscriber, 'payment', '10.00', reference: $payment, receiptService: $service));
            $this->rejects('gated_receipt_ledger_writer_required', fn () =>
                $entry->fresh()->forceFill(['amount' => '20.00'])->saveQuietly());
            foreach ($posted as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        }
        config(['financial_candidate.cash_receipts' => true]);
        $this->assertSame((int) $payment->id, (int) $writer->collect($actor, (int) $subscriber->id,
            '10.00', $uuid, $this->nowAt(), (int) $session->id)['payment']->id);
        $this->assertSame(1000, $ledger->accountBalanceMinor($subscriber));
        $this->assertSame(1000, app(CashSessionService::class)->expectedAmountMinor($session->fresh()));
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $uuid, 'amount_minor' => 1000]);
        $this->assertSame(1, DB::table('financial_posting_events')->where('operation_uuid', $uuid)->count());
        $this->assertSame($before['treasury_postings'], $snapshot('treasury_postings'));
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $account['id'])));
    }

    // These reuse only the changed issuance path, rather than re-running the accepted baseline suite.
    public function test_release_receipt_model_fence_noncash_claim_issuance(): void
    {
        config(['financial_candidate.noncash_settlements' => true]);
        $this->round134Collection('claim', false, true);
    }

    public function test_release_receipt_model_fence_late_cash_issuance(): void
    {
        config(['financial_candidate.late_cash_settlements' => true]);
        $this->round134Collection('late_cash', false, true);
    }

    public function test_release_invoice_model_fence_blocks_direct_document_and_ledger_writes(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $actor = $this->actor();
        \App\Models\Tariff::create(['name' => 'Synthetic model fence tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-INVOICE-MODEL']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $writer = app(\App\Services\GatedInvoiceTransferWriter::class);
        $ledger = app(\App\Services\TransactionService::class);
        $issueUuid = '66666666-6666-4666-8666-666666666666';
        $cancelUuid = '88888888-8888-4888-8888-888888888888';
        $document = ['subscriber_id' => $subscriber->id, 'meter_id' => $meter->id, 'invoice_type' => 'meter',
            'bill_number' => 'QA-DIRECT', 'bill_date' => '2026-11-25', 'billing_period_start' => '2026-11-01',
            'billing_period_end' => '2026-11-24', 'consumption_fee' => '0.10', 'previous_balance' => '0.00',
            'credit_applied' => '0.00', 'total_amount' => '0.10', 'amount_paid' => '0.00', 'status' => 'issued'];
        $tables = ['invoices', 'readings', 'invoice_items', 'invoice_tariff_lines', 'transactions', 'payments',
            'payment_allocation_revisions', 'payment_allocations', 'audit_events', 'document_sequences',
            'financial_posting_operations', 'financial_posting_events', 'treasury_postings'];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($r) => (array) $r)->all();
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        config(['financial_candidate.invoices' => true]);
        $this->rejects('gated_invoice_model_writer_required', fn () => \App\Models\Invoice::create($document));
        $this->rejects('gated_invoice_model_writer_required', fn () =>
            (new \App\Models\Invoice($document))->saveQuietly(['financial_invoice_writer' => $writer]));
        foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $invoice = $writer->issue($actor, (int) $subscriber->id, (int) $meter->id, $issueUuid, $this->nowAt())['invoice'];
        $original = \App\Models\Transaction::where('reference_type', \App\Models\Invoice::class)
            ->where('reference_id', $invoice->id)->where('type', 'invoice')->sole();
        $reverse = ['subscriber_id' => $subscriber->id, 'meter_id' => $meter->id, 'type' => 'invoice_reversal',
            'amount' => '0.10', 'reference_type' => \App\Models\Invoice::class, 'reference_id' => $invoice->id];
        $issued = [];
        foreach ($tables as $table) $issued[$table] = $snapshot($table);
        foreach ([true, false] as $enabled) {
            config(['financial_candidate.invoices' => $enabled]);
            $this->rejects('gated_invoice_ledger_writer_required', fn () => \App\Models\Transaction::create($reverse));
            $this->rejects('gated_invoice_ledger_writer_required', fn () =>
                (new \App\Models\Transaction($reverse))->saveQuietly(['financial_invoice_writer' => $writer]));
            $this->rejects('gated_invoice_ledger_writer_required', fn () => $ledger->reverseDocument($subscriber, $invoice, 'invoice'));
            $this->rejects('gated_invoice_ledger_writer_required', fn () => $ledger->reverseDocument($subscriber, $invoice, 'invoice', $writer));
            $this->rejects('gated_invoice_ledger_writer_required', fn () => $original->fresh()->forceFill(['amount' => '-0.20'])->saveQuietly());
            $this->rejects('gated_invoice_model_writer_required', fn () => $invoice->fresh()->forceFill(['status' => 'cancelled',
                'void_reason' => 'Unauthorized direct model cancellation', 'voided_by' => $actor->id])->saveQuietly());
            foreach ($issued as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        }
        config(['financial_candidate.invoices' => true]);
        $cancel = fn () => $writer->cancel($actor, (int) $invoice->id, 'Independent protected model cancellation',
            $cancelUuid, $this->nowAt());
        $this->assertTrue($cancel()['registry_created']);
        $this->assertFalse($cancel()['registry_created']);
        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->assertSame('-0.10', $original->fresh()->amount);
        $this->assertSame(0, $ledger->accountBalanceMinor($subscriber));
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $issueUuid, 'amount_minor' => -10]);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $cancelUuid, 'amount_minor' => 10]);
        $this->assertSame(1, DB::table('financial_posting_events')->where('operation_uuid', $cancelUuid)->count());
        $this->assertSame($before['treasury_postings'], $snapshot('treasury_postings'));
        $this->assertSame($before['payments'], $snapshot('payments'));
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $account['id'])));
    }

    public function test_release_invoice_service_fence_preserves_original_and_reversal(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $actor = $this->actor();
        \App\Models\Tariff::create(['name' => 'Synthetic invoice fence tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-INVOICE-FENCE']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $writer = app(\App\Services\GatedInvoiceTransferWriter::class);
        $service = app(\App\Services\InvoiceService::class);
        $cancellation = app(\App\Services\InvoiceCancellationService::class);
        $issueUuid = '66666666-6666-4666-8666-666666666666';
        $cancelUuid = '88888888-8888-4888-8888-888888888888';
        $notes = 'Synthetic prospective invoice';
        $reason = 'Independent prospective invoice cancellation';
        $issue = fn () => $writer->issue($actor, (int) $subscriber->id, (int) $meter->id, $issueUuid, $this->nowAt(), $notes);
        $directIssue = fn (?\App\Services\GatedInvoiceTransferWriter $candidate = null) =>
            $service->generateInvoice($subscriber, $meter, $notes, true, 'meter', $candidate);
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        $tables = ['invoices', 'readings', 'invoice_items', 'invoice_tariff_lines', 'transactions', 'payments',
            'payment_allocation_revisions', 'payment_allocations', 'audit_events', 'document_sequences',
            'financial_posting_operations', 'financial_posting_events', 'treasury_postings'];
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        config(['financial_candidate.invoices' => true]);
        $this->rejects('gated_invoice_writer_required', $directIssue);
        $this->rejects('gated_invoice_writer_required', fn () => DB::transaction(fn () => $directIssue($writer)));
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'CLOSED']);
        $this->rejects('financial_period_not_open', $issue);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'OPEN']);
        foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $result = $issue();
        $this->assertTrue($result['registry_created']);
        $invoice = $result['invoice'];
        $this->assertFalse($issue()['registry_created']);
        $original = DB::table('transactions')->where('reference_type', \App\Models\Invoice::class)
            ->where('reference_id', $invoice->id)->where('type', 'invoice')->sole();
        $ledger = app(\App\Services\TransactionService::class);
        $this->assertSame(-10, $ledger->accountBalanceMinor($subscriber));
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $issueUuid, 'source_id' => (string) $original->id, 'amount_minor' => -10]);
        $directCancel = fn (?\App\Services\GatedInvoiceTransferWriter $candidate = null) =>
            $cancellation->cancel($invoice, $reason, (int) $actor->id, 'api', $candidate);
        $cancel = fn () => $writer->cancel($actor, (int) $invoice->id, $reason, $cancelUuid, $this->nowAt());
        $this->rejects('gated_invoice_writer_required', $directCancel);
        $this->rejects('gated_invoice_writer_required', fn () => DB::transaction(fn () => $directCancel($writer)));
        config(['financial_candidate.invoices' => false]);
        $this->rejects('gated_invoice_writer_required', $directCancel);
        $issued = [];
        foreach ($tables as $table) $issued[$table] = $snapshot($table);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'CLOSED']);
        $this->rejects('financial_period_not_open', $cancel);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'OPEN']);
        foreach ($issued as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        config(['financial_candidate.invoices' => true]);
        $this->assertTrue($cancel()['registry_created']);
        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->assertSame(0, $ledger->accountBalanceMinor($subscriber));
        $this->assertEquals($original, DB::table('transactions')->find($original->id));
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $cancelUuid, 'leg' => 'subscriber', 'amount_minor' => 10]);
        $this->assertSame(1, DB::table('financial_posting_events')->where('operation_uuid', $cancelUuid)->count());
        $this->assertDatabaseHas('readings', ['meter_id' => $meter->id, 'invoice_id' => null, 'is_invoiced' => false]);
        $committed = [];
        foreach ($tables as $table) $committed[$table] = $snapshot($table);
        $this->assertFalse($cancel()['registry_created']);
        config(['financial_candidate.invoices' => false]);
        $this->rejects('gated_invoice_writer_required', $directCancel);
        $this->assertFalse($cancel()['registry_created']);
        foreach ($committed as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        foreach (['payments', 'treasury_postings'] as $table) $this->assertSame($before[$table], $snapshot($table), $table);
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $account['id'])));
    }

    public function test_round135_invoice_issue_cancel_register_exact_legs_and_rollback(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        $actor = $this->actor();
        \App\Models\Tariff::create(['name' => 'Synthetic gated invoice tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-R135-INVOICE']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 20);
        $writer = app(\App\Services\GatedInvoiceTransferWriter::class);
        $issueUuid = '66666666-6666-4666-8666-666666666666';
        $cancelUuid = '88888888-8888-4888-8888-888888888888';
        $issue = fn () => $writer->issue($actor, (int) $subscriber->id, (int) $meter->id, $issueUuid, $this->nowAt(), 'Synthetic gated issue');
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        $tables = ['invoices', 'readings', 'invoice_items', 'invoice_tariff_lines', 'transactions',
            'payment_allocation_revisions', 'payment_allocations', 'audit_events', 'document_sequences',
            'financial_posting_operations', 'financial_posting_events', 'held_cash_shadow_reinstatements'];
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        $ledger = app(\App\Services\TransactionService::class);
        $failRegistry = function (string $uuid, callable $action) use ($snapshot, $tables): void {
            $before = [];
            foreach ($tables as $table) $before[$table] = $snapshot($table);
            $condition = "NEW.operation_uuid = '{$uuid}'";
            $body = DB::getDriverName() === 'sqlite'
                ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'qa_r135_registry_failure'); END"
                : "FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'qa_r135_registry_failure'; END IF; END";
            DB::unprepared("CREATE TRIGGER qa_r135_registry BEFORE INSERT ON financial_posting_events {$body}");
            try {
                try {
                    $action();
                    $this->fail('Financial operation committed despite registry failure.');
                } catch (\Illuminate\Database\QueryException $error) {
                    $this->assertStringContainsString('qa_r135_registry_failure', $error->getMessage());
                }
                foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
            } finally {
                DB::unprepared('DROP TRIGGER IF EXISTS qa_r135_registry');
            }
        };
        $failRegistry($issueUuid, $issue);
        $this->assertSame(20, $ledger->accountBalanceMinor($subscriber));
        $issued = $issue();
        $this->assertTrue($issued['registry_created']);
        $invoice = $issued['invoice'];
        $this->assertSame(10, $ledger->accountBalanceMinor($subscriber));
        $this->assertSame('0.10', $invoice->fresh()->amount_paid);
        $this->assertFalse($issue()['registry_created']);
        $this->rejects('gated_invoice_replay_conflict', fn () => $writer->issue($actor, (int) $subscriber->id,
            (int) $meter->id, $issueUuid, $this->nowAt(), 'Different request'));
        $original = DB::table('transactions')->where('reference_type', \App\Models\Invoice::class)
            ->where('reference_id', $invoice->id)->where('type', 'invoice')->sole();
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $issueUuid,
            'source_id' => (string) $original->id, 'amount_minor' => -10]);
        $cancel = fn () => $writer->cancel($actor, (int) $invoice->id,
            'Independent prospective invoice cancellation', $cancelUuid, $this->nowAt());
        $failRegistry($cancelUuid, $cancel);
        $this->assertSame(10, $ledger->accountBalanceMinor($subscriber));
        $this->assertTrue($cancel()['registry_created']);
        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->assertSame(20, $ledger->accountBalanceMinor($subscriber));
        $this->assertEquals($original, DB::table('transactions')->find($original->id));
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $cancelUuid, 'amount_minor' => 10]);
        $this->assertFalse($cancel()['registry_created']);
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $account['id'])));
        $verified = $this->gate(fn (array $context) => app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']));
        $this->assertFalse($verified['source_coverage_verified']);
        // Includes the independently registered held-credit ledger leg in this fixture.
        $this->assertSame(3, $verified['registered_ledger_legs_verified']);
    }

    public function test_release_subscriber_transfer_entry_preserves_original_cash_and_both_balances(): void
    {
        [, , $account, , , $from] = $this->held();
        $to = Subscriber::create(['name' => 'Synthetic correct subscriber', 'square_id' => $from->square_id, 'status' => 'active']);
        $issuer = $this->actor();
        $reviewer = $this->actor();
        foreach (['ViewAny:Payment', 'View:Payment'] as $permission) {
            $reviewer->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'));
        }
        $receipt = app(GatedCashReceiptWriter::class)->collect($issuer, (int) $from->id, '10.00',
            '66666666-6666-4666-8666-666666666666', $this->nowAt())['payment'];
        $uuid = '88888888-8888-4888-8888-888888888888';
        $data = ['to_subscriber_id' => $to->id, 'reason' => 'Independent verified receipt destination',
            'evidence' => self::EVIDENCE, 'no_new_cash_movement' => true, 'operation_uuid' => $uuid];
        $url = '/api/payments/'.$receipt->id.'/transfer-account';
        $writer = app(\App\Services\GatedInvoiceTransferWriter::class);
        $service = app(\App\Services\PaymentAccountTransferService::class);
        $direct = fn (?\App\Services\GatedInvoiceTransferWriter $candidate = null) =>
            $service->transfer($receipt, (int) $to->id, $reviewer, $data['reason'], $data['evidence'], $candidate);
        $tables = ['payments', 'transactions', 'payment_account_transfers', 'payment_allocation_revisions',
            'payment_allocations', 'financial_posting_operations', 'financial_posting_events', 'audit_events', 'treasury_postings'];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($r) => (array) $r)->all();
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        config(['financial_candidate.subscriber_transfers' => true]);
        $this->rejects('gated_subscriber_transfer_writer_required', $direct);
        $this->rejects('gated_subscriber_transfer_writer_required', fn () => DB::transaction(fn () => $direct($writer)));
        \Laravel\Sanctum\Sanctum::actingAs($issuer);
        $this->postJson($url, $data)->assertForbidden();
        \Laravel\Sanctum\Sanctum::actingAs($reviewer);
        $this->postJson($url, array_diff_key($data, ['operation_uuid' => true]))->assertUnprocessable();
        $this->postJson($url, array_replace($data, ['no_new_cash_movement' => false]))->assertUnprocessable();
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        $this->actingAs($reviewer, 'web');
        $page = \App\Filament\Resources\PaymentResource\Pages\ListPayments::class;
        $action = \Filament\Actions\Testing\TestAction::make('transfer_account')->table($receipt);
        $modal = \Livewire\Livewire::test($page)->mountAction($action)->setActionData($data);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'CLOSED']);
        $modal->callMountedAction()->assertHasActionErrors();
        foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $modal->assertActionDataSet(['operation_uuid' => $uuid]);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'OPEN']);
        $modal->callMountedAction()->assertHasNoActionErrors();
        $transfer = \App\Models\PaymentAccountTransfer::where('payment_id', $receipt->id)->sole();
        $ledger = app(\App\Services\TransactionService::class);
        $this->assertSame(0, $ledger->accountBalanceMinor($from));
        $this->assertSame(1000, $ledger->accountBalanceMinor($to));
        $events = DB::table('financial_posting_events')->where('operation_uuid', $uuid)->orderBy('amount_minor')->get();
        $this->assertSame([-1000, 1000], $events->map(fn ($event) => (int) $event->amount_minor)->all());
        $this->assertSame(['subscriber', 'subscriber'], $events->pluck('leg')->all());
        $committed = [];
        foreach ($tables as $table) $committed[$table] = $snapshot($table);
        \Laravel\Sanctum\Sanctum::actingAs($reviewer);
        $this->postJson($url, $data)->assertOk()->assertJsonPath('transfer.id', $transfer->id);
        $this->postJson($url, array_replace($data, ['reason' => 'Different documented transfer reason']))->assertConflict();
        config(['financial_candidate.subscriber_transfers' => false]);
        $this->rejects('gated_subscriber_transfer_writer_required', $direct);
        $this->postJson($url, $data)->assertConflict();
        $this->postJson($url, array_diff_key($data, ['operation_uuid' => true]))->assertConflict();
        foreach ($committed as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        foreach (['payments', 'treasury_postings'] as $table) $this->assertSame($before[$table], $snapshot($table), $table);
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $account['id'])));
    }

    public function test_round135_transfer_registers_two_legs_and_rejects_conflicting_replay(): void
    {
        [, , $account, , $lot, $from] = $this->held();
        $this->assignHeld($this->actor(), $lot, $from, 25);
        $this->creditHeld($this->actor(), 20);
        $to = Subscriber::create(['name' => 'Synthetic gated transfer destination', 'square_id' => $from->square_id, 'status' => 'active']);
        $issuer = $this->actor();
        $reviewer = $this->actor();
        $receipt = app(GatedCashReceiptWriter::class)->collect($issuer, (int) $from->id, '0.30',
            '66666666-6666-4666-8666-666666666666', $this->nowAt())['payment'];
        $writer = app(\App\Services\GatedInvoiceTransferWriter::class);
        $uuid = '88888888-8888-4888-8888-888888888888';
        $reason = 'Independent current receipt transfer';
        $transfer = fn () => $writer->transfer($reviewer, (int) $receipt->id, (int) $to->id, $reason, self::EVIDENCE, $uuid, $this->nowAt());
        $ledger = app(\App\Services\TransactionService::class);
        $this->assertSame(50, $ledger->accountBalanceMinor($from));
        $this->assertSame(0, $ledger->accountBalanceMinor($to));
        $tables = ['payments', 'transactions', 'payment_account_transfers', 'payment_allocation_revisions',
            'payment_allocations', 'financial_posting_operations', 'financial_posting_events', 'audit_events', 'treasury_postings'];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'CLOSED']);
        $this->rejects('financial_period_not_open', $transfer);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'OPEN']);
        $condition = "NEW.operation_uuid = '{$uuid}' AND NEW.amount_minor > 0";
        $body = DB::getDriverName() === 'sqlite'
            ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'qa_r135_transfer_registry'); END"
            : "FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'qa_r135_transfer_registry'; END IF; END";
        DB::unprepared("CREATE TRIGGER qa_r135_transfer_registry BEFORE INSERT ON financial_posting_events {$body}");
        try {
            try {
                $transfer();
                $this->fail('Transfer committed despite incoming registry failure.');
            } catch (\Illuminate\Database\QueryException $error) {
                $this->assertStringContainsString('qa_r135_transfer_registry', $error->getMessage());
            }
            foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS qa_r135_transfer_registry');
        }
        $this->assertTrue($transfer()['registry_created']);
        $this->assertSame(20, $ledger->accountBalanceMinor($from));
        $this->assertSame(30, $ledger->accountBalanceMinor($to));
        $this->assertSame($before['payments'], $snapshot('payments'));
        $this->assertSame($before['treasury_postings'], $snapshot('treasury_postings'));
        $events = DB::table('financial_posting_events')->where('operation_uuid', $uuid)->orderBy('amount_minor')->get();
        $this->assertSame([-30, 30], $events->map(fn ($row) => (int) $row->amount_minor)->all());
        $this->assertFalse($transfer()['registry_created']);
        $this->rejects('gated_transfer_replay_conflict', fn () => $writer->transfer($reviewer, (int) $receipt->id,
            (int) $to->id, 'Different documented reason', self::EVIDENCE, $uuid, $this->nowAt()));
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $account['id'])));
        $this->assertDatabaseCount('payment_account_transfers', 1);
        $verified = $this->gate(fn (array $context) => app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']));
        // Includes the independently registered held-credit ledger leg in this fixture.
        $this->assertSame(4, $verified['registered_ledger_legs_verified']);
        $this->assertFalse($verified['source_coverage_verified']);
    }

    public function test_round135_unregistered_original_receipt_cannot_be_adopted(): void
    {
        [, , , , , $subscriber] = $this->held();
        $receipt = app(PaymentService::class)->collectPayment($subscriber, '0.30');
        $destination = Subscriber::create(['name' => 'Synthetic destination', 'square_id' => $subscriber->square_id, 'status' => 'active']);
        $uuid = '88888888-8888-4888-8888-888888888888';
        $this->rejects('gated_document_prerequisite_unregistered', fn () => app(\App\Services\GatedInvoiceTransferWriter::class)
            ->transfer($this->actor(), (int) $receipt->id, (int) $destination->id,
                'Independent current receipt transfer', self::EVIDENCE, $uuid, $this->nowAt()));
        $this->assertDatabaseMissing('financial_posting_operations', ['operation_uuid' => $uuid]);
        $this->assertDatabaseCount('payment_account_transfers', 0);
    }

    public function test_release_funding_entry_preserves_cash_custody_and_registered_replay(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $issuer = $this->actor();
        $collector = $this->actor();
        $date = '2026-11-25';
        $writer = app(GatedTreasuryOpeningFundingWriter::class);
        $treasury = app(TreasuryRegisterService::class);
        $sessions = app(CashSessionService::class);
        $accountId = (int) $account['id'];
        $opening = ['code' => 'QA-NEW-TREASURY', 'name' => 'Synthetic prospective account', 'channel' => 'cash',
            'opening_on' => $date, 'opening_amount' => '1.00',
            'opening_reference' => 'QA-NEW-OPENING', 'opening_evidence' => self::EVIDENCE];
        $openUuid = '66666666-6666-4666-8666-666666666666';
        $fundUuid = '88888888-8888-4888-8888-888888888888';
        $fund = ['collector_id' => $collector->id, 'amount' => '4.00', 'reference' => 'QA-NEW-FUNDING',
            'evidence' => self::EVIDENCE];
        $directFund = fn (?GatedTreasuryOpeningFundingWriter $candidate = null) =>
            $sessions->openFunded($collector, $issuer, $accountId, $fund['amount'], $fund['reference'],
                $fund['evidence'], null, null, $candidate);
        $url = '/api/treasury/accounts';
        $fundUrl = $url.'/'.$accountId.'/fund-collector';
        $tables = ['treasury_accounts', 'cash_sessions', 'cash_session_fundings', 'treasury_postings',
            'payments', 'transactions', 'audit_events', 'financial_posting_operations', 'financial_posting_events'];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($r) => (array) $r)->all();
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        config(['financial_candidate.treasury_opening_funding' => true]);
        $this->rejects('gated_treasury_opening_writer_required', fn () => $treasury->open($issuer, $opening));
        $this->rejects('gated_treasury_opening_writer_required', fn () =>
            DB::transaction(fn () => $treasury->open($issuer, $opening, $writer)));
        $this->rejects('gated_collector_funding_writer_required', $directFund);
        $this->rejects('gated_collector_funding_writer_required', fn () => DB::transaction(fn () => $directFund($writer)));
        \Laravel\Sanctum\Sanctum::actingAs($issuer);
        $this->postJson($url, $opening)->assertUnprocessable();
        $this->postJson($fundUrl, $fund)->assertUnprocessable();
        $this->postJson($fundUrl, $fund + ['operation_uuid' => $fundUuid, 'issued_on' => $date])->assertUnprocessable();
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'CLOSED']);
        $this->postJson($url, $opening + ['operation_uuid' => $openUuid])->assertConflict();
        $this->postJson($fundUrl, $fund + ['operation_uuid' => $fundUuid])->assertConflict();
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'OPEN']);
        foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $opened = $this->postJson($url, $opening + ['operation_uuid' => $openUuid])->assertOk();
        $newId = (int) $opened->json('account.id');
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $openUuid,
            'leg' => 'opening', 'source_id' => (string) $newId, 'amount_minor' => 100]);
        $this->postJson($fundUrl, array_replace($fund, ['collector_id' => $issuer->id, 'operation_uuid' => $fundUuid]))->assertForbidden();
        $this->postJson($fundUrl, array_replace($fund, ['amount' => '100.01', 'operation_uuid' => $fundUuid]))->assertConflict();
        $funded = $this->postJson($fundUrl, $fund + ['operation_uuid' => $fundUuid])->assertOk();
        $sessionId = (int) $funded->json('cash_session.id');
        $this->assertSame('4.00', \App\Models\CashSession::findOrFail($sessionId)->opening_amount);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $fundUuid,
            'leg' => 'treasury', 'amount_minor' => -400]);
        $this->assertSame(9625, $treasury->inspect($issuer, $accountId)['provisional_recorded_balance_minor']);
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount($accountId)));
        $committed = [];
        foreach ($tables as $table) $committed[$table] = $snapshot($table);
        $this->postJson($url, $opening + ['operation_uuid' => $openUuid])->assertOk()->assertJsonPath('account.id', $newId);
        $this->postJson($fundUrl, $fund + ['operation_uuid' => $fundUuid])->assertOk()->assertJsonPath('cash_session.id', $sessionId);
        config(['financial_candidate.treasury_opening_funding' => false]);
        $this->postJson($url, $opening)->assertConflict();
        $this->postJson($fundUrl, $fund)->assertConflict();
        $this->postJson($fundUrl, $fund + ['operation_uuid' => $fundUuid])->assertConflict();
        foreach ($committed as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        foreach (['payments', 'transactions'] as $table) $this->assertSame($before[$table], $snapshot($table), $table);
    }

    public function test_release_treasury_transfer_entry_preserves_transit_and_independent_receipt(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $sender = $this->actor();
        $receiver = $this->actor();
        $date = '2026-11-25';
        $destination = app(GatedTreasuryOpeningFundingWriter::class)->open($receiver, [
            'code' => 'QA-TRANSFER-DEST', 'name' => 'Synthetic transfer destination', 'channel' => 'cash',
            'opening_on' => $date, 'opening_amount' => '1.00',
            'opening_reference' => 'QA-TRANSFER-OPEN', 'opening_evidence' => self::EVIDENCE,
        ], '66666666-6666-4666-8666-666666666666', $this->nowAt())['account'];
        $from = (int) $account['id'];
        $to = (int) $destination['id'];
        $dispatchUuid = '88888888-8888-4888-8888-888888888888';
        $receiveUuid = '99999999-9999-4999-8999-999999999999';
        $dispatch = ['from_account_id' => $from, 'to_account_id' => $to, 'amount' => '4.00',
            'reference' => 'QA-TRANSFER-DISPATCH', 'dispatched_on' => $date, 'evidence' => self::EVIDENCE];
        $receive = ['amount' => '4.00', 'reference' => 'QA-TRANSFER-RECEIVE',
            'received_on' => $date, 'evidence' => self::EVIDENCE];
        $service = app(\App\Services\TreasuryTransferService::class);
        $writer = app(\App\Services\GatedTreasuryTransferWriter::class);
        $treasury = app(TreasuryRegisterService::class);
        $url = '/api/treasury/transfers';
        $tables = ['payments', 'transactions', 'treasury_transfers', 'treasury_transfer_receipts',
            'treasury_postings', 'audit_events', 'financial_posting_operations', 'financial_posting_events'];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($r) => (array) $r)->all();
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        config(['financial_candidate.treasury_transfers' => true]);
        $this->rejects('gated_treasury_transfer_writer_required', fn () => $service->dispatch($sender, $dispatch));
        $this->rejects('gated_treasury_transfer_writer_required', fn () =>
            DB::transaction(fn () => $service->dispatch($sender, $dispatch, $writer)));
        \Laravel\Sanctum\Sanctum::actingAs($sender);
        $this->postJson($url, $dispatch)->assertUnprocessable();
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'CLOSED']);
        $this->postJson($url, $dispatch + ['operation_uuid' => $dispatchUuid])->assertConflict();
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'OPEN']);
        foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $response = $this->postJson($url, $dispatch + ['operation_uuid' => $dispatchUuid])->assertOk()
            ->assertJsonPath('transfer.status', 'in_transit')->assertJsonPath('transfer.in_transit_minor', 400);
        $id = (int) $response->json('transfer.id');
        $this->assertSame(9625, $treasury->inspect($sender, $from)['provisional_recorded_balance_minor']);
        $this->assertSame(100, $treasury->inspect($sender, $to)['provisional_recorded_balance_minor']);
        $receiptUrl = $url.'/'.$id.'/receive';
        $this->postJson($receiptUrl, $receive + ['operation_uuid' => $receiveUuid])->assertForbidden();
        \Laravel\Sanctum\Sanctum::actingAs($receiver);
        $this->rejects('gated_treasury_transfer_writer_required', fn () => $service->receive($receiver, $id, $receive));
        $this->rejects('gated_treasury_transfer_writer_required', fn () =>
            DB::transaction(fn () => $service->receive($receiver, $id, $receive, $writer)));
        config(['financial_candidate.treasury_transfers' => false]);
        $this->postJson($receiptUrl, $receive)->assertConflict();
        $this->postJson($receiptUrl, $receive + ['operation_uuid' => $receiveUuid])->assertConflict();
        config(['financial_candidate.treasury_transfers' => true]);
        $this->postJson($receiptUrl, array_replace($receive, ['amount' => '3.99', 'operation_uuid' => $receiveUuid]))->assertUnprocessable();
        $this->postJson($receiptUrl, $receive + ['operation_uuid' => $receiveUuid])->assertOk()
            ->assertJsonPath('transfer.status', 'received')->assertJsonPath('transfer.in_transit_minor', 0);
        $committed = [];
        foreach ($tables as $table) $committed[$table] = $snapshot($table);
        $this->postJson($receiptUrl, $receive + ['operation_uuid' => $receiveUuid])->assertOk();
        \Laravel\Sanctum\Sanctum::actingAs($sender);
        $this->postJson($url, $dispatch + ['operation_uuid' => $dispatchUuid])->assertOk()
            ->assertJsonPath('transfer.id', $id);
        config(['financial_candidate.treasury_transfers' => false]);
        $this->postJson($url, $dispatch)->assertConflict();
        foreach ($committed as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $dispatchUuid, 'leg' => 'treasury', 'amount_minor' => -400]);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $receiveUuid, 'leg' => 'treasury', 'amount_minor' => 400]);
        $this->assertSame(9625, $treasury->inspect($receiver, $from)['provisional_recorded_balance_minor']);
        $this->assertSame(500, $treasury->inspect($receiver, $to)['provisional_recorded_balance_minor']);
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount($from)));
        foreach (['payments', 'transactions'] as $table) $this->assertSame($before[$table], $snapshot($table), $table);
    }

    public function test_release_refund_entrypoints_modal_and_api_share_atomic_debit(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $issuer = $this->actor();
        $reviewer = $this->actor();
        foreach (['ViewAny:Payment', 'View:Payment'] as $permission) {
            $reviewer->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'));
        }
        $receipt = app(GatedCashReceiptWriter::class)->collect($issuer, (int) $subscriber->id, '10.00',
            '66666666-6666-4666-8666-666666666666', $this->nowAt())['payment'];
        $accountId = (int) $account['id'];
        $uuid = '88888888-8888-4888-8888-888888888888';
        $data = ['amount' => '4.00', 'disbursement_reference' => 'QA-REFUND-ENTRY', 'method' => 'treasury_cash',
            'disbursed_on' => '2026-11-25', 'reason' => 'Independent documented excess credit refund',
            'evidence' => self::EVIDENCE, 'disbursement_confirmed' => true,
            'treasury_account_id' => $accountId, 'operation_uuid' => $uuid];
        $url = '/api/payments/'.$receipt->id.'/refund';
        $tables = ['payments', 'payment_refunds', 'transactions', 'treasury_postings', 'audit_events',
            'payment_allocation_revisions', 'payment_allocations', 'financial_posting_operations', 'financial_posting_events'];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($r) => (array) $r)->all();
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        config(['financial_candidate.refunds' => true]);
        \Laravel\Sanctum\Sanctum::actingAs($issuer);
        $this->postJson($url, $data)->assertForbidden();
        \Laravel\Sanctum\Sanctum::actingAs($reviewer);
        $this->postJson($url, array_diff_key($data, ['treasury_account_id' => true]))->assertUnprocessable();
        $this->postJson($url, array_replace($data, ['disbursement_confirmed' => false]))->assertUnprocessable();
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        $this->actingAs($reviewer, 'web');
        $page = \App\Filament\Resources\PaymentResource\Pages\ListPayments::class;
        $action = \Filament\Actions\Testing\TestAction::make('record_refund')->table($receipt);
        $modal = \Livewire\Livewire::test($page)->mountAction($action)->setActionData($data);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'CLOSED']);
        $modal->callMountedAction()->assertHasActionErrors();
        foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $modal->assertActionDataSet(['operation_uuid' => $uuid]);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'OPEN']);
        $modal->callMountedAction()->assertHasNoActionErrors();
        $refund = \App\Models\PaymentRefund::where('disbursement_reference', $data['disbursement_reference'])->sole();
        $posting = DB::table('treasury_postings')->where('payment_refund_id', $refund->id)->sole();
        $this->assertSame($accountId, (int) $posting->treasury_account_id);
        $events = DB::table('financial_posting_events')->where('operation_uuid', $uuid)->orderBy('leg')->get();
        $this->assertSame(['subscriber', 'treasury'], $events->pluck('leg')->all());
        $this->assertSame([-400, -400], $events->map(fn ($event) => (int) $event->amount_minor)->all());
        $committed = [];
        foreach ($tables as $table) $committed[$table] = $snapshot($table);
        \Laravel\Sanctum\Sanctum::actingAs($reviewer);
        $this->postJson($url, $data)->assertOk()->assertJsonPath('refund.id', $refund->id)
            ->assertJsonPath('posting.id', $posting->id);
        $this->postJson('/api/treasury/accounts/'.$accountId.'/postings', ['kind' => 'payment_refund', 'source_id' => $refund->id])->assertConflict();
        config(['financial_candidate.refunds' => false]);
        $this->postJson($url, $data)->assertConflict();
        $this->postJson($url, array_diff_key($data, ['treasury_account_id' => true, 'operation_uuid' => true]))->assertConflict();
        foreach ($committed as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $this->assertSame($before['payments'], $snapshot('payments'));
        $this->assertSame(600, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount($accountId)));
    }

    public function test_release_refund_service_fence_protects_both_legs_and_handover_reserve(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $issuer = $this->actor();
        $reviewer = $this->actor();
        $session = app(CashSessionService::class)->open($issuer);
        $receipt = app(GatedCashReceiptWriter::class)->collect($issuer, (int) $subscriber->id, '10.00',
            '66666666-6666-4666-8666-666666666666', $this->nowAt(), (int) $session->id)['payment'];
        $session = app(CashSessionService::class)->decide(app(CashSessionService::class)->close($session, '15.00'), true, $reviewer);
        $accountId = (int) $account['id'];
        app(\App\Services\GatedCashHandoverWriter::class)->receive($reviewer, (int) $session->id,
            $accountId, '15.00', 'QA-REFUND-RESERVE-HANDOVER', '2026-11-25', self::EVIDENCE,
            '77777777-7777-4777-8777-777777777777', $this->nowAt());
        $writer = app(\App\Services\GatedRefundWriter::class);
        $service = app(\App\Services\PaymentRefundService::class);
        $treasury = app(TreasuryRegisterService::class);
        $reference = 'QA-REFUND-FENCE';
        $date = '2026-11-25';
        $reason = 'Independent documented excess credit refund';
        $uuid = '88888888-8888-4888-8888-888888888888';
        $direct = fn (?\App\Services\GatedRefundWriter $candidate = null) =>
            $service->record($receipt, $reviewer, '4.00', $reference, 'treasury_cash', $date, $reason, self::EVIDENCE, $candidate);
        $record = fn () => $writer->record($reviewer, (int) $receipt->id, $accountId, '4.00',
            $reference, 'treasury_cash', $date, $reason, self::EVIDENCE, $uuid, $this->nowAt());
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        $tables = ['payments', 'payment_refunds', 'transactions', 'treasury_postings', 'audit_events',
            'payment_allocation_revisions', 'payment_allocations', 'financial_posting_operations', 'financial_posting_events'];
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        config(['financial_candidate.refunds' => true]);
        $this->rejects('gated_refund_writer_required', $direct);
        $this->rejects('gated_refund_writer_required', fn () => DB::transaction(fn () => $direct($writer)));
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'CLOSED']);
        $this->rejects('financial_period_not_open', $record);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'OPEN']);
        foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $result = $record();
        $this->assertTrue($result['registry_created']);
        $refund = $result['refund'];
        $post = fn (?\App\Services\GatedRefundWriter $candidate = null) =>
            $treasury->post($reviewer, $accountId, 'payment_refund', (int) $refund->id, $candidate);
        $this->rejects('gated_refund_writer_required', $post);
        $this->rejects('gated_refund_writer_required', fn () => DB::transaction(fn () => $post($writer)));
        $this->rejects('gated_refund_writer_required', $direct);
        $committed = [];
        foreach ($tables as $table) $committed[$table] = $snapshot($table);
        $this->assertFalse($record()['registry_created']);
        config(['financial_candidate.refunds' => false]);
        $this->rejects('gated_refund_writer_required', $post);
        $this->rejects('gated_refund_writer_required', $direct);
        $this->assertFalse($record()['registry_created']);
        foreach ($committed as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $events = DB::table('financial_posting_events')->where('operation_uuid', $uuid)->orderBy('leg')->get();
        $this->assertSame(['subscriber', 'treasury'], $events->pluck('leg')->all());
        $this->assertSame([-400, -400], $events->map(fn ($event) => (int) $event->amount_minor)->all());
        $this->assertSame(600, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        $report = $treasury->inspect($reviewer, $accountId);
        $this->assertSame(11125, $report['provisional_recorded_balance_minor']);
        $this->assertSame(500, $report['held_cash_handover_surplus_reserve_minor']);
        $this->assertSame(525, $report['held_cash_total_protected_minor']);
        $this->assertSame(525, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount($accountId)));
    }

    public function test_round136_cash_refund_records_both_legs_and_keeps_reserve(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 20);
        $issuer = $this->actor();
        $reviewer = $this->actor();
        $receipt = app(GatedCashReceiptWriter::class)->collect($issuer, (int) $subscriber->id, '200.00',
            '66666666-6666-4666-8666-666666666666', $this->nowAt())['payment'];
        $writer = app(\App\Services\GatedRefundWriter::class);
        $accountId = (int) $account['id'];
        $uuid = '88888888-8888-4888-8888-888888888888';
        $reason = 'Independent documented excess credit refund';
        $record = fn () => $writer->record($reviewer, (int) $receipt->id, $accountId, '40.00', 'QA-R136-REFUND',
            'treasury_cash', '2026-11-25', $reason, self::EVIDENCE, $uuid, $this->nowAt());
        $ledger = app(\App\Services\TransactionService::class);
        $tables = ['payments', 'payment_refunds', 'transactions', 'treasury_postings', 'audit_events',
            'payment_allocation_revisions', 'payment_allocations', 'financial_posting_operations', 'financial_posting_events'];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'CLOSED']);
        $this->rejects('financial_period_not_open', $record);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'OPEN']);
        $condition = "NEW.operation_uuid = '{$uuid}' AND NEW.leg = 'treasury'";
        $body = DB::getDriverName() === 'sqlite'
            ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'qa_r136_refund_registry'); END"
            : "FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'qa_r136_refund_registry'; END IF; END";
        DB::unprepared("CREATE TRIGGER qa_r136_registry BEFORE INSERT ON financial_posting_events {$body}");
        try {
            try {
                $record();
                $this->fail('Refund committed despite registry failure.');
            } catch (\Illuminate\Database\QueryException $error) {
                $this->assertStringContainsString('qa_r136_refund_registry', $error->getMessage());
            }
            foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS qa_r136_registry');
        }
        $this->assertSame(20020, $ledger->accountBalanceMinor($subscriber));
        $result = $record();
        $this->assertTrue($result['registry_created']);
        $this->assertSame(16020, $ledger->accountBalanceMinor($subscriber));
        $this->assertSame($before['payments'], $snapshot('payments'));
        $events = DB::table('financial_posting_events')->where('operation_uuid', $uuid)->orderBy('leg')->get();
        $this->assertSame(['subscriber', 'treasury'], $events->pluck('leg')->all());
        $this->assertSame([-4000, -4000], $events->map(fn ($row) => (int) $row->amount_minor)->all());
        $report = app(TreasuryRegisterService::class)->inspect($reviewer, $accountId);
        $this->assertSame(6025, $report['provisional_recorded_balance_minor']);
        $this->assertSame(25, $report['held_cash_total_protected_minor']);
        $this->assertSame(0, $report['channel_unposted_cash_refund_minor']);
        $committed = [];
        foreach ($tables as $table) $committed[$table] = $snapshot($table);
        $this->assertFalse($record()['registry_created']);
        $this->rejects('gated_refund_replay_conflict', fn () => $writer->record($reviewer, (int) $receipt->id, $accountId,
            '40.00', 'QA-R136-REFUND', 'treasury_cash', '2026-11-25', 'Different documented refund reason',
            self::EVIDENCE, $uuid, $this->nowAt()));
        $this->rejects('held_cash_reserved', fn () => $writer->record($reviewer, (int) $receipt->id, $accountId,
            '60.01', 'QA-R136-OVER', 'treasury_cash', '2026-11-25', $reason, self::EVIDENCE,
            '99999999-9999-4999-8999-999999999999', $this->nowAt()));
        foreach ($committed as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $verified = $this->gate(fn (array $context) => app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']));
        $this->assertSame(3, $verified['registered_ledger_legs_verified']);
        $this->assertSame(2, $verified['registered_treasury_legs_verified']);
        $this->assertFalse($verified['source_coverage_verified']);
    }

    public function test_round136_refund_rejects_unregistered_prior_document_without_erasing_it(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $issuer = $this->actor();
        $reviewer = $this->actor();
        $receipt = app(GatedCashReceiptWriter::class)->collect($issuer, (int) $subscriber->id, '10.00',
            '66666666-6666-4666-8666-666666666666', $this->nowAt())['payment'];
        $reason = 'Independent documented excess credit refund';
        $prior = app(\App\Services\PaymentRefundService::class)->record($receipt, $reviewer, '1.00',
            'QA-R136-PRIOR', 'treasury_cash', '2026-11-25', $reason, self::EVIDENCE);
        $uuid = '88888888-8888-4888-8888-888888888888';
        $this->rejects('gated_refund_prior_unregistered', fn () => app(\App\Services\GatedRefundWriter::class)->record(
            $reviewer, (int) $receipt->id, (int) $account['id'], '2.00', 'QA-R136-NEW',
            'treasury_cash', '2026-11-25', $reason, self::EVIDENCE, $uuid, $this->nowAt()));
        $this->assertDatabaseHas('payment_refunds', ['id' => $prior->id]);
        $this->assertDatabaseCount('payment_refunds', 1);
        $this->assertDatabaseMissing('financial_posting_operations', ['operation_uuid' => $uuid]);
        $this->assertSame(900, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        $this->assertSame(100, app(TreasuryRegisterService::class)->inspect($reviewer, (int) $account['id'])['channel_unposted_cash_refund_minor']);
    }

    public function test_round136_bank_refund_requires_registered_collection_and_records_both_legs(): void
    {
        [, , , , , $subscriber] = $this->held();
        $issuer = $this->actor();
        $reviewer = $this->actor();
        $date = '2026-11-25';
        $bank = app(GatedTreasuryOpeningFundingWriter::class)->open($issuer, [
            'code' => 'QA-R136-BANK', 'name' => 'Synthetic bank', 'channel' => 'bank', 'opening_on' => $date,
            'opening_amount' => '100.00', 'opening_reference' => 'QA-R136-BANK-OPEN', 'opening_evidence' => self::EVIDENCE,
        ], '66666666-6666-4666-8666-666666666666', $this->nowAt())['account'];
        $accountId = (int) $bank['id'];
        $statement = app(\App\Services\BankReconciliationService::class)->import($issuer, $accountId, [
            'reference' => 'QA-R136-STMT', 'from_date' => $date, 'to_date' => $date,
            'opening_amount' => '100.00', 'closing_amount' => '100.30', 'evidence' => self::EVIDENCE,
            'lines' => [['bank_reference' => 'QA-R136-IN', 'booked_on' => $date, 'amount' => '0.30']],
        ]);
        $statementId = (int) $statement['statement']['id'];
        $lineId = (int) DB::table('bank_statement_lines')->where('bank_statement_id', $statementId)->value('id');
        $claimUuid = '88888888-8888-4888-8888-888888888888';
        $claim = app(\App\Services\NonCashClaimService::class)->submit($issuer, [
            'sync_uuid' => $claimUuid, 'subscriber_id' => $subscriber->id, 'amount' => '0.30',
            'payment_method' => 'check', 'instrument_reference' => 'QA-R136-CHECK', 'instrument_on' => $date,
        ]);
        $cleared = app(\App\Services\GatedCollectionWriter::class)->clearClaim($reviewer, (int) $claim['id'], $statementId,
            $lineId, $date, 'QA-R136-PROOF', self::EVIDENCE, $claimUuid, $this->nowAt());
        $uuid = '99999999-9999-4999-8999-999999999999';
        $record = fn () => app(\App\Services\GatedRefundWriter::class)->record($reviewer, (int) $cleared['payment_id'],
            $accountId, '0.10', 'QA-R136-BANK-REFUND', 'bank_transfer', $date, 'Independent documented bank refund',
            self::EVIDENCE, $uuid, $this->nowAt());
        $this->assertTrue($record()['registry_created']);
        $this->assertFalse($record()['registry_created']);
        $events = DB::table('financial_posting_events')->where('operation_uuid', $uuid)->orderBy('leg')->get();
        $this->assertSame(['subscriber', 'treasury'], $events->pluck('leg')->all());
        $this->assertSame([-10, -10], $events->map(fn ($row) => (int) $row->amount_minor)->all());
        $this->assertSame(20, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        $this->assertSame(10020, app(TreasuryRegisterService::class)->inspect($reviewer, $accountId)['provisional_recorded_balance_minor']);
        $this->assertDatabaseCount('payment_refunds', 1);
        $verified = $this->gate(fn (array $context) => app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']));
        $this->assertSame(2, $verified['registered_ledger_legs_verified']);
        $this->assertSame(3, $verified['registered_treasury_legs_verified']);
        $this->assertFalse($verified['source_coverage_verified']);
    }

    public function test_round137_void_immediate_and_approval_respect_gate_and_record_reversal_once(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 20);
        $issuer = $this->actor();
        $reviewer = $this->actor();
        $writer = app(\App\Services\GatedReceiptVoidWriter::class);
        $collect = fn (string $amount, string $uuid) => app(GatedCashReceiptWriter::class)
            ->collect($issuer, (int) $subscriber->id, $amount, $uuid, $this->nowAt())['payment'];
        $ledger = app(\App\Services\TransactionService::class);
        \App\Models\Setting::set('void_approval_threshold', '1.00');
        $first = $collect('0.30', '66666666-6666-4666-8666-666666666666');
        $reason = 'Independent documented current receipt cancellation';
        $uuid = '88888888-8888-4888-8888-888888888888';
        $immediate = fn () => $writer->request($issuer, (int) $first->id, $reason, $this->nowAt(), $uuid);
        $this->assertSame(50, $ledger->accountBalanceMinor($subscriber));
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'CLOSED']);
        $this->rejects('financial_period_not_open', $immediate);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'OPEN']);
        $this->assertTrue($immediate()['registry_created']);
        $this->assertFalse($immediate()['registry_created']);
        $this->assertSame(20, $ledger->accountBalanceMinor($subscriber));
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $uuid, 'amount_minor' => -30]);
        $second = $collect('1.00', '99999999-9999-4999-8999-999999999999');
        $approveUuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $this->rejects('gated_void_pending_no_posting_uuid', fn () => $writer->request($issuer, (int) $second->id, $reason, $this->nowAt(), $approveUuid));
        $request = fn () => $writer->request($issuer, (int) $second->id, $reason, $this->nowAt());
        $requested = $request();
        $this->assertTrue($requested['pending']);
        $requestId = $requested['request_id'];
        $this->assertSame($requestId, $request()['request_id']);
        $this->assertSame(120, $ledger->accountBalanceMinor($subscriber));
        $this->assertDatabaseMissing('financial_posting_operations', ['operation_uuid' => $approveUuid]);
        // Lowering the threshold never bypasses an existing request.
        \App\Models\Setting::set('void_approval_threshold', '0.00');
        $this->rejects('gated_void_pending_no_posting_uuid', fn () => $writer->request($issuer, (int) $second->id, $reason, $this->nowAt(), $approveUuid));
        try {
            $writer->decide($issuer, (int) $second->id, true, 'Independent decision', $this->nowAt(), $approveUuid, $requestId);
            $this->fail('Requester approved their own void.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $approve = fn () => $writer->decide($reviewer, (int) $second->id, true, 'Independent decision', $this->nowAt(), $approveUuid, $requestId);
        $tables = ['payments', 'transactions', 'payment_allocation_revisions', 'payment_allocations', 'audit_events',
            'financial_posting_operations', 'financial_posting_events', 'treasury_postings'];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        $condition = "NEW.operation_uuid = '{$approveUuid}'";
        $body = DB::getDriverName() === 'sqlite'
            ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'qa_r137_void_registry'); END"
            : "FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'qa_r137_void_registry'; END IF; END";
        DB::unprepared("CREATE TRIGGER qa_r137_void_registry BEFORE INSERT ON financial_posting_events {$body}");
        try {
            try {
                $approve();
                $this->fail('Void committed despite registry failure.');
            } catch (\Illuminate\Database\QueryException $error) {
                $this->assertStringContainsString('qa_r137_void_registry', $error->getMessage());
            }
            foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
            $this->assertTrue($second->fresh()->hasPendingVoidRequest());
            $this->assertSame(120, $ledger->accountBalanceMinor($subscriber));
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS qa_r137_void_registry');
        }
        $this->assertTrue($approve()['registry_created']);
        $this->assertFalse($approve()['registry_created']);
        $this->assertSame(20, $ledger->accountBalanceMinor($subscriber));
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $approveUuid, 'amount_minor' => -100]);
        $this->assertSame($before['treasury_postings'], $snapshot('treasury_postings'));
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $account['id'])));
        $verified = $this->gate(fn (array $context) => app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']));
        $this->assertSame(5, $verified['registered_ledger_legs_verified']);
        $this->assertFalse($verified['source_coverage_verified']);
    }

    public function test_round137_rejected_request_creates_no_financial_posting_and_legacy_pending_is_not_adopted(): void
    {
        [, , , , , $subscriber] = $this->held();
        $issuer = $this->actor();
        $reviewer = $this->actor();
        \App\Models\Setting::set('void_approval_threshold', '0.30');
        $receipt = app(GatedCashReceiptWriter::class)->collect($issuer, (int) $subscriber->id, '0.30',
            '66666666-6666-4666-8666-666666666666', $this->nowAt())['payment'];
        $writer = app(\App\Services\GatedReceiptVoidWriter::class);
        $reason = 'Independent documented current receipt cancellation';
        $request = $writer->request($issuer, (int) $receipt->id, $reason, $this->nowAt());
        $this->assertTrue($request['pending']);
        $before = DB::table('financial_posting_events')->count();
        $reject = fn () => $writer->decide($reviewer, (int) $receipt->id, false, 'Rejected after review', $this->nowAt(), requestId: $request['request_id']);
        $this->assertFalse($reject()['registry_created']);
        $auditCount = DB::table('audit_events')->count();
        $this->assertFalse($reject()['registry_created']);
        $this->assertSame($auditCount, DB::table('audit_events')->count());
        $this->assertFalse($receipt->fresh()->isVoided());
        $this->assertFalse($receipt->fresh()->hasPendingVoidRequest());
        $this->assertSame($before, DB::table('financial_posting_events')->count());
        $this->assertSame(30, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        $new = $writer->request($issuer, (int) $receipt->id, $reason, $this->nowAt());
        $this->assertNotSame($new['request_id'], $request['request_id']);
        $this->rejects('gated_void_replay_conflict', $reject);
        $this->assertTrue($receipt->fresh()->hasPendingVoidRequest());
        $legacy = app(GatedCashReceiptWriter::class)->collect($issuer, (int) $subscriber->id, '0.50',
            '88888888-8888-4888-8888-888888888888', $this->nowAt())['payment'];
        // The new service fence refuses this previously possible legacy
        // request. Simulate a pre-fence pending row in the disposable test DB
        // so the original missing-gated-proof check remains meaningful.
        try {
            app(PaymentService::class)->requestVoid($legacy, $reason, $issuer->id);
            $this->fail('Registered receipt accepted a legacy void request.');
        } catch (\Illuminate\Validation\ValidationException $error) {
            $this->assertArrayHasKey('payment', $error->errors());
        }
        $this->assertFalse($legacy->fresh()->hasPendingVoidRequest());
        DB::table('payments')->where('id', $legacy->id)->update([
            'void_requested_at' => now(),
            'void_requested_by' => $issuer->id,
            'void_request_reason' => $reason,
        ]);
        $this->rejects('gated_void_request_unverified', fn () => $writer->decide($reviewer, (int) $legacy->id, true,
            'Independent decision', $this->nowAt(), '99999999-9999-4999-8999-999999999999'));
        $this->assertTrue($legacy->fresh()->hasPendingVoidRequest());
        $this->assertSame(80, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
    }

    public function test_release_surplus_proof_ordering_preserves_cash_after_prior_allocation_and_replay(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $collector = $this->actor();
        $reviewer = $this->actor();
        $sessions = app(CashSessionService::class);
        $session = $sessions->open($collector);
        app(GatedCashReceiptWriter::class)->collect($collector, (int) $subscriber->id, '10.00',
            '66666666-6666-4666-8666-666666666666', $this->nowAt(), (int) $session->id);
        $session = $sessions->decide($sessions->close($session, '15.00'), true, $reviewer);
        $owner = Subscriber::create(['name' => 'Synthetic prior allocation owner', 'square_id' => $subscriber->square_id, 'status' => 'active']);
        $late = app(\App\Services\LateReceiptReviewService::class);
        $allocate = function (string $uuid, string $reference) use ($late, $collector, $reviewer, $session, $owner) {
            $id = (int) $late->submit($collector, ['sync_uuid' => $uuid, 'cash_session_id' => $session->id,
                'subscriber_id' => $owner->id, 'amount' => '2.00', 'payment_method' => 'cash', 'payment_date' => '2026-11-25'])['id'];
            return app(\App\Services\GatedCountedCashWriter::class)->allocate($reviewer, $id, '2026-11-25',
                $reference, self::EVIDENCE, $uuid, $this->nowAt());
        };
        config(['financial_candidate.cash_handovers' => true, 'financial_candidate.late_cash_settlements' => true]);
        $this->assertSame(300, $allocate('99999999-9999-4999-8999-999999999999', 'QA-ORDER-FIRST')['allocation']['available_minor']);
        $accountId = (int) $account['id'];
        $uuid = '88888888-8888-4888-8888-888888888888';
        $writer = app(\App\Services\GatedCashHandoverWriter::class);
        $receive = fn () => $writer->receive($reviewer, (int) $session->id, $accountId,
            '15.00', 'QA-ORDER-HANDOVER', '2026-11-25', self::EVIDENCE, $uuid, $this->nowAt());
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($r) => (array) $r)->all();
        $before = $snapshot('transactions');
        $this->assertTrue($receive()['registry_created']);
        $this->assertSame($before, $snapshot('transactions'));
        $treasury = $snapshot('treasury_postings');
        $this->assertSame(100, $allocate('77777777-7777-4777-8777-777777777777', 'QA-ORDER-SECOND')['allocation']['available_minor']);
        $tables = ['cash_sessions', 'cash_handovers', 'payments', 'transactions', 'treasury_postings',
            'audit_events', 'financial_posting_operations', 'financial_posting_events'];
        $committed = [];
        foreach ($tables as $table) $committed[$table] = $snapshot($table);
        $this->assertFalse($receive()['registry_created']);
        foreach ($committed as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $this->assertSame($treasury, $snapshot('treasury_postings'));
        $this->assertSame(400, $owner->fresh()->getAccountBalanceMinor());
        $this->assertSame(1000, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertSame(525, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount($accountId)));
    }

    public function test_release_surplus_proof_ordering_refuses_tampered_or_missing_reserve(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $collector = $this->actor();
        $reviewer = $this->actor();
        $sessions = app(CashSessionService::class);
        $session = $sessions->open($collector);
        app(GatedCashReceiptWriter::class)->collect($collector, (int) $subscriber->id, '10.00',
            '66666666-6666-4666-8666-666666666666', $this->nowAt(), (int) $session->id);
        $session = $sessions->decide($sessions->close($session, '15.00'), true, $reviewer);
        $accountId = (int) $account['id'];
        config(['financial_candidate.cash_handovers' => true]);
        app(\App\Services\GatedCashHandoverWriter::class)->receive($reviewer, (int) $session->id,
            $accountId, '15.00', 'QA-PROOF-HANDOVER', '2026-11-25', self::EVIDENCE,
            '88888888-8888-4888-8888-888888888888', $this->nowAt());
        $guard = app(\App\Services\HeldCashReservation::class);
        $check = fn () => DB::transaction(fn () => $guard->assertOrdinaryOutflow($accountId, 1, '2026-11-25'));
        $this->assertSame(525, DB::transaction(fn () => $guard->protectedForAccount($accountId)));
        $row = DB::table('audit_events')->where('event', 'financial.handover_surplus_reserved')->sole();
        $after = json_decode($row->after, true, flags: JSON_THROW_ON_ERROR);
        $after['reserved_minor'] = 499;
        DB::table('audit_events')->where('id', $row->id)->update(['after' => json_encode($after, JSON_THROW_ON_ERROR)]);
        $this->rejects('gated_handover_surplus_reserve_invalid', $check);
        DB::table('audit_events')->where('id', $row->id)->update(['after' => $row->after]);
        $this->assertSame(525, DB::transaction(fn () => $guard->protectedForAccount($accountId)));
        DB::table('audit_events')->where('id', $row->id)->delete();
        $this->rejects('gated_handover_surplus_reserve_invalid', $check);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => '88888888-8888-4888-8888-888888888888',
            'leg' => 'treasury', 'amount_minor' => 1500]);
    }

    public function test_release_surplus_handover_reserves_full_variance_without_duplicate_cash(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $collector = $this->actor();
        $reviewer = $this->actor();
        $sessions = app(CashSessionService::class);
        $session = $sessions->open($collector);
        app(GatedCashReceiptWriter::class)->collect($collector, (int) $subscriber->id, '10.00',
            '66666666-6666-4666-8666-666666666666', $this->nowAt(), (int) $session->id);
        $session = $sessions->decide($sessions->close($session, '15.00'), true, $reviewer);
        $accountId = (int) $account['id'];
        $uuid = '88888888-8888-4888-8888-888888888888';
        $writer = app(\App\Services\GatedCashHandoverWriter::class);
        $receive = fn (string $amount) => $writer->receive($reviewer, (int) $session->id, $accountId,
            $amount, 'QA-SURPLUS-HANDOVER', '2026-11-25', self::EVIDENCE, $uuid, $this->nowAt());
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($r) => (array) $r)->all();
        $tables = ['cash_sessions', 'cash_handovers', 'payments', 'transactions', 'treasury_postings',
            'audit_events', 'financial_posting_operations', 'financial_posting_events'];
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        config(['financial_candidate.cash_handovers' => true]);
        $this->rejects('gated_handover_surplus_requires_full_count', fn () => $receive('10.00'));
        foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $this->assertTrue($receive('15.00')['registry_created']);
        $this->assertFalse($receive('15.00')['registry_created']);
        $reserve = app(\App\Services\HeldCashReservation::class);
        $this->assertSame(525, DB::transaction(fn () => $reserve->protectedForAccount($accountId)));
        $this->assertSame(500, DB::transaction(fn () => app(\App\Services\HandoverSurplusReservation::class)->forAccount($accountId)));
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $uuid, 'leg' => 'treasury', 'amount_minor' => 1500]);
        $this->assertSame(1, DB::table('financial_posting_events')->where('operation_uuid', $uuid)->count());
        DB::transaction(fn () => $reserve->assertOrdinaryOutflow($accountId, 11000, '2026-11-25'));
        $this->rejects('held_cash_reserved', fn () => DB::transaction(fn () => $reserve->assertOrdinaryOutflow($accountId, 11001, '2026-11-25')));
        $this->assertSame($before['cash_sessions'], $snapshot('cash_sessions'));
        $this->assertSame($before['payments'], $snapshot('payments'));
        $this->assertSame($before['transactions'], $snapshot('transactions'));
        $treasury = $snapshot('treasury_postings');
        $owner = Subscriber::create(['name' => 'Synthetic surplus owner', 'square_id' => $subscriber->square_id, 'status' => 'active']);
        $late = app(\App\Services\LateReceiptReviewService::class);
        $key = '99999999-9999-4999-8999-999999999999';
        $id = (int) $late->submit($collector, ['sync_uuid' => $key, 'cash_session_id' => $session->id,
            'subscriber_id' => $owner->id, 'amount' => '2.00', 'payment_method' => 'cash', 'payment_date' => '2026-11-25'])['id'];
        config(['financial_candidate.late_cash_settlements' => true]);
        $allocated = app(\App\Services\GatedCountedCashWriter::class)->allocate($reviewer, $id, '2026-11-25',
            'QA-SURPLUS-ALLOCATED', self::EVIDENCE, $key, $this->nowAt());
        $this->assertSame(300, $allocated['allocation']['available_minor']);
        $this->assertSame(200, $owner->fresh()->getAccountBalanceMinor());
        $this->assertSame($treasury, $snapshot('treasury_postings'));
        $this->assertSame(525, DB::transaction(fn () => $reserve->protectedForAccount($accountId)));
        $this->assertSame(1000, $subscriber->fresh()->getAccountBalanceMinor());
    }

    public function test_release_shortage_handover_only_posts_counted_cash_and_preserves_shortage(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $collector = $this->actor();
        $reviewer = $this->actor();
        $sessions = app(CashSessionService::class);
        $session = $sessions->open($collector);
        app(GatedCashReceiptWriter::class)->collect($collector, (int) $subscriber->id, '10.00',
            '66666666-6666-4666-8666-666666666666', $this->nowAt(), (int) $session->id);
        $session = $sessions->decide($sessions->close($session, '8.00'), true, $reviewer);
        $accountId = (int) $account['id'];
        $uuid = '88888888-8888-4888-8888-888888888888';
        $writer = app(\App\Services\GatedCashHandoverWriter::class);
        $receive = fn (string $amount, string $reference, string $key) => $writer->receive($reviewer,
            (int) $session->id, $accountId, $amount, $reference, '2026-11-25', self::EVIDENCE, $key, $this->nowAt());
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($r) => (array) $r)->all();
        $tables = ['cash_sessions', 'cash_handovers', 'payments', 'transactions', 'treasury_postings',
            'audit_events', 'financial_posting_operations', 'financial_posting_events'];
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        config(['financial_candidate.cash_handovers' => true]);
        // The approved count cannot be replaced by the larger book total.
        try {
            $receive('10.00', 'QA-SHORTAGE-OVER', $uuid);
            $this->fail('Handover exceeded independently counted cash.');
        } catch (\Illuminate\Validation\ValidationException $error) {
            $this->assertArrayHasKey('amount', $error->errors());
        }
        foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $this->assertTrue($receive('8.00', 'QA-SHORTAGE-RECEIVED', $uuid)['registry_created']);
        $this->assertFalse($receive('8.00', 'QA-SHORTAGE-RECEIVED', $uuid)['registry_created']);
        $this->assertSame('complete', app(\App\Services\CashHandoverService::class)->summary($session)['status']);
        $this->assertSame('-2.00', $session->fresh()->variance_amount);
        $this->assertSame(1000, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        foreach (['cash_sessions', 'payments', 'transactions'] as $table) $this->assertSame($before[$table], $snapshot($table), $table);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $uuid, 'leg' => 'treasury', 'amount_minor' => 800]);
        $this->assertSame(1, DB::table('financial_posting_events')->where('operation_uuid', $uuid)->count());
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount($accountId)));
    }

    public function test_release_handover_entrypoints_modal_and_api_preserve_single_treasury_effect(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $collector = $this->actor();
        $reviewer = $this->actor();
        foreach (['ViewAny:CashSession', 'View:CashSession'] as $permission) {
            $reviewer->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'));
        }
        $sessions = app(CashSessionService::class);
        $session = $sessions->open($collector);
        app(GatedCashReceiptWriter::class)->collect($collector, (int) $subscriber->id, '1.00',
            '66666666-6666-4666-8666-666666666666', $this->nowAt(), (int) $session->id);
        $session = $sessions->decide($sessions->close($session, '1.00'), true, $reviewer);
        $accountId = (int) $account['id'];
        $uuid = '88888888-8888-4888-8888-888888888888';
        $data = ['amount' => '0.40', 'reference' => 'QA-HANDOVER-ENTRY', 'received_on' => '2026-11-25',
            'evidence' => self::EVIDENCE, 'treasury_account_id' => $accountId, 'operation_uuid' => $uuid];
        $url = '/api/cash-sessions/'.$session->id.'/handovers';
        $tables = ['cash_sessions', 'cash_handovers', 'payments', 'transactions', 'treasury_postings',
            'audit_events', 'financial_posting_operations', 'financial_posting_events'];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($r) => (array) $r)->all();
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        config(['financial_candidate.cash_handovers' => true]);
        \Laravel\Sanctum\Sanctum::actingAs($collector);
        $this->postJson($url, $data)->assertForbidden();
        \Laravel\Sanctum\Sanctum::actingAs($reviewer);
        $this->postJson($url, array_diff_key($data, ['treasury_account_id' => true]))->assertUnprocessable();
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        $this->actingAs($reviewer, 'web');
        $page = \App\Filament\Resources\CashSessions\Pages\ListCashSessions::class;
        $action = \Filament\Actions\Testing\TestAction::make('record_treasury_handover')->table($session);
        $modal = \Livewire\Livewire::test($page)->mountAction($action)->setActionData($data);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'CLOSED']);
        $modal->callMountedAction()->assertHasActionErrors();
        foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $modal->assertActionDataSet(['operation_uuid' => $uuid]);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'OPEN']);
        $modal->callMountedAction()->assertHasNoActionErrors();
        $handover = \App\Models\CashHandover::where('reference', $data['reference'])->sole();
        $posting = DB::table('treasury_postings')->where('cash_handover_id', $handover->id)->sole();
        $this->assertSame($accountId, (int) $posting->treasury_account_id);
        $this->assertSame(40, (int) $posting->amount_minor);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $uuid, 'leg' => 'treasury', 'amount_minor' => 40]);
        $this->assertSame(1, DB::table('financial_posting_events')->where('operation_uuid', $uuid)->count());
        $committed = [];
        foreach ($tables as $table) $committed[$table] = $snapshot($table);
        \Laravel\Sanctum\Sanctum::actingAs($reviewer);
        $this->postJson($url, $data)->assertOk()->assertJsonPath('handover.id', $handover->id)
            ->assertJsonPath('posting.id', $posting->id)->assertJsonPath('treasury_handover.remaining_amount', '0.60');
        $this->postJson('/api/treasury/accounts/'.$accountId.'/postings', ['kind' => 'cash_handover', 'source_id' => $handover->id])->assertConflict();
        config(['financial_candidate.cash_handovers' => false]);
        $this->postJson($url, $data)->assertConflict();
        $this->postJson($url, array_diff_key($data, ['treasury_account_id' => true, 'operation_uuid' => true]))->assertConflict();
        foreach ($committed as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        foreach (['cash_sessions', 'payments', 'transactions'] as $table) $this->assertSame($before[$table], $snapshot($table), $table);
        $this->assertSame(100, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
    }

    public function test_release_handover_service_fence_requires_atomic_custody_scope(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $collector = $this->actor();
        $reviewer = $this->actor();
        $sessions = app(CashSessionService::class);
        $session = $sessions->open($collector);
        app(GatedCashReceiptWriter::class)->collect($collector, (int) $subscriber->id, '1.00',
            '66666666-6666-4666-8666-666666666666', $this->nowAt(), (int) $session->id);
        $session = $sessions->decide($sessions->close($session, '1.00'), true, $reviewer);
        $writer = app(\App\Services\GatedCashHandoverWriter::class);
        $handovers = app(\App\Services\CashHandoverService::class);
        $treasury = app(TreasuryRegisterService::class);
        $accountId = (int) $account['id'];
        $uuid = '88888888-8888-4888-8888-888888888888';
        $reference = 'QA-HANDOVER-FENCE';
        $date = '2026-11-25';
        $direct = fn (?\App\Services\GatedCashHandoverWriter $candidate = null) =>
            $handovers->record($session, $reviewer, '0.40', $reference, $date, self::EVIDENCE, $candidate);
        $receive = fn () => $writer->receive($reviewer, (int) $session->id, $accountId, '0.40',
            $reference, $date, self::EVIDENCE, $uuid, $this->nowAt());
        $tables = ['cash_sessions', 'cash_handovers', 'payments', 'transactions', 'treasury_postings', 'audit_events',
            'financial_posting_operations', 'financial_posting_events'];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        config(['financial_candidate.cash_handovers' => true]);
        $reason = 'gated_handover_writer_required';
        $this->rejects($reason, $direct);
        $this->rejects($reason, fn () => DB::transaction(fn () => $direct($writer)));
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'CLOSED']);
        $this->rejects('financial_period_not_open', $receive);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'OPEN']);
        foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $result = $receive();
        $this->assertTrue($result['registry_created']);
        $handover = $result['handover'];
        $post = fn (?\App\Services\GatedCashHandoverWriter $candidate = null) =>
            $treasury->post($reviewer, $accountId, 'cash_handover', (int) $handover->id, $candidate);
        $committed = [];
        foreach ($tables as $table) $committed[$table] = $snapshot($table);
        $this->rejects($reason, $post);
        $this->rejects($reason, fn () => DB::transaction(fn () => $post($writer)));
        $this->rejects($reason, $direct);
        $this->assertFalse($receive()['registry_created']);
        config(['financial_candidate.cash_handovers' => false]);
        $this->rejects($reason, $direct);
        $this->rejects($reason, $post);
        $this->assertFalse($receive()['registry_created']);
        foreach ($committed as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        foreach (['cash_sessions', 'payments', 'transactions'] as $table) $this->assertSame($before[$table], $snapshot($table), $table);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $uuid, 'leg' => 'treasury', 'amount_minor' => 40]);
        $this->assertSame(1, DB::table('financial_posting_events')->where('operation_uuid', $uuid)->count());
        $this->assertSame('0.60', $handovers->summary($session)['remaining_amount']);
        $this->assertSame(100, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount($accountId)));
    }

    public function test_round137_handover_registers_only_treasury_effect_and_preserves_original_till(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $collector = $this->actor();
        $reviewer = $this->actor();
        $sessions = app(CashSessionService::class);
        $session = $sessions->open($collector);
        $receipt = app(GatedCashReceiptWriter::class)->collect($collector, (int) $subscriber->id, '1.00',
            '66666666-6666-4666-8666-666666666666', $this->nowAt(), (int) $session->id)['payment'];
        $session = $sessions->decide($sessions->close($session, '1.00'), true, $reviewer);
        $writer = app(\App\Services\GatedCashHandoverWriter::class);
        $accountId = (int) $account['id'];
        $uuid = '88888888-8888-4888-8888-888888888888';
        $receive = fn () => $writer->receive($reviewer, (int) $session->id, $accountId, '0.40',
            'QA-R137-HANDOVER-1', '2026-11-25', self::EVIDENCE, $uuid, $this->nowAt());
        $tables = ['cash_sessions', 'cash_handovers', 'payments', 'transactions', 'treasury_postings', 'audit_events',
            'financial_posting_operations', 'financial_posting_events'];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        $condition = "NEW.operation_uuid = '{$uuid}'";
        $body = DB::getDriverName() === 'sqlite'
            ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'qa_r137_handover_registry'); END"
            : "FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'qa_r137_handover_registry'; END IF; END";
        DB::unprepared("CREATE TRIGGER qa_r137_handover_registry BEFORE INSERT ON financial_posting_events {$body}");
        try {
            try {
                $receive();
                $this->fail('Handover committed despite registry failure.');
            } catch (\Illuminate\Database\QueryException $error) {
                $this->assertStringContainsString('qa_r137_handover_registry', $error->getMessage());
            }
            foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS qa_r137_handover_registry');
        }
        $this->assertTrue($receive()['registry_created']);
        $this->assertFalse($receive()['registry_created']);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $uuid, 'leg' => 'treasury', 'amount_minor' => 40]);
        $this->assertSame(1, DB::table('financial_posting_events')->where('operation_uuid', $uuid)->count());
        $secondUuid = '99999999-9999-4999-8999-999999999999';
        $this->assertTrue($writer->receive($reviewer, (int) $session->id, $accountId, '0.60', 'QA-R137-HANDOVER-2',
            '2026-11-25', self::EVIDENCE, $secondUuid, $this->nowAt())['registry_created']);
        $this->assertSame('complete', app(\App\Services\CashHandoverService::class)->summary($session)['status']);
        $this->assertSame($before['cash_sessions'], $snapshot('cash_sessions'));
        $this->assertSame($before['payments'], $snapshot('payments'));
        $this->assertSame($before['transactions'], $snapshot('transactions'));
        $this->assertSame(100, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        $this->assertSame(10125, app(TreasuryRegisterService::class)->inspect($reviewer, $accountId)['provisional_recorded_balance_minor']);
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount($accountId)));
        $this->assertDatabaseCount('cash_handovers', 2);
    }

    public function test_round138_funded_till_returns_registered_funding_and_net_receipts_once(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $collector = $this->actor();
        $reviewer = $this->actor();
        $accountId = (int) $account['id'];
        $fund = app(GatedTreasuryOpeningFundingWriter::class)->fund($collector, $reviewer, $accountId, '5.00',
            'QA-R138-FUND', self::EVIDENCE, '66666666-6666-4666-8666-666666666666', $this->nowAt());
        $session = $fund['session'];
        $receipts = app(GatedCashReceiptWriter::class);
        $receipts->collect($collector, (int) $subscriber->id, '1.00', '88888888-8888-4888-8888-888888888888', $this->nowAt(), (int) $session->id);
        $voided = $receipts->collect($collector, (int) $subscriber->id, '0.30', '99999999-9999-4999-8999-999999999999', $this->nowAt(), (int) $session->id)['payment'];
        \App\Models\Setting::set('void_approval_threshold', '0.00');
        $this->assertTrue(app(\App\Services\GatedReceiptVoidWriter::class)->request($collector, (int) $voided->id,
            'Independent documented current receipt cancellation', $this->nowAt(), 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')['registry_created']);
        $sessions = app(CashSessionService::class);
        $session = $sessions->decide($sessions->close($session, '6.00'), true, $reviewer);
        $writer = app(\App\Services\GatedCashHandoverWriter::class);
        $receive = fn () => $writer->receive($reviewer, (int) $session->id, $accountId, '6.00', 'QA-R138-RETURN',
            '2026-11-25', self::EVIDENCE, 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $this->nowAt());
        $this->assertSame(9525, app(TreasuryRegisterService::class)->inspect($reviewer, $accountId)['provisional_recorded_balance_minor']);
        $before = $session->fresh()->getRawOriginal();
        $entries = DB::table('transactions')->orderBy('id')->get()->toArray();
        $this->assertTrue($receive()['registry_created']);
        $this->assertFalse($receive()['registry_created']);
        $this->assertSame($before, $session->fresh()->getRawOriginal());
        $this->assertEquals($entries, DB::table('transactions')->orderBy('id')->get()->toArray());
        $this->assertSame(100, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        $this->assertSame(10125, app(TreasuryRegisterService::class)->inspect($reviewer, $accountId)['provisional_recorded_balance_minor']);
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount($accountId)));
        $this->assertSame('complete', app(\App\Services\CashHandoverService::class)->summary($session)['status']);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'leg' => 'treasury', 'amount_minor' => 600]);
        $this->assertDatabaseCount('cash_handovers', 1);
        $verified = $this->gate(fn (array $context) => app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']));
        $this->assertSame(3, $verified['registered_ledger_legs_verified']);
        $this->assertSame(3, $verified['registered_treasury_legs_verified']);
        $this->assertFalse($verified['source_coverage_verified']);
    }

    public function test_round138_unregistered_funding_cannot_be_adopted_as_handover(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $collector = $this->actor();
        $reviewer = $this->actor();
        $accountId = (int) $account['id'];
        $sessions = app(CashSessionService::class);
        $session = $sessions->openFunded($collector, $reviewer, $accountId, '5.00', 'QA-R138-LEGACY-FUND', self::EVIDENCE);
        $session = $sessions->decide($sessions->close($session, '5.00'), true, $reviewer);
        $uuid = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $this->rejects('gated_handover_prerequisite_unregistered', fn () => app(\App\Services\GatedCashHandoverWriter::class)
            ->receive($reviewer, (int) $session->id, $accountId, '5.00', 'QA-R138-LEGACY-RETURN',
                '2026-11-25', self::EVIDENCE, $uuid, $this->nowAt()));
        $this->assertDatabaseCount('cash_handovers', 0);
        $this->assertDatabaseCount('cash_session_fundings', 1);
        $this->assertDatabaseMissing('financial_posting_operations', ['operation_uuid' => $uuid]);
        $this->assertSame(9525, app(TreasuryRegisterService::class)->inspect($reviewer, $accountId)['provisional_recorded_balance_minor']);
    }

    public function test_release_bank_fee_entry_blocks_direct_writer_and_preserves_single_debit(): void
    {
        [, , $cash, , , $subscriber] = $this->held();
        $importer = $this->actor();
        $reviewer = $this->actor();
        $date = '2026-11-25';
        $account = app(GatedTreasuryOpeningFundingWriter::class)->open($importer, [
            'code' => 'QA-FEE-ENTRY', 'name' => 'Synthetic bank fee account', 'channel' => 'bank',
            'opening_on' => $date, 'opening_amount' => '100.00',
            'opening_reference' => 'QA-FEE-OPEN', 'opening_evidence' => self::EVIDENCE,
        ], '66666666-6666-4666-8666-666666666666', $this->nowAt())['account'];
        $bank = app(\App\Services\BankReconciliationService::class);
        $statement = $bank->import($importer, (int) $account['id'], [
            'reference' => 'QA-FEE-STMT', 'from_date' => $date, 'to_date' => $date,
            'opening_amount' => '100.00', 'closing_amount' => '99.60', 'evidence' => self::EVIDENCE,
            'lines' => [['bank_reference' => 'QA-FEE-DEBIT', 'booked_on' => $date, 'amount' => '-0.40']],
        ]);
        $statementId = (int) $statement['statement']['id'];
        $lineId = (int) DB::table('bank_statement_lines')->where('bank_statement_id', $statementId)->value('id');
        $uuid = '88888888-8888-4888-8888-888888888888';
        $writer = app(\App\Services\GatedBankFeeWriter::class);
        $reason = 'Independent documented bank service fee';
        $reference = 'QA-FEE-ENTRY-PROOF';
        $direct = fn (?\App\Services\GatedBankFeeWriter $candidate = null) =>
            $bank->recordFee($reviewer, $statementId, $lineId, $reference, $reason, self::EVIDENCE, $candidate);
        $record = fn () => $writer->record($reviewer, $statementId, $lineId, $date,
            $reference, $reason, self::EVIDENCE, $uuid, $this->nowAt());
        $url = '/api/treasury/bank-statements/'.$statementId.'/fees';
        $data = ['line_id' => $lineId, 'reference' => $reference, 'reason' => $reason,
            'evidence' => self::EVIDENCE, 'operation_uuid' => $uuid];
        $tables = ['payments', 'transactions', 'treasury_bank_fees', 'treasury_postings', 'bank_statement_matches',
            'bank_statement_lines', 'audit_events', 'financial_posting_operations', 'financial_posting_events'];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        config(['financial_candidate.bank_fees' => true]);
        $this->rejects('gated_bank_fee_writer_required', $direct);
        $this->rejects('gated_bank_fee_writer_required', fn () => DB::transaction(fn () => $direct($writer)));
        \Laravel\Sanctum\Sanctum::actingAs($importer);
        $this->postJson($url, $data)->assertForbidden();
        \Laravel\Sanctum\Sanctum::actingAs($reviewer);
        $this->postJson($url, array_diff_key($data, ['operation_uuid' => true]))->assertUnprocessable();
        $this->postJson($url, $data + ['amount' => '1.00'])->assertUnprocessable();
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'CLOSED']);
        $this->postJson($url, $data)->assertConflict();
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'OPEN']);
        foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $response = $this->postJson($url, $data)->assertOk()->assertJsonPath('reconciliation.fully_matched', true);
        $postingId = $response->json('posting.id');
        $this->assertSame(-40, (int) $response->json('posting.amount_minor'));
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $uuid, 'leg' => 'treasury', 'amount_minor' => -40]);
        $this->assertSame(1, DB::table('financial_posting_events')->where('operation_uuid', $uuid)->count());
        $committed = [];
        foreach ($tables as $table) $committed[$table] = $snapshot($table);
        $this->postJson($url, $data)->assertOk()->assertJsonPath('posting.id', $postingId);
        $this->postJson($url, array_replace($data, ['operation_uuid' => '99999999-9999-4999-8999-999999999999']))->assertConflict();
        config(['financial_candidate.bank_fees' => false]);
        $this->rejects('gated_bank_fee_writer_required', $direct);
        $this->postJson($url, $data)->assertConflict();
        $this->postJson($url, array_diff_key($data, ['operation_uuid' => true]))->assertConflict();
        $this->assertFalse($record()['registry_created']);
        foreach ($committed as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $this->assertSame($before['payments'], $snapshot('payments'));
        $this->assertSame($before['transactions'], $snapshot('transactions'));
        $this->assertSame(9960, app(TreasuryRegisterService::class)->inspect($reviewer, (int) $account['id'])['provisional_recorded_balance_minor']);
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $cash['id'])));
    }

    public function test_round139_bank_fee_is_registered_atomically_without_subscriber_effect(): void
    {
        [, , $cash, , , $subscriber] = $this->held();
        $importer = $this->actor();
        $reviewer = $this->actor();
        $date = '2026-11-25';
        $account = app(GatedTreasuryOpeningFundingWriter::class)->open($importer, [
            'code' => 'QA-R139-BANK', 'name' => 'Synthetic bank fee account', 'channel' => 'bank',
            'opening_on' => $date, 'opening_amount' => '100.00',
            'opening_reference' => 'QA-R139-OPEN', 'opening_evidence' => self::EVIDENCE,
        ], '66666666-6666-4666-8666-666666666666', $this->nowAt())['account'];
        $bank = app(\App\Services\BankReconciliationService::class);
        $statement = $bank->import($importer, (int) $account['id'], [
            'reference' => 'QA-R139-STMT', 'from_date' => $date, 'to_date' => $date,
            'opening_amount' => '100.00', 'closing_amount' => '99.60', 'evidence' => self::EVIDENCE,
            'lines' => [['bank_reference' => 'QA-R139-DEBIT', 'booked_on' => $date, 'amount' => '-0.40']],
        ]);
        $statementId = (int) $statement['statement']['id'];
        $lineId = (int) DB::table('bank_statement_lines')->where('bank_statement_id', $statementId)->value('id');
        $uuid = '88888888-8888-4888-8888-888888888888';
        $writer = app(\App\Services\GatedBankFeeWriter::class);
        $reason = 'Independent documented bank service fee';
        $record = fn () => $writer->record($reviewer, $statementId, $lineId, $date,
            'QA-R139-FEE', $reason, self::EVIDENCE, $uuid, $this->nowAt());
        $tables = ['payments', 'transactions', 'treasury_bank_fees', 'treasury_postings', 'bank_statement_matches',
            'bank_statement_lines', 'audit_events', 'financial_posting_operations', 'financial_posting_events'];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'CLOSED']);
        $this->rejects('financial_period_not_open', $record);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'OPEN']);
        try {
            $writer->record($importer, $statementId, $lineId, $date, 'QA-R139-FEE',
                $reason, self::EVIDENCE, $uuid, $this->nowAt());
            $this->fail('Statement importer approved their own bank fee.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $condition = "NEW.operation_uuid = '{$uuid}'";
        $body = DB::getDriverName() === 'sqlite'
            ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'qa_r139_fee_registry'); END"
            : "FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'qa_r139_fee_registry'; END IF; END";
        DB::unprepared("CREATE TRIGGER qa_r139_fee_registry BEFORE INSERT ON financial_posting_events {$body}");
        try {
            try {
                $record();
                $this->fail('Bank fee committed despite registry failure.');
            } catch (\Illuminate\Database\QueryException $error) {
                $this->assertStringContainsString('qa_r139_fee_registry', $error->getMessage());
            }
            foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS qa_r139_fee_registry');
        }
        $result = $record();
        $this->assertTrue($result['registry_created']);
        $this->assertTrue($result['reconciliation']['fully_matched']);
        $this->assertSame(-40, (int) $result['posting']['amount_minor']);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $uuid, 'leg' => 'treasury', 'amount_minor' => -40]);
        $this->assertSame(1, DB::table('financial_posting_events')->where('operation_uuid', $uuid)->count());
        $this->assertSame($before['payments'], $snapshot('payments'));
        $this->assertSame($before['transactions'], $snapshot('transactions'));
        $this->assertSame(9960, app(TreasuryRegisterService::class)->inspect($reviewer, (int) $account['id'])['provisional_recorded_balance_minor']);
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $cash['id'])));
        $this->assertFalse($record()['registry_created']);
        $this->rejects('gated_bank_fee_operation_conflict', fn () => $writer->record($reviewer, $statementId, $lineId,
            $date, 'QA-R139-FEE', $reason, self::EVIDENCE, '99999999-9999-4999-8999-999999999999', $this->nowAt()));
        $this->assertDatabaseCount('treasury_bank_fees', 1);
        $verified = $this->gate(fn (array $context) => app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']));
        $this->assertSame(2, $verified['registered_treasury_legs_verified']);
        $this->assertSame(0, $verified['registered_ledger_legs_verified']);
        $this->assertFalse($verified['source_coverage_verified']);
    }

    public function test_round139_legacy_bank_fee_cannot_be_silently_adopted(): void
    {
        [, , , , , $subscriber] = $this->held();
        $importer = $this->actor();
        $reviewer = $this->actor();
        $date = '2026-11-25';
        $account = app(GatedTreasuryOpeningFundingWriter::class)->open($importer, [
            'code' => 'QA-R139-LEGACY', 'name' => 'Synthetic bank fee account', 'channel' => 'bank',
            'opening_on' => $date, 'opening_amount' => '100.00',
            'opening_reference' => 'QA-R139-LEGACY-OPEN', 'opening_evidence' => self::EVIDENCE,
        ], '66666666-6666-4666-8666-666666666666', $this->nowAt())['account'];
        $bank = app(\App\Services\BankReconciliationService::class);
        $statement = $bank->import($importer, (int) $account['id'], [
            'reference' => 'QA-R139-LEGACY-STMT', 'from_date' => $date, 'to_date' => $date,
            'opening_amount' => '100.00', 'closing_amount' => '99.60', 'evidence' => self::EVIDENCE,
            'lines' => [['bank_reference' => 'QA-R139-LEGACY-DEBIT', 'booked_on' => $date, 'amount' => '-0.40']],
        ]);
        $statementId = (int) $statement['statement']['id'];
        $lineId = (int) DB::table('bank_statement_lines')->where('bank_statement_id', $statementId)->value('id');
        $reason = 'Independent documented bank service fee';
        $old = $bank->recordFee($reviewer, $statementId, $lineId, 'QA-R139-LEGACY-FEE', $reason, self::EVIDENCE);
        $uuid = '88888888-8888-4888-8888-888888888888';
        $this->rejects('gated_bank_fee_operation_conflict', fn () => app(\App\Services\GatedBankFeeWriter::class)
            ->record($reviewer, $statementId, $lineId, $date, 'QA-R139-LEGACY-FEE', $reason, self::EVIDENCE, $uuid, $this->nowAt()));
        $this->assertDatabaseCount('treasury_bank_fees', 1);
        $this->assertDatabaseHas('treasury_postings', ['id' => $old['posting']['id'], 'amount_minor' => -40]);
        $this->assertDatabaseMissing('financial_posting_operations', ['operation_uuid' => $uuid]);
    }

    public function test_round140_registered_documents_match_both_directions_without_authorizing_close(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 20);
        $issuer = $this->actor();
        $reviewer = $this->actor();
        \App\Models\Tariff::create(['name' => 'Synthetic coverage tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-R140-METER']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $writer = app(\App\Services\GatedInvoiceTransferWriter::class);
        $invoice = $writer->issue($issuer, (int) $subscriber->id, (int) $meter->id,
            '66666666-6666-4666-8666-666666666666', $this->nowAt())['invoice'];
        $writer->cancel($reviewer, (int) $invoice->id, 'Independent documented current cancellation',
            '88888888-8888-4888-8888-888888888888', $this->nowAt());
        $receipt = app(GatedCashReceiptWriter::class)->collect($issuer, (int) $subscriber->id, '0.30',
            '99999999-9999-4999-8999-999999999999', $this->nowAt())['payment'];
        $to = Subscriber::create(['name' => 'Synthetic coverage destination', 'square_id' => $subscriber->square_id, 'status' => 'active']);
        $writer->transfer($reviewer, (int) $receipt->id, (int) $to->id, 'Independent current receipt transfer',
            self::EVIDENCE, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $this->nowAt());
        $tables = ['invoices', 'payments', 'transactions', 'treasury_postings', 'financial_posting_operations',
            'financial_posting_events', 'audit_events', 'payment_allocation_revisions'];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        $inspect = fn () => $this->gate(fn (array $context) => app(\App\Services\FinancialSourceCoverageDiagnostic::class)->inspect($context['period_id']));
        $result = $inspect();
        $this->assertSame([], $result['issues']);
        $this->assertTrue($result['scanned_sources_match']);
        $this->assertSame(11, $result['expected_legs']);
        $this->assertSame(11, $result['matched_legs']);
        $this->assertSame(11, $result['registry_events']);
        $this->assertFalse($result['source_coverage_verified']);
        $this->assertFalse($result['closure_authorized']);
        $this->assertSame($result, $inspect());
        foreach ($before as $table => $records) $this->assertSame($records, $snapshot($table), $table);
    }

    public function test_round140_missing_receipt_and_handover_sources_detected_even_when_registry_valid(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $issuer = $this->actor();
        $reviewer = $this->actor();
        $legacy = app(PaymentService::class)->collectPayment($subscriber, '0.30');
        $entry = DB::table('transactions')->where('reference_type', Payment::class)->where('reference_id', $legacy->id)->sole();
        $missingLedger = $legacy->replicate();
        $missingLedger->receipt_number = 'QA-R140-NO-LEDGER';
        $missingLedger->sync_uuid = '66666666-6666-4666-8666-666666666666';
        $missingLedger->save();
        $sessions = app(CashSessionService::class);
        $session = $sessions->open($issuer);
        app(GatedCashReceiptWriter::class)->collect($issuer, (int) $subscriber->id, '1.00',
            '88888888-8888-4888-8888-888888888888', $this->nowAt(), (int) $session->id);
        $session = $sessions->decide($sessions->close($session, '1.00'), true, $reviewer);
        $handover = app(\App\Services\CashHandoverService::class)->record($session, $reviewer, '1.00',
            'QA-R140-UNPOSTED', '2026-11-25', self::EVIDENCE);
        $result = $this->gate(fn (array $context) => app(\App\Services\FinancialSourceCoverageDiagnostic::class)->inspect($context['period_id']));
        $this->assertFalse($result['scanned_sources_match']);
        $this->assertContains(['code' => 'required_registry_leg_missing_or_duplicate', 'source' => 'ledger_transaction',
            'id' => $entry->id.':subscriber'], $result['issues']);
        $this->assertContains(['code' => 'document_ledger_missing_duplicate_or_deleted', 'source' => Payment::class,
            'id' => $missingLedger->id.':payment'], $result['issues']);
        $this->assertContains(['code' => 'document_treasury_posting_missing_or_duplicate', 'source' => 'cash_handover_id',
            'id' => (string) $handover->id], $result['issues']);
        $this->assertFalse($result['source_coverage_verified']);
        $this->assertFalse($result['closure_authorized']);
        $this->assertDatabaseMissing('treasury_postings', ['cash_handover_id' => $handover->id]);
        $this->assertDatabaseHas('payments', ['id' => $missingLedger->id]);
    }

    public function test_round140_detects_correctly_hashed_wrong_date_and_split_refund_operation(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $issuer = $this->actor();
        $reviewer = $this->actor();
        $receipt = app(PaymentService::class)->collectPayment($subscriber, '10.00');
        $entry = DB::table('transactions')->where('reference_type', Payment::class)->where('reference_id', $receipt->id)->sole();
        app(FinancialWriteGate::class)->withinOpenPeriod('water', '2026-11-24', $this->nowAt(), function (array $context) use ($entry): void {
            $leg = app(\App\Services\FinancialLedgerEvidence::class)->leg((int) $entry->id);
            $this->assertTrue(app(FinancialPostingRegistry::class)->record($context, '66666666-6666-4666-8666-666666666666', [$leg], $this->nowAt()));
        });
        $refund = app(\App\Services\PaymentRefundService::class)->record($receipt, $reviewer, '1.00',
            'QA-R140-REFUND', 'treasury_cash', '2026-11-25', 'Independent documented excess credit refund', self::EVIDENCE);
        $posting = app(TreasuryRegisterService::class)->post($reviewer, (int) $account['id'], 'payment_refund', (int) $refund->id);
        $refundEntry = DB::table('transactions')->where('reference_type', \App\Models\PaymentRefund::class)->where('reference_id', $refund->id)->sole();
        $this->gate(function (array $context) use ($refundEntry, $posting): void {
            $registry = app(FinancialPostingRegistry::class);
            $this->assertTrue($registry->record($context, '88888888-8888-4888-8888-888888888888',
                [app(\App\Services\FinancialLedgerEvidence::class)->leg((int) $refundEntry->id)], $this->nowAt()));
            $this->assertTrue($registry->record($context, '99999999-9999-4999-8999-999999999999',
                [app(\App\Services\TreasuryCustodyEvidence::class)->leg((int) $posting['id'])], $this->nowAt()));
        });
        $result = $this->gate(fn (array $context) => app(\App\Services\FinancialSourceCoverageDiagnostic::class)->inspect($context['period_id']));
        $this->assertFalse($result['scanned_sources_match']);
        $this->assertContains(['code' => 'registry_effective_date_mismatch', 'source' => 'ledger_transaction',
            'id' => $entry->id.':subscriber'], $result['issues']);
        $this->assertContains(['code' => 'multi_leg_operation_split', 'source' => 'operation_group',
            'id' => 'refund:'.$refund->id], $result['issues']);
        $this->assertFalse($result['source_coverage_verified']);
        $this->assertFalse($result['closure_authorized']);
        $this->assertDatabaseCount('payment_refunds', 1);
    }

    public function test_round141_collection_and_handover_documents_match_exact_operation_membership(): void
    {
        [, , $cash, , , $subscriber] = $this->held();
        $issuer = $this->actor();
        $reviewer = $this->actor();
        $date = '2026-11-25';
        $bank = app(GatedTreasuryOpeningFundingWriter::class)->open($issuer, [
            'code' => 'QA-R141-BANK', 'name' => 'Synthetic bank', 'channel' => 'bank',
            'opening_on' => $date, 'opening_amount' => '0.00',
            'opening_reference' => 'QA-R141-OPEN', 'opening_evidence' => self::EVIDENCE,
        ], '66666666-6666-4666-8666-666666666666', $this->nowAt())['account'];
        $statement = app(\App\Services\BankReconciliationService::class)->import($issuer, (int) $bank['id'], [
            'reference' => 'QA-R141-STMT', 'from_date' => $date, 'to_date' => $date,
            'opening_amount' => '0.00', 'closing_amount' => '0.30', 'evidence' => self::EVIDENCE,
            'lines' => [['bank_reference' => 'QA-R141-IN', 'booked_on' => $date, 'amount' => '0.30']],
        ]);
        $statementId = (int) $statement['statement']['id'];
        $lineId = (int) DB::table('bank_statement_lines')->where('bank_statement_id', $statementId)->value('id');
        $bankUuid = '88888888-8888-4888-8888-888888888888';
        $claim = app(\App\Services\NonCashClaimService::class)->submit($issuer, [
            'sync_uuid' => $bankUuid, 'subscriber_id' => $subscriber->id, 'amount' => '0.30',
            'payment_method' => 'check', 'instrument_reference' => 'QA-R141-CHECK', 'instrument_on' => $date,
        ]);
        $writer = app(\App\Services\GatedCollectionWriter::class);
        $writer->clearClaim($reviewer, (int) $claim['id'], $statementId, $lineId, $date,
            'QA-R141-BANK-PROOF', self::EVIDENCE, $bankUuid, $this->nowAt());
        $sessions = app(CashSessionService::class);
        $session = $sessions->open($issuer);
        app(GatedCashReceiptWriter::class)->collect($issuer, (int) $subscriber->id, '1.00',
            '99999999-9999-4999-8999-999999999999', $this->nowAt(), (int) $session->id);
        $session = $sessions->decide($sessions->close($session, '1.00'), true, $reviewer);
        app(\App\Services\GatedCashHandoverWriter::class)->receive($reviewer, (int) $session->id, (int) $cash['id'],
            '1.00', 'QA-R141-HANDOVER', $date, self::EVIDENCE, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $this->nowAt());
        $lateUuid = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $late = app(\App\Services\LateReceiptReviewService::class)->submit($issuer, [
            'sync_uuid' => $lateUuid, 'cash_session_id' => $session->id, 'subscriber_id' => $subscriber->id,
            'amount' => '0.50', 'payment_method' => 'cash', 'payment_date' => $date,
        ]);
        $writer->receiveLateCash($reviewer, (int) $late['id'], (int) $cash['id'], $date,
            'QA-R141-LATE', self::EVIDENCE, $lateUuid, $this->nowAt());
        $result = $this->gate(fn (array $context) => app(\App\Services\FinancialSourceCoverageDiagnostic::class)->inspect($context['period_id']));
        $this->assertSame([], $result['issues']);
        $this->assertTrue($result['scanned_sources_match']);
        $this->assertSame(9, $result['expected_legs']);
        $this->assertSame(9, $result['matched_legs']);
        $this->assertSame(9, $result['registry_events']);
        $this->assertFalse($result['source_coverage_verified']);
        $this->assertFalse($result['closure_authorized']);
    }

    public function test_round141_rejects_unrelated_receipts_combined_into_valid_registry_operation(): void
    {
        [, , , , , $subscriber] = $this->held();
        $payments = app(PaymentService::class);
        $first = $payments->collectPayment($subscriber, '1.00');
        $second = $payments->collectPayment($subscriber, '2.00');
        $entries = DB::table('transactions')->where('reference_type', Payment::class)
            ->whereIn('reference_id', [$first->id, $second->id])->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->gate(function (array $context) use ($entries): void {
            $ledger = app(\App\Services\FinancialLedgerEvidence::class);
            $legs = $entries->map(fn ($row) => $ledger->leg((int) $row->id))->all();
            $this->assertTrue(app(FinancialPostingRegistry::class)->record($context,
                '66666666-6666-4666-8666-666666666666', $legs, $this->nowAt()));
        });
        $result = $this->gate(fn (array $context) => app(\App\Services\FinancialSourceCoverageDiagnostic::class)->inspect($context['period_id']));
        $this->assertFalse($result['scanned_sources_match']);
        $this->assertSame($result['expected_legs'], $result['matched_legs']);
        foreach ($entries as $entry) {
            $this->assertContains(['code' => 'operation_membership_mismatch', 'source' => 'operation_group',
                'id' => 'single:ledger_transaction|'.$entry->id.'|subscriber'], $result['issues']);
        }
        $this->assertFalse($result['closure_authorized']);
    }

    public function test_round142_continuous_bank_statement_and_counted_cash_proof_are_read_only(): void
    {
        [, , , , , $subscriber] = $this->held();
        $issuer = $this->actor();
        $reviewer = $this->actor();
        $bank = app(GatedTreasuryOpeningFundingWriter::class)->open($issuer, [
            'code' => 'QA-R142-BANK', 'name' => 'Synthetic bank', 'channel' => 'bank',
            'opening_on' => '2026-11-24', 'opening_amount' => '100.00',
            'opening_reference' => 'QA-R142-OPEN', 'opening_evidence' => self::EVIDENCE,
        ], '66666666-6666-4666-8666-666666666666', $this->nowAt())['account'];
        foreach (['2026-11-24', '2026-11-25'] as $day) {
            app(\App\Services\BankReconciliationService::class)->import($issuer, (int) $bank['id'], [
                'reference' => 'QA-R142-'.$day, 'from_date' => $day, 'to_date' => $day,
                'opening_amount' => '100.00', 'closing_amount' => '100.00', 'evidence' => self::EVIDENCE, 'lines' => [],
            ]);
        }
        $sessions = app(CashSessionService::class);
        $session = $sessions->open($issuer);
        app(GatedCashReceiptWriter::class)->collect($issuer, (int) $subscriber->id, '5.00',
            '88888888-8888-4888-8888-888888888888', $this->nowAt(), (int) $session->id);
        $session = $sessions->decide($sessions->close($session, '15.25'), true, $reviewer);
        $owner = Subscriber::create(['name' => 'Synthetic counted owner', 'square_id' => $subscriber->square_id, 'status' => 'active']);
        $uuid = '99999999-9999-4999-8999-999999999999';
        $claim = app(\App\Services\LateReceiptReviewService::class)->submit($issuer, [
            'sync_uuid' => $uuid, 'cash_session_id' => $session->id, 'subscriber_id' => $owner->id,
            'amount' => '4.12', 'payment_method' => 'cash', 'payment_date' => '2026-11-25',
        ]);
        app(\App\Services\GatedCountedCashWriter::class)->allocate($reviewer, (int) $claim['id'], '2026-11-25',
            'QA-R142-COUNTED', self::EVIDENCE, $uuid, $this->nowAt());
        $inspect = fn () => $this->gate(fn (array $context) => app(\App\Services\FinancialSettlementCoverageDiagnostic::class)
            ->inspect($reviewer, $context['period_id'], '2026-11-25'));
        $tables = ['bank_statements', 'bank_statement_lines', 'bank_statement_matches', 'cash_sessions',
            'late_receipt_reviews', 'payments', 'transactions', 'audit_events', 'financial_posting_events'];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        $result = $inspect();
        $this->assertSame([], $result['issues']);
        $this->assertTrue($result['scanned_settlements_match']);
        $this->assertSame(1, $result['bank_accounts_checked']);
        $this->assertSame(2, $result['bank_statements_checked']);
        $this->assertSame(1, $result['counted_reviews_checked']);
        $this->assertSame(1, $result['counted_sessions_verified']);
        $this->assertFalse($result['source_coverage_verified']);
        $this->assertFalse($result['closure_authorized']);
        $this->assertSame($result, $inspect());
        foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
    }

    public function test_round142_bank_gap_and_zero_net_unmatched_lines_are_not_hidden(): void
    {
        [, , , , , $subscriber] = $this->held();
        $issuer = $this->actor();
        $reviewer = $this->actor();
        $bank = app(GatedTreasuryOpeningFundingWriter::class)->open($issuer, [
            'code' => 'QA-R142-GAP', 'name' => 'Synthetic gap bank', 'channel' => 'bank',
            'opening_on' => '2026-11-23', 'opening_amount' => '100.00',
            'opening_reference' => 'QA-R142-GAP-OPEN', 'opening_evidence' => self::EVIDENCE,
        ], '66666666-6666-4666-8666-666666666666', $this->nowAt())['account'];
        $id = (int) $bank['id'];
        $service = app(\App\Services\BankReconciliationService::class);
        $service->import($issuer, $id, [
            'reference' => 'QA-R142-23', 'from_date' => '2026-11-23', 'to_date' => '2026-11-23',
            'opening_amount' => '100.00', 'closing_amount' => '100.00', 'evidence' => self::EVIDENCE, 'lines' => [],
        ]);
        $report = $service->import($issuer, $id, [
            'reference' => 'QA-R142-25', 'from_date' => '2026-11-25', 'to_date' => '2026-11-25',
            'opening_amount' => '100.00', 'closing_amount' => '100.00', 'evidence' => self::EVIDENCE,
            'lines' => [
                ['bank_reference' => 'QA-R142-CREDIT', 'booked_on' => '2026-11-25', 'amount' => '1.00'],
                ['bank_reference' => 'QA-R142-DEBIT', 'booked_on' => '2026-11-25', 'amount' => '-1.00'],
            ],
        ]);
        $result = $this->gate(fn (array $context) => app(\App\Services\FinancialSettlementCoverageDiagnostic::class)
            ->inspect($reviewer, $context['period_id'], '2026-11-25'));
        $this->assertFalse($result['scanned_settlements_match']);
        $this->assertContains(['code' => 'bank_statement_gap', 'source' => 'treasury_accounts', 'id' => $id.':2026-11-24',
            'missing_from' => '2026-11-24', 'missing_until_exclusive' => '2026-11-25'], $result['issues']);
        $this->assertContains(['code' => 'bank_statement_unresolved', 'source' => 'bank_statements',
            'id' => (string) $report['statement']['id'], 'unmatched_bank_count' => 2,
            'unmatched_ledger_count' => 0, 'bank_unmatched_minor' => 0, 'ledger_unmatched_minor' => 0,
            'opening_difference_minor' => 0, 'closing_difference_minor' => 0, 'integrity_matches' => true], $result['issues']);
        $this->assertFalse($result['source_coverage_verified']);
        $this->assertFalse($result['closure_authorized']);
        $this->assertDatabaseCount('bank_statement_matches', 0);
    }

    public function test_release_settlement_filament_counted_cash_modal_and_api_replay(): void
    {
        [, , , , , $subscriber] = $this->held();
        $issuer = $this->actor();
        $reviewer = $this->actor();
        foreach (['ViewAny:CashSession', 'View:CashSession'] as $permission) {
            $reviewer->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'));
        }
        $sessions = app(CashSessionService::class);
        $session = $sessions->open($issuer);
        app(GatedCashReceiptWriter::class)->collect($issuer, (int) $subscriber->id, '5.00',
            '66666666-6666-4666-8666-666666666666', $this->nowAt(), (int) $session->id);
        $session = $sessions->decide($sessions->close($session, '15.25'), true, $reviewer);
        $owner = Subscriber::create(['name' => 'Synthetic modal counted owner', 'square_id' => $subscriber->square_id, 'status' => 'active']);
        $late = app(\App\Services\LateReceiptReviewService::class);
        $uuid = '88888888-8888-4888-8888-888888888888';
        $id = (int) $late->submit($issuer, ['sync_uuid' => $uuid, 'cash_session_id' => $session->id,
            'subscriber_id' => $owner->id, 'amount' => '4.12', 'payment_method' => 'cash', 'payment_date' => '2026-11-25'])['id'];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($r) => (array) $r)->all();
        $tables = ['cash_sessions', 'treasury_postings', 'payments', 'transactions', 'late_receipt_reviews',
            'financial_posting_operations', 'financial_posting_events', 'audit_events', 'document_sequences'];
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        config(['financial_candidate.late_cash_settlements' => true]);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        $this->actingAs($reviewer, 'web');
        $page = \App\Filament\Resources\CashSessions\Pages\ListCashSessions::class;
        $action = \Filament\Actions\Testing\TestAction::make('late_receipt_review')->table($session);
        $data = ['review_id' => $id, 'decision' => 'approve', 'settlement_method' => 'already_counted',
            'evidence_reference' => 'QA-COUNTED-MODAL', 'evidence' => self::EVIDENCE];
        $modal = \Livewire\Livewire::test($page)->mountAction($action)->setActionData(array_replace($data, ['evidence_reference' => null]));
        $modal->callMountedAction()->assertHasActionErrors();
        $modal->setActionData($data);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'CLOSED']);
        $modal->callMountedAction()->assertHasActionErrors();
        foreach ($tables as $table) $this->assertSame($before[$table], $snapshot($table), $table);
        $modal->assertActionDataSet(['review_id' => $id, 'evidence_reference' => 'QA-COUNTED-MODAL']);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'OPEN']);
        $modal->callMountedAction()->assertHasNoActionErrors();
        $payment = Payment::where('sync_uuid', $uuid)->sole();
        $this->assertSame(412, $owner->fresh()->getAccountBalanceMinor());
        $this->assertSame($before['cash_sessions'], $snapshot('cash_sessions'));
        $this->assertSame($before['treasury_postings'], $snapshot('treasury_postings'));
        $events = DB::table('financial_posting_events')->where('operation_uuid', $uuid)->get();
        $this->assertCount(1, $events);
        $this->assertSame('subscriber', $events->sole()->leg);
        $this->assertSame(412, (int) $events->sole()->amount_minor);
        $committed = [];
        foreach ($tables as $table) $committed[$table] = $snapshot($table);
        \Laravel\Sanctum\Sanctum::actingAs($reviewer);
        $url = '/api/late-receipt-reviews/'.$id.'/decide';
        $api = ['approve' => true, 'settlement_method' => 'already_counted',
            'evidence_reference' => $data['evidence_reference'], 'evidence' => $data['evidence']];
        $this->postJson($url, $api)->assertOk()->assertJsonPath('review.payment_id', $payment->id);
        foreach ($committed as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
    }

    public function test_release_settlement_api_counted_cash(): void
    {
        [, , , , , $subscriber] = $this->held();
        $issuer = $this->actor();
        $reviewer = $this->actor();
        $sessions = app(CashSessionService::class);
        $session = $sessions->open($issuer);
        app(GatedCashReceiptWriter::class)->collect($issuer, (int) $subscriber->id, '5.00',
            '66666666-6666-4666-8666-666666666666', $this->nowAt(), (int) $session->id);
        $session = $sessions->decide($sessions->close($session, '15.25'), true, $reviewer);
        $owner = Subscriber::create(['name' => 'Synthetic API counted owner', 'square_id' => $subscriber->square_id, 'status' => 'active']);
        $late = app(\App\Services\LateReceiptReviewService::class);
        $uuid = '88888888-8888-4888-8888-888888888888';
        $id = (int) $late->submit($issuer, ['sync_uuid' => $uuid, 'cash_session_id' => $session->id,
            'subscriber_id' => $owner->id, 'amount' => '4.12', 'payment_method' => 'cash', 'payment_date' => '2026-11-25'])['id'];
        $url = '/api/late-receipt-reviews/'.$id.'/decide';
        $data = ['approve' => true, 'settlement_method' => 'already_counted',
            'evidence_reference' => 'QA-COUNTED-API', 'evidence' => self::EVIDENCE];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($r) => (array) $r)->all();
        $tables = ['cash_sessions', 'treasury_postings', 'payments', 'transactions', 'late_receipt_reviews',
            'financial_posting_operations', 'financial_posting_events', 'audit_events', 'document_sequences'];
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        config(['financial_candidate.late_cash_settlements' => true]);
        \Laravel\Sanctum\Sanctum::actingAs($issuer);
        $this->postJson($url, $data)->assertForbidden();
        \Laravel\Sanctum\Sanctum::actingAs($reviewer);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'CLOSED']);
        $this->postJson($url, $data)->assertConflict();
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'OPEN']);
        foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $payment = $this->postJson($url, $data)->assertOk()->assertJsonPath('review.status', 'approved')->json('review.payment_id');
        $this->assertNotNull($payment);
        $this->assertSame(412, $owner->fresh()->getAccountBalanceMinor());
        $this->assertSame($before['cash_sessions'], $snapshot('cash_sessions'));
        $this->assertSame($before['treasury_postings'], $snapshot('treasury_postings'));
        $events = DB::table('financial_posting_events')->where('operation_uuid', $uuid)->get();
        $this->assertCount(1, $events);
        $this->assertSame('subscriber', $events->sole()->leg);
        $this->assertSame(412, (int) $events->sole()->amount_minor);
        $committed = [];
        foreach ($tables as $table) $committed[$table] = $snapshot($table);
        $this->postJson($url, $data)->assertOk()->assertJsonPath('review.payment_id', $payment);
        config(['financial_candidate.late_cash_settlements' => false]);
        $this->postJson($url, $data)->assertConflict();
        foreach ($committed as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
    }

    public function test_release_counted_cash_fence_preserves_capacity_and_single_ledger_effect(): void
    {
        [, , , , , $subscriber] = $this->held();
        $issuer = $this->actor();
        $reviewer = $this->actor();
        $sessions = app(CashSessionService::class);
        $session = $sessions->open($issuer);
        app(GatedCashReceiptWriter::class)->collect($issuer, (int) $subscriber->id, '5.00',
            '66666666-6666-4666-8666-666666666666', $this->nowAt(), (int) $session->id);
        $session = $sessions->decide($sessions->close($session, '15.25'), true, $reviewer);
        $owner = Subscriber::create(['name' => 'Synthetic fenced counted owner', 'square_id' => $subscriber->square_id, 'status' => 'active']);
        $late = app(\App\Services\LateReceiptReviewService::class);
        $writer = app(\App\Services\GatedCountedCashWriter::class);
        $uuid = '88888888-8888-4888-8888-888888888888';
        $other = '99999999-9999-4999-8999-999999999999';
        $submit = fn (string $key, string $amount) => $late->submit($issuer, [
            'sync_uuid' => $key, 'cash_session_id' => $session->id, 'subscriber_id' => $owner->id,
            'amount' => $amount, 'payment_method' => 'cash', 'payment_date' => '2026-11-25',
        ]);
        $id = (int) $submit($uuid, '4.12')['id'];
        $over = (int) $submit($other, '6.14')['id'];
        $data = ['approve' => true, 'settlement_method' => 'already_counted',
            'evidence_reference' => 'QA-COUNTED-FENCE', 'evidence' => self::EVIDENCE];
        $direct = fn (?\App\Services\GatedCountedCashWriter $candidate = null) =>
            $late->decide($id, $reviewer, $data, $candidate);
        $issue = fn (?\App\Services\GatedCountedCashWriter $candidate = null) =>
            app(PaymentService::class)->collectPayment($owner, '4.12', userId: $issuer->id,
                paymentDate: '2026-11-25', syncUuid: $uuid, lateReviewId: $id, settlementWriter: $candidate);
        $act = fn () => $writer->allocate($reviewer, $id, '2026-11-25',
            'QA-COUNTED-FENCE', self::EVIDENCE, $uuid, $this->nowAt());
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($r) => (array) $r)->all();
        $tables = ['cash_sessions', 'treasury_postings', 'payments', 'transactions', 'late_receipt_reviews',
            'financial_posting_operations', 'financial_posting_events', 'audit_events', 'document_sequences'];
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        config(['financial_candidate.late_cash_settlements' => true]);
        $reason = 'gated_late_cash_writer_required';
        $this->rejects($reason, $direct);
        $this->rejects($reason, $issue);
        $this->rejects($reason, fn () => DB::transaction(fn () => $direct($writer)));
        $this->rejects($reason, fn () => DB::transaction(fn () => $issue($writer)));
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'CLOSED']);
        $this->rejects('financial_period_not_open', $act);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'OPEN']);
        foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $result = $act();
        $this->assertTrue($result['registry_created']);
        $this->assertSame(1025, $result['allocation']['capacity_minor']);
        $this->assertSame(412, $result['allocation']['allocated_minor']);
        $this->assertSame(613, $result['allocation']['available_minor']);
        $this->assertSame(412, $owner->fresh()->getAccountBalanceMinor());
        $this->assertSame($before['cash_sessions'], $snapshot('cash_sessions'));
        $this->assertSame($before['treasury_postings'], $snapshot('treasury_postings'));
        $events = DB::table('financial_posting_events')->where('operation_uuid', $uuid)->get();
        $this->assertCount(1, $events);
        $this->assertSame('subscriber', $events->sole()->leg);
        $this->assertSame(412, (int) $events->sole()->amount_minor);
        $this->assertFalse($writer->allowsMutation('already_counted', $id, $uuid));
        $committed = [];
        foreach ($tables as $table) $committed[$table] = $snapshot($table);
        $this->rejects('gated_counted_capacity_exceeded', fn () => $writer->allocate($reviewer,
            $over, '2026-11-25', 'QA-COUNTED-OVER', self::EVIDENCE, $other, $this->nowAt()));
        config(['financial_candidate.late_cash_settlements' => false]);
        $this->rejects($reason, $direct);
        $this->rejects($reason, $issue);
        $this->assertFalse($act()['registry_created']);
        foreach ($committed as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $this->assertDatabaseMissing('financial_posting_operations', ['operation_uuid' => $other]);
    }

    public function test_round142_unregistered_counted_cash_decision_is_reported_without_adoption(): void
    {
        [, , , , , $subscriber] = $this->held();
        $issuer = $this->actor();
        $reviewer = $this->actor();
        $sessions = app(CashSessionService::class);
        $session = $sessions->open($issuer);
        app(GatedCashReceiptWriter::class)->collect($issuer, (int) $subscriber->id, '5.00',
            '66666666-6666-4666-8666-666666666666', $this->nowAt(), (int) $session->id);
        $session = $sessions->decide($sessions->close($session, '15.25'), true, $reviewer);
        $uuid = '88888888-8888-4888-8888-888888888888';
        $claim = app(\App\Services\LateReceiptReviewService::class)->submit($issuer, [
            'sync_uuid' => $uuid, 'cash_session_id' => $session->id, 'subscriber_id' => $subscriber->id,
            'amount' => '4.12', 'payment_method' => 'cash', 'payment_date' => '2026-11-25',
        ]);
        app(\App\Services\LateReceiptReviewService::class)->decide((int) $claim['id'], $reviewer, [
            'approve' => true, 'settlement_method' => 'already_counted',
            'evidence_reference' => 'QA-R142-LEGACY', 'evidence' => self::EVIDENCE,
        ]);
        $result = $this->gate(fn (array $context) => app(\App\Services\FinancialSettlementCoverageDiagnostic::class)
            ->inspect($reviewer, $context['period_id'], '2026-11-25'));
        $this->assertFalse($result['scanned_settlements_match']);
        $this->assertSame(1, $result['counted_reviews_checked']);
        $this->assertSame(0, $result['counted_sessions_verified']);
        $this->assertContains('counted_cash_session_unverified', array_column($result['issues'], 'code'));
        $this->assertFalse($result['closure_authorized']);
        $this->assertDatabaseMissing('financial_posting_operations', ['operation_uuid' => $uuid]);
        $this->assertDatabaseHas('late_receipt_reviews', ['id' => $claim['id'], 'status' => 'approved']);
    }

    public function test_round143_current_subscriber_balances_and_allocations_do_not_certify_period_opening(): void
    {
        [, , , , $lot, $subscriber] = $this->held();
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 20);
        $issuer = $this->actor();
        $reviewer = $this->actor();
        \App\Models\Tariff::create(['name' => 'Synthetic subscriber diagnostic tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-R143-CREDIT']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $writer = app(\App\Services\GatedInvoiceTransferWriter::class);
        $writer->issue($issuer, (int) $subscriber->id, (int) $meter->id,
            '66666666-6666-4666-8666-666666666666', $this->nowAt());
        $debtor = Subscriber::create(['name' => 'Synthetic current debtor', 'square_id' => $subscriber->square_id, 'status' => 'active']);
        $meter2 = \App\Models\Meter::create(['subscriber_id' => $debtor->id, 'unique_code' => 'QA-R143-DEBT']);
        $this->createMeasuredReading(['meter_id' => $meter2->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 3]);
        $writer->issue($issuer, (int) $debtor->id, (int) $meter2->id,
            '88888888-8888-4888-8888-888888888888', $this->nowAt());
        $idle = Subscriber::create(['name' => 'Synthetic idle', 'square_id' => $subscriber->square_id, 'status' => 'active']);
        $deleted = Subscriber::create(['name' => 'Synthetic empty deleted account', 'square_id' => $subscriber->square_id, 'status' => 'active']);
        $deleted->delete();
        $inspect = fn () => $this->gate(fn (array $context) => app(\App\Services\FinancialSubscriberCoverageDiagnostic::class)->inspect($context['period_id']));
        $tables = ['subscribers', 'transactions', 'invoices', 'payment_allocations', 'payment_allocation_revisions',
            'financial_posting_events', 'financial_posting_operations', 'audit_events'];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        $result = $inspect();
        $this->assertSame([], $result['issues']);
        $this->assertSame([], $result['period_source_issues']);
        $this->assertTrue($result['scanned_accounts_match']);
        $this->assertFalse($result['opening_verified']);
        $this->assertFalse($result['period_closing_verified']);
        $this->assertFalse($result['closure_authorized']);
        $rows = collect($result['rows'])->keyBy('subscriber_id');
        $this->assertSame(10, $rows[$subscriber->id]['current_ledger_minor']);
        $this->assertSame(20, $rows[$subscriber->id]['current_available_minor']);
        $this->assertSame(10, $rows[$subscriber->id]['current_allocated_minor']);
        $this->assertSame(10, $rows[$subscriber->id]['current_surplus_minor']);
        $this->assertSame(-30, $rows[$debtor->id]['current_ledger_minor']);
        $this->assertSame(0, $rows[$idle->id]['current_ledger_minor']);
        $this->assertFalse($rows->has($deleted->id));
        foreach ($rows as $row) {
            $this->assertNull($row['opening_minor']);
            $this->assertNull($row['period_closing_minor']);
        }
        $this->assertSame(-20, $result['totals']['registered_movement_minor']);
        $this->assertSame(-20, $result['totals']['current_ledger_minor']);
        $this->assertSame(30, $result['totals']['current_debt_minor']);
        $this->assertSame(10, $result['totals']['current_credit_minor']);
        $this->assertSame($result, $inspect());
        foreach ($before as $table => $records) $this->assertSame($records, $snapshot($table), $table);
    }

    public function test_round143_unregistered_current_receipt_is_not_hidden_by_matching_account_totals(): void
    {
        [, , , , , $subscriber] = $this->held();
        app(PaymentService::class)->collectPayment($subscriber, '1.00');
        $result = $this->gate(fn (array $context) => app(\App\Services\FinancialSubscriberCoverageDiagnostic::class)->inspect($context['period_id']));
        $row = collect($result['rows'])->firstWhere('subscriber_id', $subscriber->id);
        $this->assertTrue($row['matches']);
        $this->assertSame(100, $row['current_ledger_minor']);
        $this->assertSame(100, $row['current_document_balance_minor']);
        $this->assertSame(0, $row['registered_movement_minor']);
        $this->assertFalse($result['scanned_accounts_match']);
        $this->assertContains('subscriber_period_sources_unmatched', array_column($result['issues'], 'code'));
        $this->assertContains('required_registry_leg_missing_or_duplicate', array_column($result['period_source_issues'], 'code'));
        $this->assertNull($row['opening_minor']);
        $this->assertFalse($result['closure_authorized']);
    }

    public function test_round143_invoice_paid_projection_mismatch_is_detected_without_repair(): void
    {
        [, , , , , $subscriber] = $this->held();
        $actor = $this->actor();
        \App\Models\Tariff::create(['name' => 'Synthetic stale allocation tariff', 'unit_price' => '1.00', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-R143-STALE']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $invoice = app(\App\Services\GatedInvoiceTransferWriter::class)->issue($actor, (int) $subscriber->id, (int) $meter->id,
            '66666666-6666-4666-8666-666666666666', $this->nowAt())['invoice'];
        app(GatedCashReceiptWriter::class)->collect($actor, (int) $subscriber->id, '0.50',
            '88888888-8888-4888-8888-888888888888', $this->nowAt());
        $this->assertSame('0.50', $invoice->fresh()->amount_paid);
        DB::table('invoices')->where('id', $invoice->id)->update(['amount_paid' => '0.25']);
        $result = $this->gate(fn (array $context) => app(\App\Services\FinancialSubscriberCoverageDiagnostic::class)->inspect($context['period_id']));
        $this->assertSame([], $result['period_source_issues']);
        $this->assertFalse($result['scanned_accounts_match']);
        $this->assertContains('allocation_invoice_mismatch', array_column(array_column($result['issues'], 'detail'), 'code'));
        $this->assertSame('0.25', $invoice->fresh()->amount_paid);
        $this->assertFalse($result['period_closing_verified']);
        $this->assertFalse($result['closure_authorized']);
    }

    public function test_release_void_approval_is_bound_to_latest_request_and_exact_replay(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 20);
        $issuer = $this->actor();
        $reviewer = $this->actor();
        \App\Models\Setting::set('void_approval_threshold', '0.30');
        $payment = app(GatedCashReceiptWriter::class)->collect($issuer, (int) $subscriber->id, '0.30',
            '66666666-6666-4666-8666-666666666666', $this->nowAt())['payment'];
        $writer = app(\App\Services\GatedReceiptVoidWriter::class);
        $reason = 'Independent documented receipt cancellation';
        $id = (int) $payment->id;
        $first = $writer->request($issuer, $id, $reason, $this->nowAt())['request_id'];
        $writer->decide($reviewer, $id, false, 'Rejected first request', $this->nowAt(), requestId: $first);
        $second = $writer->request($issuer, $id, $reason, $this->nowAt())['request_id'];
        $this->assertNotSame($first, $second);
        $uuid = '88888888-8888-4888-8888-888888888888';
        $approve = fn (?int $requestId) => $writer->decide($reviewer, $id, true, 'Independent decision',
            $this->nowAt(), $uuid, $requestId);
        $tables = ['payments', 'transactions', 'payment_allocations', 'payment_allocation_revisions',
            'financial_posting_events', 'financial_posting_operations', 'audit_events', 'treasury_postings'];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        $this->rejects('gated_void_request_id_required', fn () => $approve(null));
        $this->rejects('gated_void_replay_conflict', fn () => $approve($first));
        $this->rejects('gated_void_request_id_required', fn () => $writer->decide($reviewer, $id, false,
            'No request identity', $this->nowAt()));
        $this->assertTrue($payment->fresh()->hasPendingVoidRequest());
        foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $this->assertTrue($approve($second)['registry_created']);
        $this->assertTrue($payment->fresh()->isVoided());
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $uuid, 'amount_minor' => -30]);
        $committed = [];
        foreach ($tables as $table) $committed[$table] = $snapshot($table);
        $this->assertFalse($approve($second)['registry_created']);
        $this->rejects('gated_void_replay_conflict', fn () => $approve($first));
        $this->rejects('gated_void_request_id_required', fn () => $approve(null));
        foreach ($committed as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $this->assertSame(20, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $account['id'])));
    }

    public function test_release_void_api_uses_exact_request_identity_and_gate_without_legacy_fallback(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $issuer = $this->actor();
        $reviewer = $this->actor();
        $outsider = User::factory()->create(['role' => 'subscriber']);
        $receipt = app(GatedCashReceiptWriter::class)->collect($issuer, (int) $subscriber->id, '1.00',
            '66666666-6666-4666-8666-666666666666', $this->nowAt())['payment'];
        \App\Models\Setting::set('void_approval_threshold', '0.30');
        config(['financial_candidate.void_api' => true]);
        $url = '/api/payments/'.$receipt->id;
        $reason = 'Independent documented receipt cancellation';
        \Laravel\Sanctum\Sanctum::actingAs($outsider);
        $this->postJson($url.'/void', ['reason' => $reason])->assertForbidden();
        \Laravel\Sanctum\Sanctum::actingAs($issuer);
        $first = $this->postJson($url.'/void', ['reason' => $reason, 'intent_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'])->assertStatus(202)
            ->assertJsonPath('pending_approval', true)->json('request_id');
        $this->assertIsInt($first);
        $this->postJson($url.'/void-decision', ['approve' => false, 'request_id' => $first])->assertForbidden();
        \Laravel\Sanctum\Sanctum::actingAs($reviewer);
        $this->getJson('/api/payments/void-requests')->assertOk()->assertJsonPath('data.0.request_id', $first);
        $this->postJson($url.'/void-decision', ['approve' => false])->assertUnprocessable();
        $reject = ['approve' => false, 'request_id' => $first, 'notes' => 'Rejected independently'];
        $this->postJson($url.'/void-decision', $reject)->assertOk();
        $this->postJson($url.'/void-decision', $reject)->assertOk();
        \Laravel\Sanctum\Sanctum::actingAs($issuer);
        $second = $this->postJson($url.'/void', ['reason' => $reason, 'intent_uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'])->assertStatus(202)->json('request_id');
        $this->assertNotSame($first, $second);
        \Laravel\Sanctum\Sanctum::actingAs($reviewer);
        $uuid = '88888888-8888-4888-8888-888888888888';
        $approve = ['approve' => true, 'request_id' => $second, 'operation_uuid' => $uuid, 'notes' => 'Approved independently'];
        $this->postJson($url.'/void-decision', array_replace($approve, ['request_id' => $first]))->assertConflict();
        $this->postJson($url.'/void-decision', array_diff_key($approve, ['operation_uuid' => true]))->assertUnprocessable();
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'CLOSED']);
        $this->postJson($url.'/void-decision', $approve)->assertConflict();
        $this->assertTrue($receipt->fresh()->hasPendingVoidRequest());
        $this->assertDatabaseMissing('financial_posting_operations', ['operation_uuid' => $uuid]);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'OPEN']);
        $this->postJson($url.'/void-decision', $approve)->assertOk();
        $this->assertTrue($receipt->fresh()->isVoided());
        $count = DB::table('audit_events')->count();
        $this->postJson($url.'/void-decision', $approve)->assertOk();
        $this->assertSame($count, DB::table('audit_events')->count());
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $uuid, 'amount_minor' => -100]);
        $this->assertSame(1, DB::table('financial_posting_events')->where('operation_uuid', $uuid)->count());
        $this->assertSame(0, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $account['id'])));
    }

    public function test_release_void_api_immediate_void_and_unregistered_receipt_refusal(): void
    {
        [, , , , , $subscriber] = $this->held();
        $issuer = $this->actor();
        \Laravel\Sanctum\Sanctum::actingAs($issuer);
        \App\Models\Setting::set('void_approval_threshold', '0.00');
        $receipt = app(GatedCashReceiptWriter::class)->collect($issuer, (int) $subscriber->id, '1.00',
            '66666666-6666-4666-8666-666666666666', $this->nowAt())['payment'];
        $legacy = app(PaymentService::class)->collectPayment($subscriber, '2.00');
        $this->assertFalse(config('financial_candidate.void_api'));
        config(['financial_candidate.void_api' => true]);
        $data = ['reason' => 'Independent documented immediate cancellation', 'operation_uuid' => '88888888-8888-4888-8888-888888888888'];
        $this->postJson('/api/payments/'.$legacy->id.'/void', $data)->assertConflict();
        $this->assertFalse($legacy->fresh()->isVoided());
        $this->postJson('/api/payments/'.$receipt->id.'/void', ['reason' => $data['reason']])->assertConflict();
        $this->postJson('/api/payments/'.$receipt->id.'/void', $data)->assertOk()->assertJsonPath('pending_approval', false);
        $this->postJson('/api/payments/'.$receipt->id.'/void', $data)->assertOk()->assertJsonPath('pending_approval', false);
        $this->assertSame(200, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $data['operation_uuid'], 'amount_minor' => -100]);
    }

    public function test_release_void_filament_mounts_exact_request_and_refuses_stale_modal(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $issuer = $this->actor();
        $reviewer = $this->actor();
        foreach ([$issuer, $reviewer] as $user) {
            $user->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('ViewAny:Payment', 'web'));
            $user->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('View:Payment', 'web'));
        }
        $receipt = app(GatedCashReceiptWriter::class)->collect($issuer, (int) $subscriber->id, '1.00',
            '66666666-6666-4666-8666-666666666666', $this->nowAt())['payment'];
        \App\Models\Setting::set('void_approval_threshold', '0.30');
        config(['financial_candidate.void_api' => true]);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        $page = \App\Filament\Resources\PaymentResource\Pages\ListPayments::class;
        $action = fn (string $name) => \Filament\Actions\Testing\TestAction::make($name)->table($receipt);
        $reason = 'Independent documented receipt cancellation';
        $this->actingAs($issuer, 'web');
        \Livewire\Livewire::test($page)->callAction($action('prospective_void_request'), data: ['reason' => $reason])
            ->assertHasNoActionErrors();
        $this->assertTrue($receipt->fresh()->hasPendingVoidRequest());
        $first = (int) AuditEvent::query()->where('event', 'financial.void_request_gated')->where('entity_id', (string) $receipt->id)->latest('id')->value('id');
        $this->assertGreaterThan(0, $first);
        $this->actingAs($reviewer, 'web');
        $modal = \Livewire\Livewire::test($page)->mountAction($action('prospective_void_approve'))
            ->assertActionDataSet(['request_id' => $first]);
        $writer = app(\App\Services\GatedReceiptVoidWriter::class);
        $writer->decide($reviewer, (int) $receipt->id, false, 'Independent rejection', $this->nowAt(), requestId: $first);
        $second = $writer->request($issuer, (int) $receipt->id, $reason, $this->nowAt())['request_id'];
        $this->assertNotSame($first, $second);
        $before = DB::table('financial_posting_events')->count();
        $modal->callMountedAction()->assertHasActionErrors();
        $this->assertTrue($receipt->fresh()->hasPendingVoidRequest());
        $this->assertSame($before, DB::table('financial_posting_events')->count());
        \Livewire\Livewire::test($page)->mountAction($action('prospective_void_approve'))
            ->assertActionDataSet(['request_id' => $second])->callMountedAction()->assertHasNoActionErrors();
        $this->assertTrue($receipt->fresh()->isVoided());
        $this->assertSame($before + 1, DB::table('financial_posting_events')->count());
        $this->assertSame(0, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $account['id'])));
    }

    public function test_release_void_mobile_decision_does_not_downgrade_and_echoes_identity(): void
    {
        [, , , , , $subscriber] = $this->held();
        $issuer = $this->actor();
        $reviewer = $this->actor();
        $receipt = app(GatedCashReceiptWriter::class)->collect($issuer, (int) $subscriber->id, '1.00',
            '66666666-6666-4666-8666-666666666666', $this->nowAt())['payment'];
        \App\Models\Setting::set('void_approval_threshold', '0.30');
        $request = app(\App\Services\GatedReceiptVoidWriter::class)->request($issuer, (int) $receipt->id,
            'Independent documented receipt cancellation', $this->nowAt())['request_id'];
        \Laravel\Sanctum\Sanctum::actingAs($reviewer);
        $url = '/api/payments/'.$receipt->id.'/void-decision';
        $uuid = '88888888-8888-5888-8888-888888888888';
        $approve = ['approve' => true, 'request_id' => $request, 'operation_uuid' => $uuid];
        config(['financial_candidate.void_api' => false]);
        $this->postJson($url, $approve)->assertConflict();
        $this->assertTrue($receipt->fresh()->hasPendingVoidRequest());
        $this->assertDatabaseMissing('financial_posting_operations', ['operation_uuid' => $uuid]);
        config(['financial_candidate.void_api' => true]);
        $this->postJson($url, $approve)->assertOk()->assertJsonPath('request_id', $request)
            ->assertJsonPath('operation_uuid', $uuid)->assertJsonPath('payment.id', $receipt->id);
        $this->postJson($url, $approve)->assertOk()->assertJsonPath('request_id', $request);
        $this->assertTrue($receipt->fresh()->isVoided());
        $this->assertSame(1, DB::table('financial_posting_events')->where('operation_uuid', $uuid)->count());
    }

    public function test_release_void_service_fence_blocks_legacy_paths_even_when_flag_disabled(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $issuer = $this->actor();
        $reviewer = $this->actor();
        $receipt = app(GatedCashReceiptWriter::class)->collect($issuer, (int) $subscriber->id, '1.00',
            '66666666-6666-4666-8666-666666666666', $this->nowAt())['payment'];
        $service = app(PaymentService::class);
        $writer = app(\App\Services\GatedReceiptVoidWriter::class);
        $reason = 'Independent documented receipt cancellation';
        $tables = ['payments', 'transactions', 'payment_allocations', 'payment_allocation_revisions',
            'financial_posting_events', 'financial_posting_operations', 'audit_events', 'treasury_postings'];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        $unchanged = function (callable $action) use ($tables, $snapshot): void {
            $before = [];
            foreach ($tables as $table) $before[$table] = $snapshot($table);
            try {
                $action();
                $this->fail('Protected receipt accepted an unguarded mutation.');
            } catch (\Illuminate\Validation\ValidationException $error) {
                $this->assertArrayHasKey('payment', $error->errors());
            }
            foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        };
        config(['financial_candidate.void_api' => false]);
        \App\Models\Setting::set('void_approval_threshold', '0.00');
        $unchanged(fn () => $service->requestVoid($receipt, $reason, $issuer->id));
        $unchanged(fn () => $service->voidPayment($receipt, $reason, $reviewer->id, 'gated'));
        $unchanged(fn () => $service->voidPayment($receipt, $reason, $reviewer->id, 'gated', $writer));
        \App\Models\Setting::set('void_approval_threshold', '0.30');
        $id = (int) $receipt->id;
        $request = $writer->request($issuer, $id, $reason, $this->nowAt())['request_id'];
        $this->assertTrue($receipt->fresh()->hasPendingVoidRequest());
        foreach ([true, false] as $approve) {
            $unchanged(fn () => $service->decideVoidRequest($receipt, $approve, $reviewer, 'Old client'));
            $unchanged(fn () => $service->decideVoidRequest($receipt, $approve, $reviewer, 'Spoofed source', 'gated', $writer));
        }
        \Laravel\Sanctum\Sanctum::actingAs($reviewer);
        $before = DB::table('audit_events')->count();
        $this->postJson('/api/payments/'.$id.'/void-decision', ['approve' => true])->assertUnprocessable();
        $this->postJson('/api/payments/'.$id.'/void-decision', ['approve' => false])->assertUnprocessable();
        $this->assertSame($before, DB::table('audit_events')->count());
        $this->assertTrue($receipt->fresh()->hasPendingVoidRequest());
        $this->assertFalse($receipt->fresh()->isVoided());
        $writer->decide($reviewer, $id, false, 'Independent rejection', $this->nowAt(), requestId: $request);
        $unchanged(fn () => $service->requestVoid($receipt, $reason, $issuer->id));
        $second = $writer->request($issuer, $id, $reason, $this->nowAt())['request_id'];
        $uuid = '88888888-8888-4888-8888-888888888888';
        $this->assertTrue($writer->decide($reviewer, $id, true, null, $this->nowAt(), $uuid, $second)['registry_created']);
        $this->assertFalse($writer->allowsMutation($receipt));
        $this->assertFalse($writer->decide($reviewer, $id, true, null, $this->nowAt(), $uuid, $second)['registry_created']);
        $this->assertSame(1, DB::table('financial_posting_events')->where('operation_uuid', $uuid)->count());
        $this->assertSame(0, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $account['id'])));
    }

    public function test_release_void_service_fence_preserves_unregistered_legacy_receipt_behavior(): void
    {
        [, , , , , $subscriber] = $this->held();
        $issuer = $this->actor();
        $reviewer = $this->actor();
        $service = app(PaymentService::class);
        $legacy = $service->collectPayment($subscriber, '1.00');
        \App\Models\Setting::set('void_approval_threshold', '0.30');
        $reason = 'Independent documented legacy cancellation';
        $this->assertTrue($service->requestVoid($legacy, $reason, $issuer->id)['pending']);
        $this->assertFalse($service->decideVoidRequest($legacy, false, $reviewer, 'Independent rejection')->isVoided());
        $this->assertTrue($service->requestVoid($legacy, $reason, $issuer->id)['pending']);
        $this->assertTrue($service->decideVoidRequest($legacy, true, $reviewer)->isVoided());
        $this->assertSame(0, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
    }

    public function test_release_void_intent_replays_pending_and_immediate_but_never_reopens_rejected_request(): void
    {
        [, , , , , $subscriber] = $this->held();
        $issuer = $this->actor();
        $reviewer = $this->actor();
        $receipt = app(GatedCashReceiptWriter::class)->collect($issuer, (int) $subscriber->id, '1.00',
            '66666666-6666-4666-8666-666666666666', $this->nowAt())['payment'];
        \App\Models\Setting::set('void_approval_threshold', '0.30');
        $reason = 'Independent documented void intent';
        $intent = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $url = '/api/payments/'.$receipt->id;
        $data = ['reason' => $reason, 'intent_uuid' => $intent];
        \Laravel\Sanctum\Sanctum::actingAs($issuer);
        config(['financial_candidate.void_api' => false]);
        $this->postJson($url.'/void', $data)->assertConflict();
        config(['financial_candidate.void_api' => true]);
        $this->postJson($url.'/void', ['reason' => $reason])->assertConflict();
        $request = $this->postJson($url.'/void', $data)->assertStatus(202)
            ->assertJsonPath('intent_uuid', $intent)->json('request_id');
        $count = DB::table('audit_events')->count();
        $this->postJson($url.'/void', $data)->assertStatus(202)->assertJsonPath('request_id', $request);
        $this->assertSame($count, DB::table('audit_events')->count());
        $this->postJson($url.'/void', array_replace($data, ['reason' => 'Different documented reason']))->assertConflict();
        $this->assertDatabaseMissing('financial_posting_operations', ['operation_uuid' => $intent]);
        \Laravel\Sanctum\Sanctum::actingAs($reviewer);
        $this->postJson($url.'/void-decision', ['approve' => false, 'request_id' => $request])->assertOk();
        \Laravel\Sanctum\Sanctum::actingAs($issuer);
        $this->postJson($url.'/void', $data)->assertConflict();
        $this->assertFalse($receipt->fresh()->hasPendingVoidRequest());
        $this->assertFalse($receipt->fresh()->isVoided());
        $this->assertSame(100, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        \App\Models\Setting::set('void_approval_threshold', '0.00');
        $next = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $data['intent_uuid'] = $next;
        $this->postJson($url.'/void', $data)->assertOk()->assertJsonPath('intent_uuid', $next)
            ->assertJsonPath('pending_approval', false);
        $this->postJson($url.'/void', $data)->assertOk()->assertJsonPath('intent_uuid', $next);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $next, 'amount_minor' => -100]);
        $this->assertSame(1, DB::table('financial_posting_events')->where('operation_uuid', $next)->count());
        $this->assertSame(0, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
    }

    public function test_release_cash_api_all_four_entrypoints_share_registry_and_exact_replay(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $actor = $this->actor();
        \App\Models\Tariff::create(['name' => 'Synthetic cash API tariff', 'unit_price' => '1.00', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-CASH-API']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $invoice = app(\App\Services\GatedInvoiceTransferWriter::class)->issue($actor, (int) $subscriber->id, (int) $meter->id,
            '66666666-6666-4666-8666-666666666666', $this->nowAt())['invoice'];
        \Laravel\Sanctum\Sanctum::actingAs($actor);
        config(['financial_candidate.cash_receipts' => true]);
        $data = ['subscriber_id' => $subscriber->id, 'amount' => '0.25', 'payment_method' => 'cash',
            'payment_date' => '2026-11-25', 'sync_uuid' => '88888888-8888-4888-8888-888888888888',
            'notes' => 'Synthetic office collection'];
        $first = $this->postJson('/api/payments', $data)->assertCreated()->json('payment.id');
        $this->postJson('/api/subscribers/'.$subscriber->id.'/collect-payment', $data)->assertCreated()->assertJsonPath('payment.id', $first);
        $target = ['amount' => '0.40', 'payment_method' => 'cash', 'payment_date' => '2026-11-25',
            'sync_uuid' => '99999999-9999-4999-8999-999999999999', 'invoice_id' => $invoice->id];
        $second = $this->postJson('/api/invoices/'.$invoice->id.'/pay', $target)->assertCreated()->json('payment.id');
        $this->postJson('/api/invoices/pay', $target)->assertCreated()->assertJsonPath('payment.id', $second);
        $this->assertNotSame($first, $second);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $data['sync_uuid'], 'amount_minor' => 25]);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $target['sync_uuid'], 'amount_minor' => 40]);
        $this->assertSame(1, DB::table('financial_posting_events')->where('operation_uuid', $target['sync_uuid'])->count());
        $this->assertSame(-35, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        $this->assertSame('0.65', $invoice->fresh()->amount_paid);
        $this->assertSame('Synthetic office collection', Payment::findOrFail($first)->notes);
        $this->postJson('/api/payments', array_replace($data, ['notes' => 'Changed request']))->assertConflict();
        $this->postJson('/api/payments', array_replace($data, ['amount' => '0.26']))->assertConflict();
        $this->assertDatabaseCount('payments', 2);
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $account['id'])));
    }

    public function test_release_cash_api_deferred_identity_gate_and_late_review_no_fallback(): void
    {
        [, , , , , $subscriber] = $this->held();
        $actor = $this->actor();
        $session = app(CashSessionService::class)->open($actor);
        \Laravel\Sanctum\Sanctum::actingAs($actor);
        config(['financial_candidate.cash_receipts' => true]);
        $data = ['subscriber_id' => $subscriber->id, 'amount' => '0.50', 'payment_method' => 'cash',
            'payment_date' => '2026-11-25', 'sync_uuid' => '88888888-8888-4888-8888-888888888888',
            'cash_session_id' => $session->id, 'deferred_sync' => true];
        $this->postJson('/api/payments', array_diff_key($data, ['sync_uuid' => true]))->assertUnprocessable();
        $this->postJson('/api/payments', array_diff_key($data, ['cash_session_id' => true]))->assertConflict();
        $this->postJson('/api/payments', array_replace($data, ['payment_date' => '2026-11-24']))->assertConflict();
        $this->postJson('/api/payments', array_replace($data, ['payment_method' => 'check']))->assertUnprocessable();
        $this->postJson('/api/payments', array_replace($data, ['amount' => '0.501']))->assertUnprocessable();
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'CLOSED']);
        $this->postJson('/api/payments', $data)->assertConflict();
        $this->assertDatabaseCount('payments', 0);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'OPEN']);
        $id = $this->postJson('/api/payments', $data)->assertCreated()->json('payment.id');
        $this->assertDatabaseHas('payments', ['id' => $id, 'cash_session_id' => $session->id, 'sync_uuid' => $data['sync_uuid']]);
        $this->travelTo(\Carbon\Carbon::parse('2026-11-26T08:30:00Z'));
        $this->postJson('/api/payments', $data)->assertCreated()->assertJsonPath('payment.id', $id);
        $this->postJson('/api/payments', array_replace($data, ['sync_uuid' => '99999999-9999-4999-8999-999999999999']))->assertConflict();
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $data['sync_uuid'], 'amount_minor' => 50]);
        $this->assertSame(50, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
    }

    public function test_release_cash_filament_create_keeps_identity_on_conflict_and_replays_once(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $actor = $this->actor();
        foreach (['Create:Payment', 'ViewAny:Payment', 'View:Payment'] as $permission) {
            $actor->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'));
        }
        $session = app(CashSessionService::class)->open($actor);
        config(['financial_candidate.cash_receipts' => true]);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        $this->actingAs($actor, 'web');
        $page = \App\Filament\Resources\PaymentResource\Pages\CreatePayment::class;
        $component = \Livewire\Livewire::test($page);
        $uuid = $component->get('data.sync_uuid');
        $this->assertTrue(is_string($uuid) && \Illuminate\Support\Str::isUuid($uuid));
        $data = ['subscriber_id' => $subscriber->id, 'amount' => '0.50',
            'payment_date' => '2026-11-25', 'payment_method' => 'cash',
            'cash_session_id' => $session->id, 'notes' => 'Synthetic Filament receipt', 'sync_uuid' => $uuid];
        $component->fillForm($data);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'CLOSED']);
        $component->call('create')->assertHasErrors(['data.amount']);
        $this->assertSame($uuid, $component->get('data.sync_uuid'));
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseMissing('financial_posting_operations', ['operation_uuid' => $uuid]);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'OPEN']);
        $component->call('create')->assertHasNoErrors();
        $receipt = Payment::where('sync_uuid', $uuid)->sole();
        $this->assertSame((int) $session->id, (int) $receipt->cash_session_id);
        $this->assertSame('0.50', $receipt->amount);
        $this->assertSame('Synthetic Filament receipt', $receipt->notes);
        \Livewire\Livewire::test($page)->fillForm($data)->call('create')->assertHasNoErrors();
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $uuid, 'amount_minor' => 50]);
        $this->assertSame(1, DB::table('financial_posting_events')->where('operation_uuid', $uuid)->count());
        $this->assertSame(50, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        $this->assertSame(50, app(CashSessionService::class)->expectedAmountMinor($session->fresh()));
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $account['id'])));
    }

    public function test_release_cash_filament_omitted_original_till_refuses_without_posting(): void
    {
        [, , , , , $subscriber] = $this->held();
        $actor = $this->actor();
        foreach (['Create:Payment', 'ViewAny:Payment', 'View:Payment'] as $permission) {
            $actor->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'));
        }
        app(CashSessionService::class)->open($actor);
        config(['financial_candidate.cash_receipts' => true]);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        $this->actingAs($actor, 'web');
        $page = \App\Filament\Resources\PaymentResource\Pages\CreatePayment::class;
        $component = \Livewire\Livewire::test($page);
        $uuid = $component->get('data.sync_uuid');
        $component->fillForm(['subscriber_id' => $subscriber->id, 'amount' => '1.00',
            'payment_date' => '2026-11-25', 'payment_method' => 'cash', 'cash_session_id' => null])
            ->call('create')->assertHasErrors(['data.amount']);
        $this->assertSame($uuid, $component->get('data.sync_uuid'));
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseMissing('financial_posting_operations', ['operation_uuid' => $uuid]);
        $this->assertSame(0, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
    }

    public function test_release_cash_actions_subscriber_and_invoice_share_gate_and_replay_identity(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $actor = $this->actor();
        foreach (['Create:Payment', 'ViewAny:Subscriber', 'View:Subscriber', 'ViewAny:Invoice', 'View:Invoice', 'ViewAny:Payment', 'View:Payment'] as $permission) {
            $actor->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'));
        }
        \App\Models\Tariff::create(['name' => 'Synthetic modal tariff', 'unit_price' => '1.00', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-CASH-MODAL']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $invoice = app(\App\Services\GatedInvoiceTransferWriter::class)->issue($actor, (int) $subscriber->id, (int) $meter->id,
            '66666666-6666-4666-8666-666666666666', $this->nowAt())['invoice'];
        $session = app(CashSessionService::class)->open($actor);
        config(['financial_candidate.cash_receipts' => true]);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        $this->actingAs($actor, 'web');
        $subscriberPage = \App\Filament\Resources\SubscriberResource\Pages\ListSubscribers::class;
        $invoicePage = \App\Filament\Resources\InvoiceResource\Pages\ListInvoices::class;
        $action = fn ($record) => \Filament\Actions\Testing\TestAction::make('collect_payment')->table($record);
        $uuid = '88888888-8888-4888-8888-888888888888';
        $data = ['sync_uuid' => $uuid, 'payment_date' => '2026-11-25', 'cash_session_id' => $session->id,
            'amount' => '0.25', 'payment_method' => 'cash', 'invoice_id' => $invoice->id, 'notes' => 'Synthetic collection modal'];
        $modal = \Livewire\Livewire::test($subscriberPage)->mountAction($action($subscriber))
            ->assertActionDataSet(['payment_date' => '2026-11-25'])->setActionData($data);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'CLOSED']);
        $modal->callMountedAction()->assertHasActionErrors();
        $this->assertDatabaseCount('payments', 0);
        $modal->assertActionDataSet(['sync_uuid' => $uuid]);
        DB::table('financial_periods')->where('scope_key', 'water')->update(['state' => 'OPEN']);
        $modal->callMountedAction()->assertHasNoActionErrors();
        $receipt = Payment::where('sync_uuid', $uuid)->sole();
        $this->assertSame((int) $session->id, (int) $receipt->cash_session_id);
        $this->assertSame((int) $actor->id, (int) $receipt->user_id);
        $this->assertSame('0.25', $invoice->fresh()->amount_paid);
        $invoiceData = array_diff_key($data, ['invoice_id' => true]);
        \Livewire\Livewire::test($invoicePage)->callAction($action($invoice), data: $invoiceData)->assertHasNoActionErrors();
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(1, DB::table('financial_posting_events')->where('operation_uuid', $uuid)->count());
        $second = array_replace($invoiceData, ['sync_uuid' => '99999999-9999-4999-8999-999999999999', 'amount' => '0.40']);
        \Livewire\Livewire::test($invoicePage)->callAction($action($invoice), data: $second)->assertHasNoActionErrors();
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $second['sync_uuid'], 'amount_minor' => 40]);
        $this->assertSame('0.65', $invoice->fresh()->amount_paid);
        $this->assertSame(-35, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        $this->assertSame(65, app(CashSessionService::class)->expectedAmountMinor($session->fresh()));
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $account['id'])));
    }
}
