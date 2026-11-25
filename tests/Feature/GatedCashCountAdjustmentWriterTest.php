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

        app(\App\Services\InvoiceCancellationService::class)->cancel($invoice->fresh(), 'Synthetic independently documented invoice cancellation', (int) $actor->id);
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

}
