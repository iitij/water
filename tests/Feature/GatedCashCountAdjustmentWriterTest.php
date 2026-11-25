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
}
