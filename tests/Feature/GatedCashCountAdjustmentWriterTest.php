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

    public function test_release_held_credit_model_fence_rejects_direct_document_and_ledger(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $actor = $this->actor();
        $writer = app(\App\Services\TreasuryHeldCashLiabilityService::class);
        $ledger = app(\App\Services\TransactionService::class);
        $record = ['operation_uuid' => self::OTHER, 'assignment_uuid' => self::NEXT,
            'subscriber_id' => $subscriber->id, 'approved_by' => $actor->id, 'amount' => '0.20',
            'effective_on' => '2026-11-25', 'reference' => 'QA-HELD-CREDIT-'.self::OTHER,
            'evidence' => 'Independent documented owner consent to apply held cash against subscriber account'];
        $tables = ['treasury_held_cash_credits', 'transactions', 'treasury_postings', 'payments',
            'payment_allocations', 'payment_allocation_revisions', 'financial_posting_operations', 'financial_posting_events', 'audit_events'];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        $unchanged = function (string $error, callable $action) use ($tables, $snapshot): void {
            $before = [];
            foreach ($tables as $table) $before[$table] = $snapshot($table);
            $this->rejects($error, $action);
            foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        };
        $unchanged('held_cash_credit_document_writer_required', fn () => \App\Models\TreasuryHeldCashCredit::create($record));
        $unchanged('held_cash_credit_document_writer_required', fn () => DB::transaction(fn () =>
            (new \App\Models\TreasuryHeldCashCredit($record))->saveQuietly(['financial_held_credit_writer' => $writer])));
        $result = $this->creditHeld($actor, 20);
        $this->assertTrue($result['subscriber_credit_created']);
        $doc = \App\Models\TreasuryHeldCashCredit::where('operation_uuid', self::OTHER)->sole();
        $data = ['subscriber_id' => $subscriber->id, 'type' => 'held_cash_credit', 'amount' => '0.20',
            'reference_type' => \App\Models\TreasuryHeldCashCredit::class, 'reference_id' => $doc->id];
        $entry = \App\Models\Transaction::where('reference_type', \App\Models\TreasuryHeldCashCredit::class)
            ->where('reference_id', $doc->id)->sole();
        $unchanged('held_cash_credit_ledger_writer_required', fn () => \App\Models\Transaction::create($data));
        $unchanged('held_cash_credit_ledger_writer_required', fn () => DB::transaction(fn () =>
            (new \App\Models\Transaction($data))->saveQuietly(['financial_held_credit_writer' => $writer])));
        $unchanged('held_cash_credit_ledger_writer_required', fn () => $ledger->recordTransaction($subscriber, 'held_cash_credit', '0.20', reference: $doc));
        $unchanged('held_cash_credit_ledger_writer_required', fn () => DB::transaction(fn () =>
            $ledger->recordTransaction($subscriber, 'held_cash_credit', '0.20', reference: $doc, heldCreditWriter: $writer)));
        $unchanged('held_cash_credit_ledger_writer_required', fn () => $entry->fresh()->forceFill(['amount' => '1.00'])->saveQuietly());
        $unchanged('held_cash_credit_document_writer_required', fn () => $doc->fresh()->forceFill(['amount' => '1.00'])->saveQuietly());
        $this->assertFalse($this->creditHeld($actor, 20)['created']);
        $this->assertSame(20, $ledger->accountBalanceMinor($subscriber));
        $this->assertDatabaseCount('treasury_held_cash_credits', 1);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $account['id'])));
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
    private function batchResolutionControl(int $variance = -10, string $receiptAmount = '1.00', array $classifications = ['posting_required', 'posting_required']): array
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        $issuer = $this->actor();
        $requester = $this->actor();
        $reviewer = $this->actor();
        \App\Models\Tariff::create(['name' => 'Synthetic lifecycle tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-REFUND-LIFECYCLE']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        app(\App\Services\GatedInvoiceTransferWriter::class)->issue($issuer, (int) $subscriber->id, (int) $meter->id,
            '66666666-6666-4666-8666-666666666666', $this->nowAt());
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 20);
        $id = (int) $account['id'];
        $credit = DB::table('treasury_held_cash_credits')->sole();
        $release = app(\App\Services\HeldCashReleaseProposalService::class);
        $proposal = '77777777-7777-4777-8777-777777777777';
        $release->propose($issuer, $id, (int) $credit->id, 10, $proposal, 'QA-LIFECYCLE-RELEASE', self::EVIDENCE, $this->nowAt());
        config(['financial_candidate.active_held_release' => true, 'financial_candidate.completed_cash_refund' => true]);
        $release->recordActiveRelease($reviewer, $id, $proposal, '88888888-8888-4888-8888-888888888888', self::EVIDENCE, $this->nowAt());
        $other = Subscriber::create(['name' => 'Synthetic lifecycle refund recipient', 'square_id' => $subscriber->square_id, 'status' => 'active']);
        $receipt = app(GatedCashReceiptWriter::class)->collect($issuer, (int) $other->id, $receiptAmount,
            '99999999-9999-4999-8999-999999999999', $this->nowAt())['payment'];
        $submit = '/api/payments/'.$receipt->id.'/completed-cash-refund-requests';
        $root = '/api/completed-cash-refund-requests/';
        $data = ['account_id' => $id, 'amount' => '0.06', 'reference' => 'QA-LIFECYCLE-1', 'date' => '2026-11-25',
            'reason' => 'Independent evidence of completed cash disbursement', 'evidence' => self::EVIDENCE,
            'operation_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'disbursement_confirmed' => true];
        $uuid = $data['operation_uuid'];
        $endpoint = $root.$uuid.'/resolution-proposal';
        $counter = $this->actor();
        $countReviewer = $this->actor();
        $proposer = $this->actor();
        \Laravel\Sanctum\Sanctum::actingAs($requester);
        $this->postJson($submit, $data)->assertOk();
        \Laravel\Sanctum\Sanctum::actingAs($reviewer);
        $this->postJson($root.$uuid.'/close', ['decision' => 'rejected', 'reason' => self::EVIDENCE])->assertOk();
        $second = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        \Laravel\Sanctum\Sanctum::actingAs($requester);
        $this->postJson($submit, array_replace($data, [
            'operation_uuid' => $second, 'amount' => '0.04', 'reference' => 'QA-BATCH-SECOND',
        ]))->assertOk();
        \Laravel\Sanctum\Sanctum::actingAs($reviewer);
        $this->postJson($root.$second.'/close', ['decision' => 'rejected', 'reason' => self::EVIDENCE])->assertOk();
        $balance = $this->gate(fn () => app(\App\Services\HeldCashRecordedLiquidityEvidence::class)->balance($id, '2026-11-25'));
        $counted = $balance + $variance;
        $amount = sprintf('%d.%02d', intdiv($counted, 100), $counted % 100);
        $counts = app(TreasuryCashCountService::class);
        $count = $counts->record($counter, $id, $amount, 'QA-BATCH-COUNT', self::EVIDENCE)['count'];
        $counts->review($countReviewer, (int) $count['id'], true, self::EVIDENCE);
        \Laravel\Sanctum\Sanctum::actingAs($proposer);
        $proposalIds = [];
        foreach ([$uuid, $second] as $index => $claim) {
            $response = $this->postJson($root.$claim.'/resolution-proposal', [
                'count_id' => (int) $count['id'], 'classification' => $classifications[$index], 'evidence' => self::EVIDENCE,
            ])->assertOk()->assertJsonPath('created', true);
            $proposalIds[$claim] = $response->json('proposal_id');
        }
        $approver = $this->actor();
        return compact('id', 'uuid', 'second', 'root', 'submit', 'data', 'requester', 'approver',
            'counter', 'countReviewer', 'amount', 'count', 'proposalIds', 'balance', 'counted');
    }

    private function mixedAtomicControl(int $variance = -6, array $classifications = ['posting_required', 'cash_returned']): array
    {
        $f = $this->batchResolutionControl($variance, '1.00', $classifications);
        return ['f' => $f] + $this->signedResolutionSources($f['proposalIds']);
    }

    private function signedResolutionSources(array $proposalIds): array
    {
        $service = app(\App\Services\CompletedCashRefundRequest::class);
        $recorder = $this->actor();
        $byteReviewer = $this->actor();
        $sourceReviewer = $this->actor();
        $ids = [];
        $keys = [];
        foreach ($proposalIds as $uuid => $proposal) {
            $issuer = 'qa-source-'.count($ids);
            $reference = 'QA-DOC-'.count($ids);
            $sourceRef = 'QA-EXTERNAL-'.count($ids);
            $bytes = 'Synthetic QA evidence for the exact claim '.$uuid;
            \Laravel\Sanctum\Sanctum::actingAs($recorder);
            $doc = $service->recordResolutionDocument($recorder, $uuid, $proposal, $reference, $bytes, $this->nowAt());
            \Laravel\Sanctum\Sanctum::actingAs($byteReviewer);
            $service->reviewResolutionDocument($byteReviewer, $uuid, $proposal, $doc['document_id'], $reference, $bytes,
                'Independent reviewer compared the original document bytes', $this->nowAt());
            $pair = sodium_crypto_sign_keypair();
            $secret = sodium_crypto_sign_secretkey($pair);
            $keys[$issuer] = base64_encode(sodium_crypto_sign_publickey($pair));
            config(['financial_candidate.refund_source_public_keys' => $keys]);
            $sig = base64_encode(sodium_crypto_sign_detached(
                \App\Services\CompletedCashRefundRequest::sourceStatement($doc['document'], $issuer, $sourceRef), $secret));
            sodium_memzero($secret);
            \Laravel\Sanctum\Sanctum::actingAs($sourceReviewer);
            $attest = $service->attestResolutionSource($sourceReviewer, $uuid, $proposal, $doc['document_id'],
                $issuer, $sourceRef, $sig, 'Independent reviewer inspected the synthetic source statement', $this->nowAt());
            $ids[$uuid] = $attest['attestation_id'];
        }
        return compact('ids', 'keys', 'service');
    }

    public function test_release_refund_signed_api_document_to_settlement_rejects_bypasses_and_replays(): void
    {
        $f = $this->resolutionControl('posting_required', -6);
        $proposal = AuditEvent::query()->where('event', 'financial.completed_cash_refund_resolution_proposed')->sole();
        $pid = (int) $proposal->id;
        $root = $f['root'].$f['uuid'];
        $group = '/api/completed-cash-refund-settlements/';
        $bytes = "Synthetic independently sourced binary document\0\xff";
        $record = ['proposal_id' => $pid, 'document_reference' => 'QA-API-DOCUMENT',
            'document_base64' => base64_encode($bytes)];
        $recorder = $this->actor();
        $byteReviewer = $this->actor();
        $attester = $this->actor();
        $ordinary = User::factory()->create(['role' => 'admin']);
        \Laravel\Sanctum\Sanctum::actingAs($ordinary);
        $this->postJson($root.'/resolution-document', $record)->assertForbidden();
        \Laravel\Sanctum\Sanctum::actingAs($recorder);
        $this->postJson($root.'/resolution-document', $record + ['actor_id' => $byteReviewer->id])->assertUnprocessable();
        $this->postJson($root.'/resolution-document?reviewer_id=1', $record)->assertUnprocessable();
        $this->postJson($root.'/resolution-document', array_replace($record, ['proposal_id' => (string) $pid]))->assertUnprocessable();
        $this->postJson($root.'/resolution-document', array_replace($record, ['document_base64' => 'not base64!']))->assertUnprocessable();
        $doc = $this->postJson($root.'/resolution-document', $record)->assertOk()
            ->assertJsonPath('created', true)->assertJsonPath('original_bytes_stored', false)
            ->assertJsonPath('document.sha256', hash('sha256', $bytes))->json();
        $review = $record + ['document_id' => $doc['document_id'], 'confirmation' => self::EVIDENCE];
        $this->postJson($root.'/resolution-document-review', $review)->assertConflict();
        \Laravel\Sanctum\Sanctum::actingAs($byteReviewer);
        $this->postJson($root.'/resolution-document-review', array_replace($review,
            ['document_base64' => base64_encode($bytes.'changed')]))->assertConflict();
        $this->postJson($root.'/resolution-document-review', $review)->assertOk()
            ->assertJsonPath('independent_byte_review_recorded', true)
            ->assertJsonPath('spending_authorized', false);
        $keypair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($keypair);
        $keys = ['qa-api-source' => base64_encode(sodium_crypto_sign_publickey($keypair))];
        config(['financial_candidate.refund_source_public_keys' => $keys]);
        $signature = base64_encode(sodium_crypto_sign_detached(
            \App\Services\CompletedCashRefundRequest::sourceStatement($doc['document'], 'qa-api-source', 'QA-API-SOURCE'), $secret));
        sodium_memzero($secret);
        $attestation = ['proposal_id' => $pid, 'document_id' => $doc['document_id'], 'issuer' => 'qa-api-source',
            'source_reference' => 'QA-API-SOURCE', 'signature' => $signature, 'substantive_review' => self::EVIDENCE];
        $this->postJson($root.'/resolution-source-attestation', $attestation)->assertConflict();
        \Laravel\Sanctum\Sanctum::actingAs($attester);
        $this->postJson($root.'/resolution-source-attestation', $attestation + ['public_key' => $keys['qa-api-source']])->assertUnprocessable();
        $proof = $this->postJson($root.'/resolution-source-attestation', $attestation)->assertOk()
            ->assertJsonPath('created', true)->assertJsonPath('spending_authorized', false)->json();
        $member = ['operation_uuid' => $f['uuid'], 'proposal_id' => $pid, 'attestation_id' => $proof['attestation_id']];
        $selection = ['account_id' => $f['id'], 'members' => [$member]];
        $input = $selection + ['confirmation' => self::EVIDENCE];
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $tables = ['payment_refunds', 'transactions', 'treasury_postings', 'financial_posting_operations',
            'financial_posting_events', 'audit_events'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->get()->toArray(), $tables);
        $before = $snapshot();
        $this->postJson($group.'settle', $input + ['amount' => '0.01'])->assertUnprocessable();
        $this->postJson($group.'settle', array_replace($input, ['members' => [$member + ['reviewer_id' => $f['approver']->id]]]))->assertUnprocessable();
        $this->postJson($group.'settle', array_replace($input, ['members' => [$member, $member]]))->assertUnprocessable();
        $this->postJson($group.'settle', array_replace($input, ['members' => [array_diff_key($member, ['attestation_id' => true])]]))->assertUnprocessable();
        $this->postJson($group.'settle', array_replace($input, ['members' => [$f['uuid'] => $member]]))->assertUnprocessable();
        $this->postJson($group.'readiness', $selection)->assertOk()
            ->assertJsonPath('all_source_signatures_revalidated', true)
            ->assertJsonPath('spending_authorized', false)->assertJsonPath('closure_authorized', false);
        config(['financial_candidate.refund_source_public_keys' => []]);
        $this->postJson($group.'settle', $input)->assertConflict();
        $this->assertEquals($before, $snapshot());
        config(['financial_candidate.refund_source_public_keys' => $keys]);
        $this->postJson($group.'settle', $input)->assertOk()->assertJsonPath('registry_created', true)
            ->assertJsonPath('batch.format', 'signed-v2')->assertJsonPath('batch.posting_required_minor', 6);
        $this->assertSame(1, DB::table('payment_refunds')->count());
        $after = $snapshot();
        config(['financial_candidate.refund_source_public_keys' => []]);
        $this->postJson($group.'settle', $input)->assertOk()->assertJsonPath('registry_created', false);
        $this->assertEquals($after, $snapshot());
        $this->getJson($root)->assertOk()->assertJsonPath('incident_unresolved', false);
        $this->assertStringNotContainsString(base64_encode($bytes), json_encode(DB::table('audit_events')->get(), JSON_THROW_ON_ERROR));
        app()->instance('env', 'production');
        try {
            $this->postJson($group.'settle', $input)->assertStatus(503);
        } finally {
            app()->instance('env', 'testing');
        }
    }

    public function test_release_refund_signed_api_renewal_rejects_partial_selection_and_requires_fresh_evidence(): void
    {
        ['f' => $f, 'ids' => $attestations] = $this->mixedAtomicControl();
        $counts = app(TreasuryCashCountService::class);
        $new = $counts->record($f['counter'], $f['id'], $f['amount'], 'QA-API-RENEWAL', self::EVIDENCE)['count'];
        $counts->review($f['countReviewer'], (int) $new['id'], true, self::EVIDENCE);
        $members = [];
        foreach ($f['proposalIds'] as $uuid => $id) $members[] = ['operation_uuid' => $uuid, 'proposal_id' => $id];
        $group = '/api/completed-cash-refund-settlements/';
        $input = ['account_id' => $f['id'], 'members' => $members, 'count_id' => (int) $new['id'], 'evidence' => self::EVIDENCE];
        $actor = $this->actor();
        \Laravel\Sanctum\Sanctum::actingAs($actor);
        $this->postJson($group.'renew', array_replace($input, ['members' => [$members[0]]]))->assertUnprocessable();
        $this->postJson($group.'renew', $input + ['classification' => 'no_disbursement'])->assertUnprocessable();
        $this->assertSame(0, AuditEvent::query()->where('event', 'financial.completed_cash_refund_resolution_revised')->count());
        $result = $this->postJson($group.'renew', $input)->assertOk()->assertJsonPath('created', true)
            ->assertJsonPath('financial_posting_created', false)->assertJsonPath('posting_required_minor', 6)->json();
        $this->postJson($group.'renew', $input)->assertOk()->assertJsonPath('created', false);
        $this->assertSame(2, AuditEvent::query()->where('event', 'financial.completed_cash_refund_resolution_revised')->count());
        $selected = [];
        foreach ($result['proposal_ids'] as $uuid => $id) $selected[] = ['operation_uuid' => $uuid,
            'proposal_id' => $id, 'attestation_id' => $attestations[$uuid]];
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $this->postJson($group.'settle', ['account_id' => $f['id'], 'members' => $selected, 'confirmation' => self::EVIDENCE])->assertConflict();
        $this->assertSame(0, DB::table('payment_refunds')->count());
        $this->assertSame(['count' => 2, 'claimed_minor' => 10], $this->gate(fn () =>
            app(\App\Services\CompletedCashRefundRequest::class)->unresolvedForAccount($f['id'])));
    }

    public function test_release_refund_signed_single_posting_blocks_unsigned_and_replays_exactly(): void
    {
        $f = $this->resolutionControl('posting_required', -6);
        $proposal = AuditEvent::query()->where('event', 'financial.completed_cash_refund_resolution_proposed')->sole();
        $proposalIds = [$f['uuid'] => (int) $proposal->id];
        $service = app(\App\Services\CompletedCashRefundRequest::class);
        $this->assertNotTrue(config('financial_candidate.qa_legacy_unsigned_refund_fixture'));
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $confirmation = 'Independent verified signed evidence supports the completed cash settlement';
        $tables = ['payment_refunds', 'transactions', 'treasury_postings', 'financial_posting_operations',
            'financial_posting_events', 'audit_events'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->get()->toArray(), $tables);
        $before = $snapshot();
        $this->rejects('completed_refund_signed_source_required', fn () =>
            $service->postResolution($f['approver'], $f['uuid'], $confirmation, $this->nowAt(), (int) $proposal->id));
        $this->postJson($f['root'].$f['uuid'].'/resolution-post', ['confirmation' => $confirmation,
            'proposal_id' => (int) $proposal->id])->assertConflict();
        $this->assertEquals($before, $snapshot());
        ['ids' => $ids, 'keys' => $keys] = $this->signedResolutionSources($proposalIds);
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $beforeSigned = $snapshot();
        $this->rejects('completed_refund_mixed_source_selection', fn () =>
            $service->postMixedResolution($f['approver'], $f['id'], $proposalIds, [], $confirmation, $this->nowAt()));
        config(['financial_candidate.refund_source_public_keys' => []]);
        $post = fn () => $service->postMixedResolution($f['approver'], $f['id'], $proposalIds, $ids, $confirmation, $this->nowAt());
        $this->rejects('completed_refund_source_untrusted', $post);
        $this->assertEquals($beforeSigned, $snapshot());
        config(['financial_candidate.refund_source_public_keys' => $keys]);
        $result = $post();
        $this->assertTrue($result['registry_created']);
        $this->assertSame('signed-v2', $result['batch']['format']);
        $this->assertSame(1, $result['batch']['incident_count']);
        $this->assertSame(6, $result['batch']['posting_required_minor']);
        $this->assertSame(1, DB::table('payment_refunds')->count());
        $legs = DB::table('financial_posting_events')->where('operation_uuid', $f['uuid'])->orderBy('leg')->get();
        $this->assertSame(['subscriber', 'treasury'], $legs->pluck('leg')->all());
        $this->assertSame([-6, -6], $legs->map(fn ($leg) => (int) $leg->amount_minor)->all());
        $after = $snapshot();
        config(['financial_candidate.refund_source_public_keys' => []]);
        $this->assertFalse($post()['registry_created']);
        $this->assertEquals($after, $snapshot());
        $this->assertSame(['count' => 0, 'claimed_minor' => 0], $this->gate(fn () => $service->unresolvedForAccount($f['id'])));
    }

    public function test_release_refund_signed_single_nonposting_blocks_unsigned_closure(): void
    {
        $f = $this->resolutionControl();
        $proposal = AuditEvent::query()->where('event', 'financial.completed_cash_refund_resolution_proposed')->sole();
        $proposalIds = [$f['uuid'] => (int) $proposal->id];
        $service = app(\App\Services\CompletedCashRefundRequest::class);
        $confirmation = 'Independent signed evidence confirms no net cash disbursement';
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $this->rejects('completed_refund_signed_source_required', fn () =>
            $service->approveResolution($f['approver'], $f['uuid'], $confirmation, $this->nowAt(), (int) $proposal->id));
        $this->assertSame(['count' => 1, 'claimed_minor' => 6], $this->gate(fn () => $service->unresolvedForAccount($f['id'])));
        ['ids' => $ids] = $this->signedResolutionSources($proposalIds);
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $tables = ['payment_refunds', 'transactions', 'treasury_postings', 'financial_posting_operations', 'financial_posting_events'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->get()->toArray(), $tables);
        $before = $snapshot();
        $post = fn () => $service->postMixedResolution($f['approver'], $f['id'], $proposalIds, $ids, $confirmation, $this->nowAt());
        $result = $post();
        $this->assertTrue($result['registry_created']);
        $this->assertSame('signed-v2', $result['batch']['format']);
        $this->assertFalse($result['batch']['financial_posting_created']);
        $this->assertFalse($post()['registry_created']);
        $this->assertEquals($before, $snapshot());
        $this->assertSame(['count' => 0, 'claimed_minor' => 0], $this->gate(fn () => $service->unresolvedForAccount($f['id'])));
    }

    public function test_release_refund_signed_full_group_blocks_unsigned_batch_and_posts_exact_legs(): void
    {
        ['f' => $f, 'ids' => $ids, 'service' => $service] = $this->mixedAtomicControl(-10, ['posting_required', 'posting_required']);
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $confirmation = 'Independent signed evidence confirms both completed cash refunds';
        $this->rejects('completed_refund_signed_source_required', fn () =>
            $service->postBatchResolution($f['approver'], $f['id'], $f['proposalIds'], $confirmation, $this->nowAt()));
        $this->assertSame(0, DB::table('payment_refunds')->count());
        $post = fn () => $service->postMixedResolution($f['approver'], $f['id'], $f['proposalIds'], $ids, $confirmation, $this->nowAt());
        $result = $post();
        $this->assertTrue($result['registry_created']);
        $this->assertSame('signed-v2', $result['batch']['format']);
        $this->assertSame(10, $result['batch']['posting_required_minor']);
        $this->assertSame(2, DB::table('payment_refunds')->count());
        foreach ([$f['uuid'] => -6, $f['second'] => -4] as $uuid => $minor) {
            $legs = DB::table('financial_posting_events')->where('operation_uuid', $uuid)->orderBy('leg')->get();
            $this->assertSame(['subscriber', 'treasury'], $legs->pluck('leg')->all());
            $this->assertSame([$minor, $minor], $legs->map(fn ($leg) => (int) $leg->amount_minor)->all());
        }
        $this->assertSame(['count' => 0, 'claimed_minor' => 0], $this->gate(fn () => $service->unresolvedForAccount($f['id'])));
        config(['financial_candidate.refund_source_public_keys' => []]);
        $this->assertFalse($post()['registry_created']);
        $this->assertSame(2, DB::table('payment_refunds')->count());
    }

    public function test_release_refund_signed_policy_blocks_direct_pending_approval_and_staging_fixture_override(): void
    {
        $f = $this->resolutionControl();
        $request = AuditEvent::query()->where('event', 'financial.completed_cash_refund_requested')->sole();
        $uuid = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $service = app(\App\Services\CompletedCashRefundRequest::class);
        \Laravel\Sanctum\Sanctum::actingAs($f['requester']);
        $this->postJson('/api/payments/'.$request->after['payment_id'].'/completed-cash-refund-requests',
            array_replace($f['data'], ['operation_uuid' => $uuid, 'reference' => 'QA-SIGNED-PENDING']))->assertOk();
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $this->rejects('completed_refund_signed_source_required', fn () =>
            $service->approve($f['approver'], $uuid, self::EVIDENCE, $this->nowAt()));
        $this->postJson($f['root'].$uuid.'/approve', ['confirmation' => self::EVIDENCE])->assertConflict();
        $this->assertSame(0, DB::table('payment_refunds')->count());
        $this->assertSame(['count' => 2, 'claimed_minor' => 12], $this->gate(fn () => $service->unresolvedForAccount($f['id'])));
        config(['financial_candidate.qa_legacy_unsigned_refund_fixture' => true]);
        app()->instance('env', 'staging');
        try {
            $this->rejects('completed_refund_signed_source_required', fn () =>
                \App\Services\CompletedCashRefundRequest::requireSignedNewSettlement());
        } finally {
            app()->instance('env', 'testing');
            config(['financial_candidate.qa_legacy_unsigned_refund_fixture' => false]);
        }
    }

    private function assertMixedSettlementRace(bool $differentReviewer, bool $zeroPosting = false): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent shared-database connections require the MySQL QA runner.');
        }
        ['f' => $f, 'ids' => $ids, 'service' => $service] = $zeroPosting
            ? $this->mixedAtomicControl(0, ['no_disbursement', 'cash_returned'])
            : $this->mixedAtomicControl();
        $request = AuditEvent::query()->where('event', 'financial.completed_cash_refund_requested')
            ->where('entity_id', $f['uuid'])->sole();
        $subscriber = Subscriber::findOrFail(Payment::findOrFail($request->after['payment_id'])->subscriber_id);
        $ledger = app(\App\Services\TransactionService::class);
        $beforeCredit = $ledger->accountBalanceMinor($subscriber);
        $cash = fn () => $this->gate(fn () => app(\App\Services\HeldCashRecordedLiquidityEvidence::class)
            ->balance($f['id'], '2026-11-25'));
        $beforeCash = $cash();
        $results = $this->refundResolutionRace($f, $differentReviewer, true, $ids);
        $success = array_values(array_filter($results, fn ($r) => $r['ok']));
        $this->assertCount($differentReviewer ? 1 : 2, $success, json_encode($results));
        $this->assertCount(1, array_filter($success, fn ($r) => $r['created']), json_encode($results));
        if ($differentReviewer) {
            $rejected = collect($results)->firstWhere('ok', false);
            $this->assertSame(DomainException::class, $rejected['error'], json_encode($results));
            $this->assertSame('completed_refund_batch_conflict', $rejected['message']);
        }
        $winner = collect($success)->firstWhere('created', true);
        $minor = $zeroPosting ? 0 : 6;
        $this->assertSame($beforeCredit - $minor, $ledger->accountBalanceMinor($subscriber->fresh()));
        $this->assertSame($beforeCash - $minor, $cash());
        $this->assertSame($f['counted'], $cash());
        $this->assertSame($zeroPosting ? 0 : 1, DB::table('payment_refunds')->count());
        $this->assertSame($zeroPosting ? 0 : 1, DB::table('financial_posting_operations')
            ->whereIn('operation_uuid', array_keys($ids))->count());
        $this->assertSame($zeroPosting ? 0 : 2, DB::table('financial_posting_events')
            ->whereIn('operation_uuid', array_keys($ids))->count());
        $this->assertSame(0, DB::table('financial_posting_operations')->where('operation_uuid', $f['second'])->count());
        if (! $zeroPosting) {
            $legs = DB::table('financial_posting_events')->where('operation_uuid', $f['uuid'])->orderBy('leg')->get();
            $this->assertSame(['subscriber', 'treasury'], $legs->pluck('leg')->all());
            $this->assertSame([-6, -6], $legs->map(fn ($leg) => (int) $leg->amount_minor)->all());
        }
        $approval = AuditEvent::query()->where('event', 'financial.completed_cash_refund_batch_approved')->sole();
        $this->assertSame($winner['actor'], (int) $approval->actor_user_id);
        $this->assertSame('mixed-v1', $approval->after['format']);
        $this->assertSame(10, $approval->after['total_claimed_minor']);
        $this->assertSame($minor, $approval->after['posting_required_minor']);
        $this->assertSame(! $zeroPosting, $approval->after['financial_posting_created']);
        $binding = json_decode($approval->after['source_binding_json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($winner['actor'], $binding['reviewer_id']);
        ksort($ids);
        $this->assertSame($ids, array_column($binding['sources'], 'attestation_id', 'operation_uuid'));
        $this->assertSame(hash('sha256', $approval->after['source_binding_json']), $approval->after['source_binding_sha256']);
        $this->assertSame($zeroPosting ? 0 : 1, AuditEvent::query()->where('event', 'financial.completed_cash_refund')->count());
        $this->assertSame(0, AuditEvent::query()->where('event', 'financial.completed_cash_refund_resolution_approved')->count());
        $this->assertSame(['count' => 0, 'claimed_minor' => 0], $this->gate(fn () => $service->unresolvedForAccount($f['id'])));
        \Laravel\Sanctum\Sanctum::actingAs(User::findOrFail($winner['actor']));
        // The completed group remains verifiable even when neither live nor
        // archive configuration retains its original public keys.
        config(['financial_candidate.refund_source_public_keys' => [], 'financial_candidate.refund_source_archived_public_keys' => []]);
        foreach (array_keys($ids) as $uuid) {
            $this->getJson($f['root'].$uuid)->assertOk()->assertJsonPath('status', 'rejected')
                ->assertJsonPath('completion_verified', ! $zeroPosting && $uuid === $f['uuid'])
                ->assertJsonPath('incident_unresolved', false);
        }
        $this->assertSame(['count' => 0, 'claimed_minor' => 0], $this->gate(fn () => $service->unresolvedForAccount($f['id'])));
    }

    public function test_release_refund_mixed_concurrency_identical_retries_commit_once(): void
    {
        $this->assertMixedSettlementRace(false);
    }

    public function test_release_refund_mixed_concurrency_competing_reviewers_commit_once(): void
    {
        $this->assertMixedSettlementRace(true);
    }

    public function test_release_refund_mixed_concurrency_zero_posting_competing_reviewers_close_once(): void
    {
        $this->assertMixedSettlementRace(true, true);
    }

    public function test_release_refund_mixed_controls_final_audit_failure_rolls_back_posting_and_keeps_both_incidents_open(): void
    {
        ['f' => $f, 'ids' => $ids, 'service' => $service] = $this->mixedAtomicControl();
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $tables = ['payments', 'subscribers', 'invoices', 'payment_refunds', 'transactions', 'treasury_accounts',
            'treasury_postings', 'payment_allocations', 'payment_allocation_revisions', 'financial_posting_operations',
            'financial_posting_events', 'audit_events', 'treasury_cash_counts', 'treasury_cash_count_reviews'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->orderBy(
            $table === 'financial_posting_operations' ? 'operation_uuid' : 'id')->get()->toArray(), $tables);
        $before = $snapshot();
        $request = AuditEvent::query()->where('event', 'financial.completed_cash_refund_requested')
            ->where('entity_id', $f['uuid'])->sole();
        $subscriber = Subscriber::findOrFail(Payment::findOrFail($request->after['payment_id'])->subscriber_id);
        $ledger = app(\App\Services\TransactionService::class);
        $beforeCredit = $ledger->accountBalanceMinor($subscriber);
        $cash = fn () => $this->gate(fn () => app(\App\Services\HeldCashRecordedLiquidityEvidence::class)->balance($f['id'], '2026-11-25'));
        $beforeCash = $cash();
        $post = fn () => $service->postMixedResolution($f['approver'], $f['id'], $f['proposalIds'], $ids,
            'Independent verified evidence supports the complete mixed cash settlement', $this->nowAt());
        // Only fail when BOTH exact legs exist, the completed-refund audit
        // exists, and the nonposting member still has no registered operation.
        $condition = "NEW.event = 'financial.completed_cash_refund_batch_approved'
            AND (SELECT COUNT(*) FROM financial_posting_events WHERE operation_uuid = '{$f['uuid']}' AND amount_minor = -6) = 2
            AND (SELECT COUNT(*) FROM audit_events WHERE event = 'financial.completed_cash_refund') = 1
            AND NOT EXISTS (SELECT 1 FROM financial_posting_operations WHERE operation_uuid = '{$f['second']}')";
        $body = DB::getDriverName() === 'sqlite'
            ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'qa_mixed_final_audit'); END"
            : "FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'qa_mixed_final_audit'; END IF; END";
        DB::unprepared("CREATE TRIGGER qa_mixed_final_audit BEFORE INSERT ON audit_events {$body}");
        \Illuminate\Support\Facades\Log::spy();
        try {
            try {
                $post();
                $this->fail('Mixed settlement committed despite final audit failure.');
            } catch (\RuntimeException $error) {
                $this->assertSame('Financial operation requires a durable audit record.', $error->getMessage());
            }
            \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->with(
                'audit.record_failed', \Mockery::on(static fn ($context) =>
                    ($context['event'] ?? null) === 'financial.completed_cash_refund_batch_approved'
                    && str_contains((string) ($context['error'] ?? ''), 'qa_mixed_final_audit')))->once();
            $this->assertEquals($before, $snapshot());
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame($beforeCredit, $ledger->accountBalanceMinor($subscriber->fresh()));
            $this->assertSame($beforeCash, $cash());
            $this->assertSame(['count' => 2, 'claimed_minor' => 10], $this->gate(fn () => $service->unresolvedForAccount($f['id'])));
            $this->rejects('completed_cash_refund_incident_unresolved', fn () => $this->gate(fn () =>
                app(\App\Services\HeldCashReservation::class)->assertOrdinaryOutflow($f['id'], 1, '2026-11-25')));
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS qa_mixed_final_audit');
        }
        $this->assertTrue($post()['registry_created']);
        $this->assertSame($beforeCredit - 6, $ledger->accountBalanceMinor($subscriber->fresh()));
        $this->assertSame($beforeCash - 6, $cash());
        $this->assertSame(1, DB::table('payment_refunds')->count());
        $this->assertSame(0, DB::table('financial_posting_operations')->where('operation_uuid', $f['second'])->count());
        $this->assertFalse($post()['registry_created']);
    }

    public function test_release_refund_mixed_controls_revoked_nonposting_source_blocks_entire_settlement(): void
    {
        ['f' => $f, 'ids' => $ids, 'keys' => $keys, 'service' => $service] = $this->mixedAtomicControl();
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $plan = $service->mixedSourceReadiness($f['approver'], $f['id'], $f['proposalIds'], $ids, $this->nowAt());
        $this->assertTrue($plan['all_source_signatures_revalidated']);
        $tables = ['payments', 'subscribers', 'invoices', 'payment_refunds', 'transactions', 'treasury_accounts',
            'treasury_postings', 'payment_allocations', 'payment_allocation_revisions', 'financial_posting_operations',
            'financial_posting_events', 'audit_events', 'treasury_cash_counts', 'treasury_cash_count_reviews'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->orderBy(
            $table === 'financial_posting_operations' ? 'operation_uuid' : 'id')->get()->toArray(), $tables);
        $before = $snapshot();
        $post = fn (array $selection) => $service->postMixedResolution($f['approver'], $f['id'], $f['proposalIds'], $selection,
            'Independent verified evidence supports the complete mixed cash settlement', $this->nowAt());
        $raw = base64_decode($keys['qa-source-1'], true);
        config(['financial_candidate.refund_source_public_keys' => ['qa-source-0' => $keys['qa-source-0']],
            'financial_candidate.refund_source_archived_public_keys' => ['qa-source-1' => [hash('sha256', $raw) => $keys['qa-source-1']]]]);
        $this->rejects('completed_refund_source_untrusted', fn () => $post($ids));
        $this->assertEquals($before, $snapshot());
        config(['financial_candidate.refund_source_public_keys' => $keys]);
        $this->rejects('completed_refund_mixed_source_selection', fn () => $post([$f['uuid'] => $ids[$f['uuid']]]));
        $this->rejects('completed_refund_mixed_source_selection', fn () => $post(array_fill_keys(array_keys($ids), $ids[$f['uuid']])));
        $this->rejects('completed_refund_source_evidence', fn () => $post(array_combine(array_keys($ids), array_reverse(array_values($ids)))));
        $this->assertEquals($before, $snapshot());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(['count' => 2, 'claimed_minor' => 10], $this->gate(fn () => $service->unresolvedForAccount($f['id'])));
        $this->rejects('completed_cash_refund_incident_unresolved', fn () => $this->gate(fn () =>
            app(\App\Services\HeldCashReservation::class)->assertOrdinaryOutflow($f['id'], 1, '2026-11-25')));
        $this->assertTrue($post($ids)['registry_created']);
        $this->assertFalse($post($ids)['registry_created']);
        $this->assertSame(1, DB::table('payment_refunds')->count());
        $this->assertSame(0, DB::table('financial_posting_operations')->where('operation_uuid', $f['second'])->count());
    }

    public function test_release_refund_mixed_atomic_posts_only_required_member_and_preserves_historical_proof(): void
    {
        ['f' => $f, 'ids' => $ids, 'keys' => $keys, 'service' => $service] = $this->mixedAtomicControl();
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $confirmation = 'Independent verified evidence supports the complete mixed cash settlement';
        $post = fn () => $service->postMixedResolution($f['approver'], $f['id'], $f['proposalIds'], $ids, $confirmation, $this->nowAt());
        $result = $post();
        $this->assertTrue($result['registry_created']);
        $this->assertSame(10, $result['batch']['total_claimed_minor']);
        $this->assertSame(6, $result['batch']['posting_required_minor']);
        $this->assertSame(0, $result['current_active_release_liquidity']['unresolved_refund_incident_count']);
        $this->assertSame(1, DB::table('payment_refunds')->count());
        $this->assertSame(2, DB::table('financial_posting_events')->where('operation_uuid', $f['uuid'])->count());
        $this->assertSame(0, DB::table('financial_posting_operations')->where('operation_uuid', $f['second'])->count());
        $this->assertSame($f['counted'], $this->gate(fn () => app(\App\Services\HeldCashRecordedLiquidityEvidence::class)
            ->balance($f['id'], '2026-11-25')));
        config(['financial_candidate.refund_source_public_keys' => [], 'financial_candidate.refund_source_archived_public_keys' => []]);
        $this->assertFalse($post()['registry_created']);
        $this->getJson($f['root'].$f['uuid'])->assertOk()->assertJsonPath('completion_verified', true)
            ->assertJsonPath('incident_unresolved', false);
        $this->getJson($f['root'].$f['second'])->assertOk()->assertJsonPath('completion_verified', false)
            ->assertJsonPath('incident_unresolved', false);
        $this->assertSame(['count' => 0, 'claimed_minor' => 0], $this->gate(fn () => $service->unresolvedForAccount($f['id'])));
        $this->rejects('completed_refund_batch_conflict', fn () =>
            $service->postBatchResolution($f['approver'], $f['id'], $f['proposalIds'], $confirmation, $this->nowAt()));
        $this->assertSame(1, AuditEvent::query()->where('event', 'financial.completed_cash_refund_batch_approved')->count());
        $this->assertSame(1, DB::table('payment_refunds')->count());
    }

    public function test_release_refund_mixed_atomic_zero_posting_closes_without_financial_effect(): void
    {
        ['f' => $f, 'ids' => $ids, 'service' => $service] = $this->mixedAtomicControl(0, ['no_disbursement', 'cash_returned']);
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $tables = ['payment_refunds', 'transactions', 'treasury_postings', 'financial_posting_operations', 'financial_posting_events'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->get()->toArray(), $tables);
        $before = $snapshot();
        $post = fn () => $service->postMixedResolution($f['approver'], $f['id'], $f['proposalIds'], $ids,
            'Independent verified evidence supports no net cash disbursement', $this->nowAt());
        $result = $post();
        $this->assertTrue($result['registry_created']);
        $this->assertFalse($result['batch']['financial_posting_created']);
        $this->assertSame(0, $result['batch']['posting_required_minor']);
        $this->assertSame(0, $result['current_active_release_liquidity']['unresolved_refund_incident_count']);
        $this->assertFalse($post()['registry_created']);
        $this->assertEquals($before, $snapshot());
        foreach (array_keys($ids) as $uuid) {
            $this->getJson($f['root'].$uuid)->assertOk()->assertJsonPath('completion_verified', false)
                ->assertJsonPath('incident_unresolved', false);
        }
    }

    private function assertGroupRenewalRace(bool $differentReviewer, bool $zeroPosting = false): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent shared-database connections require the MySQL QA runner.');
        }
        $f = $zeroPosting ? $this->batchResolutionControl(0, '1.00', ['no_disbursement', 'cash_returned'])
            : $this->batchResolutionControl(-6, '1.00', ['posting_required', 'cash_returned']);
        $service = app(\App\Services\CompletedCashRefundRequest::class);
        $counts = app(TreasuryCashCountService::class);
        $new = $counts->record($f['counter'], $f['id'], $f['amount'], 'QA-RACE-RENEWAL', self::EVIDENCE)['count'];
        $counts->review($f['countReviewer'], (int) $new['id'], true, self::EVIDENCE);
        $countId = (int) $new['id'];
        // The worker proposing the renewal cannot be the later settlement reviewer.
        $settlementReviewer = $this->actor();
        $tables = ['payments', 'subscribers', 'invoices', 'payment_refunds', 'transactions',
            'treasury_postings', 'payment_allocations', 'payment_allocation_revisions',
            'financial_posting_operations', 'financial_posting_events'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->get()->toArray(), $tables);
        $before = $snapshot();
        $results = $this->refundResolutionRace($f, $differentReviewer, false, null, $countId);
        $success = array_values(array_filter($results, fn ($r) => $r['ok']));
        $this->assertCount($differentReviewer ? 1 : 2, $success, json_encode($results));
        $this->assertCount(1, array_filter($success, fn ($r) => $r['created']), json_encode($results));
        if ($differentReviewer) {
            $rejected = collect($results)->firstWhere('ok', false);
            $this->assertSame(DomainException::class, $rejected['error'], json_encode($results));
            $this->assertSame('completed_refund_group_revision_selection', $rejected['message']);
        }
        $winner = collect($success)->firstWhere('created', true);
        foreach ($success as $item) {
            $this->assertSame($winner['proposal_ids'], $item['proposal_ids']);
            $this->assertSame($winner['group_hash'], $item['group_hash']);
        }
        $revisions = AuditEvent::query()->where('event', 'financial.completed_cash_refund_resolution_revised')->orderBy('id')->get();
        $this->assertCount(2, $revisions);
        foreach ($revisions as $row) {
            $p = $row->after;
            $uuid = $p['operation_uuid'];
            $this->assertSame($winner['actor'], (int) $row->actor_user_id);
            $this->assertSame($winner['proposal_ids'][$uuid], (int) $row->id);
            $this->assertSame($f['proposalIds'][$uuid], $p['supersedes_id']);
            $this->assertSame($winner['group_hash'], $p['group_revision_hash']);
            $this->assertSame($countId, $p['count_id']);
            $this->assertSame($zeroPosting ? 0 : -6, $p['variance_minor']);
        }
        $this->assertEquals($before, $snapshot());
        $this->assertSame(0, AuditEvent::query()->where('event', 'financial.completed_cash_refund_batch_approved')->count());
        $this->assertSame(['count' => 2, 'claimed_minor' => 10], $this->gate(fn () => $service->unresolvedForAccount($f['id'])));
        \Laravel\Sanctum\Sanctum::actingAs($settlementReviewer);
        $plan = $service->mixedResolutionReadiness($settlementReviewer, $f['id'], $winner['proposal_ids'], $this->nowAt());
        $this->assertSame($countId, $plan['count_id']);
        $this->assertSame($zeroPosting ? 0 : 6, $plan['posting_required_minor']);
        $this->assertFalse($plan['closure_authorized']);
        $this->assertFalse($plan['spending_authorized']);
        $this->rejects('completed_cash_refund_incident_unresolved', fn () => $this->gate(fn () =>
            app(\App\Services\HeldCashReservation::class)->assertOrdinaryOutflow($f['id'], 1, '2026-11-25')));
    }

    public function test_release_refund_renewal_race_identical_requests_create_one_group(): void
    {
        $this->assertGroupRenewalRace(false);
    }

    public function test_release_refund_renewal_race_competing_proposers_create_one_group(): void
    {
        $this->assertGroupRenewalRace(true);
    }

    public function test_release_refund_renewal_race_zero_posting_creates_one_group(): void
    {
        $this->assertGroupRenewalRace(true, true);
    }

    public function test_release_refund_renewal_membership_rejects_counterfeit_equal_hashes_and_missing_member_marker(): void
    {
        ['f' => $f, 'ids' => $oldIds, 'service' => $service] = $this->mixedAtomicControl();
        $counts = app(TreasuryCashCountService::class);
        $new = $counts->record($f['counter'], $f['id'], $f['amount'], 'QA-MEMBERSHIP-RENEWAL', self::EVIDENCE)['count'];
        $counts->review($f['countReviewer'], (int) $new['id'], true, self::EVIDENCE);
        $actor = $this->actor();
        \Laravel\Sanctum\Sanctum::actingAs($actor);
        $renewed = $service->renewResolutionGroup($actor, $f['id'], $f['proposalIds'], (int) $new['id'], self::EVIDENCE, $this->nowAt());
        $ids = $renewed['proposal_ids'];
        $originals = AuditEvent::query()->whereIn('id', array_values($ids))->orderBy('id')->get();
        $this->assertCount(2, $originals);
        $protection = app(\App\Services\CompletedCashRefundResolutionRevisionProtection::class);
        $this->assertTrue($protection->active());
        // Simulate counterfeit persisted evidence in the disposable database.
        // Reinstall the authentic guard before every actual service call.
        $replace = function (callable $change) use ($originals, $protection): void {
            DB::unprepared('DROP TRIGGER completed_refund_res_rev_audit_update');
            try {
                foreach ($originals as $row) {
                    $after = $change($row->after, (int) $row->id);
                    DB::table('audit_events')->where('id', $row->id)->update(['after' => json_encode($after, JSON_THROW_ON_ERROR)]);
                }
            } finally {
                $protection->install();
            }
            $this->assertTrue($protection->active());
        };
        $recorder = $this->actor();
        $tables = ['payment_refunds', 'transactions', 'treasury_postings', 'financial_posting_operations',
            'financial_posting_events', 'audit_events'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->get()->toArray(), $tables);
        $check = function () use ($service, $f, $ids, $oldIds, $recorder, $snapshot): void {
            $before = $snapshot();
            \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
            $this->rejects('completed_refund_group_revision_evidence', fn () => $service->inspect($f['approver'], $f['uuid']));
            $this->rejects('completed_refund_group_revision_evidence', fn () =>
                $service->mixedResolutionReadiness($f['approver'], $f['id'], $ids, $this->nowAt()));
            $this->rejects('completed_refund_group_revision_evidence', fn () =>
                $service->postMixedResolution($f['approver'], $f['id'], $ids, $oldIds, self::EVIDENCE, $this->nowAt()));
            \Laravel\Sanctum\Sanctum::actingAs($recorder);
            $this->rejects('completed_refund_group_revision_evidence', fn () =>
                $service->recordResolutionDocument($recorder, $f['uuid'], $ids[$f['uuid']], 'QA-MEMBERSHIP-DOC',
                    'Synthetic new claim evidence for membership test', $this->nowAt()));
            $this->assertEquals($before, $snapshot());
            $this->assertSame(0, DB::table('payment_refunds')->count());
            $this->assertSame(['count' => 2, 'claimed_minor' => 10], $this->gate(fn () => $service->unresolvedForAccount($f['id'])));
        };
        try {
            $replace(static function (array $q, int $id): array {
                $q['group_revision_hash'] = str_repeat('a', 64);
                return $q;
            });
            $check(); // All stored hashes agree, but none is authentic.
            $replace(static function (array $q, int $id) use ($ids, $f): array {
                if ($id === $ids[$f['second']]) unset($q['group_revision_hash']);
                return $q;
            });
            $check(); // One member claims to be an ordinary individual revision.
            $replace(static function (array $q, int $id): array {
                unset($q['group_revision_hash']);
                return $q;
            });
            $check(); // Removing both markers cannot downgrade the group.
        } finally {
            $replace(static fn (array $q, int $id): array => $q);
        }
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $plan = $service->mixedResolutionReadiness($f['approver'], $f['id'], $ids, $this->nowAt());
        $this->assertSame(6, $plan['posting_required_minor']);
        $this->assertFalse($plan['closure_authorized']);
        $this->assertSame($ids[$f['uuid']], $service->inspect($f['approver'], $f['uuid'])['current_proposal_id']);
    }

    public function test_release_refund_group_renewal_is_atomic_and_requires_new_signed_evidence(): void
    {
        ['f' => $f, 'ids' => $oldIds, 'keys' => $keys, 'service' => $service] = $this->mixedAtomicControl();
        $counts = app(TreasuryCashCountService::class);
        $new = $counts->record($f['counter'], $f['id'], $f['amount'], 'QA-GROUP-RENEWAL', self::EVIDENCE)['count'];
        $counts->review($f['countReviewer'], (int) $new['id'], true, self::EVIDENCE);
        $countId = (int) $new['id'];
        $actor = $this->actor();
        \Laravel\Sanctum\Sanctum::actingAs($actor);
        $tables = ['payment_refunds', 'transactions', 'treasury_postings', 'financial_posting_operations',
            'financial_posting_events', 'audit_events'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->get()->toArray(), $tables);
        $before = $snapshot();
        $renew = fn (array $ids, string $evidence = self::EVIDENCE) =>
            $service->renewResolutionGroup($actor, $f['id'], $ids, $countId, $evidence, $this->nowAt());
        $this->rejects('completed_refund_group_revision_selection', fn () => $renew([$f['uuid'] => $f['proposalIds'][$f['uuid']]]));
        $this->rejects('completed_refund_group_revision_selection', fn () =>
            $renew(array_fill_keys(array_keys($f['proposalIds']), reset($f['proposalIds']))));
        $this->rejects('completed_refund_group_revision_required', fn () =>
            $service->proposeResolution($actor, $f['uuid'], $countId, 'posting_required', self::EVIDENCE,
                $this->nowAt(), $f['proposalIds'][$f['uuid']]));
        $this->assertEquals($before, $snapshot());

        $condition = "NEW.event = 'financial.completed_cash_refund_resolution_revised'
            AND NEW.entity_id = '{$f['second']}:{$countId}'
            AND EXISTS (SELECT 1 FROM audit_events WHERE event = 'financial.completed_cash_refund_resolution_revised'
                AND entity_id = '{$f['uuid']}:{$countId}')";
        $body = DB::getDriverName() === 'sqlite'
            ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'qa_group_renewal_second'); END"
            : "FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'qa_group_renewal_second'; END IF; END";
        DB::unprepared("CREATE TRIGGER qa_group_renewal_second BEFORE INSERT ON audit_events {$body}");
        \Illuminate\Support\Facades\Log::spy();
        try {
            try {
                $renew($f['proposalIds']);
                $this->fail('Group renewal committed despite second revision audit failure.');
            } catch (\RuntimeException $error) {
                $this->assertSame('Financial operation requires a durable audit record.', $error->getMessage());
            }
            \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->with('audit.record_failed',
                \Mockery::on(static fn ($context) =>
                    ($context['event'] ?? null) === 'financial.completed_cash_refund_resolution_revised'
                    && str_contains((string) ($context['error'] ?? ''), 'qa_group_renewal_second')))->once();
            $this->assertEquals($before, $snapshot());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS qa_group_renewal_second');
        }
        $result = $renew($f['proposalIds']);
        $this->assertTrue($result['created']);
        $this->assertSame(10, $result['total_claimed_minor']);
        $this->assertSame(6, $result['posting_required_minor']);
        $this->assertSame($countId, $result['count_id']);
        foreach (['financial_posting_created', 'spending_authorized', 'closure_authorized'] as $field) {
            $this->assertFalse($result[$field]);
        }
        $ids = $result['proposal_ids'];
        $revisions = AuditEvent::query()->where('event', 'financial.completed_cash_refund_resolution_revised')->orderBy('id')->get();
        $this->assertCount(2, $revisions);
        foreach ($revisions as $revision) {
            $uuid = $revision->after['operation_uuid'];
            $this->assertSame($ids[$uuid], (int) $revision->id);
            $this->assertSame($f['proposalIds'][$uuid], $revision->after['supersedes_id']);
            $this->assertSame($result['group_revision_hash'], $revision->after['group_revision_hash']);
            $this->assertSame(-6, $revision->after['variance_minor']);
        }
        $after = $snapshot();
        $this->assertSame(array_replace($result, ['created' => false]), $renew(array_reverse($f['proposalIds'], true)));
        $this->rejects('completed_refund_resolution_conflict', fn () => $renew($f['proposalIds'], self::EVIDENCE.' altered'));
        $this->assertEquals($after, $snapshot());
        $this->assertEquals(array_slice($before, 0, 5), array_slice($after, 0, 5));
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $this->rejects('completed_refund_resolution_proposal_changed', fn () =>
            $service->mixedSourceReadiness($f['approver'], $f['id'], $f['proposalIds'], $oldIds, $this->nowAt()));
        $this->rejects('completed_refund_source_evidence', fn () =>
            $service->mixedSourceReadiness($f['approver'], $f['id'], $ids, $oldIds, $this->nowAt()));

        // New, independently reviewed documents and statements are bound to
        // the revised proposals; none of the original audit rows is overwritten.
        $recorder = $this->actor();
        $byteReviewer = $this->actor();
        $sourceReviewer = $this->actor();
        $freshIds = [];
        foreach ($ids as $uuid => $proposalId) {
            $suffix = count($freshIds);
            $reference = 'QA-RENEWED-DOC-'.$suffix;
            $sourceRef = 'QA-RENEWED-SOURCE-'.$suffix;
            $issuer = 'qa-renewed-'.$suffix;
            $bytes = 'New synthetic independently sourced document for '.$uuid;
            \Laravel\Sanctum\Sanctum::actingAs($recorder);
            $doc = $service->recordResolutionDocument($recorder, $uuid, $proposalId, $reference, $bytes, $this->nowAt());
            \Laravel\Sanctum\Sanctum::actingAs($byteReviewer);
            $service->reviewResolutionDocument($byteReviewer, $uuid, $proposalId, $doc['document_id'], $reference,
                $bytes, self::EVIDENCE, $this->nowAt());
            $pair = sodium_crypto_sign_keypair();
            $secret = sodium_crypto_sign_secretkey($pair);
            $keys[$issuer] = base64_encode(sodium_crypto_sign_publickey($pair));
            config(['financial_candidate.refund_source_public_keys' => $keys]);
            $signature = base64_encode(sodium_crypto_sign_detached(
                \App\Services\CompletedCashRefundRequest::sourceStatement($doc['document'], $issuer, $sourceRef), $secret));
            sodium_memzero($secret);
            \Laravel\Sanctum\Sanctum::actingAs($sourceReviewer);
            $freshIds[$uuid] = $service->attestResolutionSource($sourceReviewer, $uuid, $proposalId, $doc['document_id'],
                $issuer, $sourceRef, $signature, self::EVIDENCE, $this->nowAt())['attestation_id'];
        }
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $plan = $service->mixedSourceReadiness($f['approver'], $f['id'], $ids, $freshIds, $this->nowAt());
        $this->assertTrue($plan['all_source_signatures_revalidated']);
        $this->assertSame($countId, $plan['count_id']);
        $this->assertSame(6, $plan['posting_required_minor']);
        $this->assertFalse($plan['spending_authorized']);
        $this->assertSame(4, AuditEvent::query()->where('event', 'financial.completed_cash_refund_source_attested')->count());
        $this->assertSame(0, DB::table('payment_refunds')->count());
        $this->assertSame(['count' => 2, 'claimed_minor' => 10], $this->gate(fn () => $service->unresolvedForAccount($f['id'])));
        $request = AuditEvent::query()->where('event', 'financial.completed_cash_refund_requested')
            ->where('entity_id', $f['uuid'])->sole();
        $subscriber = Subscriber::findOrFail(Payment::findOrFail($request->after['payment_id'])->subscriber_id);
        $ledger = app(\App\Services\TransactionService::class);
        $creditBefore = $ledger->accountBalanceMinor($subscriber);
        $confirmation = 'Independent verified renewed evidence supports the complete mixed cash settlement';
        $post = fn () => $service->postMixedResolution($f['approver'], $f['id'], $ids, $freshIds, $confirmation, $this->nowAt());
        $settled = $post();
        $this->assertTrue($settled['registry_created']);
        $this->assertSame(6, $settled['batch']['posting_required_minor']);
        $this->assertSame($creditBefore - 6, $ledger->accountBalanceMinor($subscriber->fresh()));
        $this->assertSame($f['counted'], $this->gate(fn () => app(\App\Services\HeldCashRecordedLiquidityEvidence::class)
            ->balance($f['id'], '2026-11-25')));
        $this->assertSame(1, DB::table('payment_refunds')->count());
        $legs = DB::table('financial_posting_events')->where('operation_uuid', $f['uuid'])->orderBy('leg')->get();
        $this->assertSame(['subscriber', 'treasury'], $legs->pluck('leg')->all());
        $this->assertSame([-6, -6], $legs->map(fn ($leg) => (int) $leg->amount_minor)->all());
        $this->assertSame(0, DB::table('financial_posting_operations')->where('operation_uuid', $f['second'])->count());
        $approval = AuditEvent::query()->where('event', 'financial.completed_cash_refund_batch_approved')->sole();
        $binding = json_decode($approval->after['source_binding_json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($freshIds, array_column($binding['sources'], 'attestation_id', 'operation_uuid'));
        $this->assertSame($ids, array_column($binding['sources'], 'proposal_id', 'operation_uuid'));
        $afterSettlement = $snapshot();
        config(['financial_candidate.refund_source_public_keys' => [], 'financial_candidate.refund_source_archived_public_keys' => []]);
        $this->assertFalse($post()['registry_created']);
        $this->assertEquals($afterSettlement, $snapshot());
        $this->assertSame(['count' => 0, 'claimed_minor' => 0], $this->gate(fn () => $service->unresolvedForAccount($f['id'])));
    }

    public function test_release_refund_group_renewal_full_posting_uses_aggregate_variance(): void
    {
        // A fully posting group uses the total claim amount rather than
        // either individual refund amount.
        $f = $this->batchResolutionControl();
        $service = app(\App\Services\CompletedCashRefundRequest::class);
        $counts = app(TreasuryCashCountService::class);
        $new = $counts->record($f['counter'], $f['id'], $f['amount'], 'QA-FULL-RENEWAL', self::EVIDENCE)['count'];
        $counts->review($f['countReviewer'], (int) $new['id'], true, self::EVIDENCE);
        $actor = $this->actor();
        \Laravel\Sanctum\Sanctum::actingAs($actor);
        $result = $service->renewResolutionGroup($actor, $f['id'], $f['proposalIds'], (int) $new['id'], self::EVIDENCE, $this->nowAt());
        $this->assertSame(10, $result['posting_required_minor']);
        $this->assertFalse($result['financial_posting_created']);
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $plan = $service->batchPostingResolutionReadiness($f['approver'], $f['id'], $result['proposal_ids'], $this->nowAt());
        $this->assertSame(-10, $plan['expected_treasury_delta_minor']);
        $this->assertFalse($plan['spending_authorized']);
    }

    public function test_release_refund_group_renewal_zero_posting_rejects_wrong_count_and_reviewer(): void
    {
        $f = $this->batchResolutionControl(0, '1.00', ['no_disbursement', 'cash_returned']);
        $service = app(\App\Services\CompletedCashRefundRequest::class);
        $counts = app(TreasuryCashCountService::class);
        $wrong = $f['counted'] - 1;
        $wrongAmount = sprintf('%d.%02d', intdiv($wrong, 100), $wrong % 100);
        $bad = $counts->record($f['counter'], $f['id'], $wrongAmount, 'QA-ZERO-RENEWAL-WRONG', self::EVIDENCE)['count'];
        $counts->review($f['countReviewer'], (int) $bad['id'], true, self::EVIDENCE);
        $actor = $this->actor();
        \Laravel\Sanctum\Sanctum::actingAs($actor);
        $this->rejects('completed_refund_resolution_stale_count', fn () =>
            $service->renewResolutionGroup($actor, $f['id'], $f['proposalIds'], (int) $bad['id'], self::EVIDENCE, $this->nowAt()));
        $new = $counts->record($f['counter'], $f['id'], $f['amount'], 'QA-ZERO-RENEWAL', self::EVIDENCE)['count'];
        $counts->review($f['countReviewer'], (int) $new['id'], true, self::EVIDENCE);
        \Laravel\Sanctum\Sanctum::actingAs($f['countReviewer']);
        $this->rejects('completed_refund_resolution_independence', fn () =>
            $service->renewResolutionGroup($f['countReviewer'], $f['id'], $f['proposalIds'], (int) $new['id'], self::EVIDENCE, $this->nowAt()));
        $this->assertSame(0, AuditEvent::query()->where('event', 'financial.completed_cash_refund_resolution_revised')->count());
        \Laravel\Sanctum\Sanctum::actingAs($actor);
        $result = $service->renewResolutionGroup($actor, $f['id'], $f['proposalIds'], (int) $new['id'], self::EVIDENCE, $this->nowAt());
        $this->assertSame(0, $result['posting_required_minor']);
        $this->assertSame(10, $result['total_claimed_minor']);
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $plan = $service->mixedResolutionReadiness($f['approver'], $f['id'], $result['proposal_ids'], $this->nowAt());
        $this->assertSame(0, $plan['expected_treasury_delta_minor']);
        $this->assertFalse($plan['closure_authorized']);
        $this->assertSame(0, DB::table('payment_refunds')->count());
    }

    public function test_release_refund_mixed_source_plan_requires_complete_independent_current_sources(): void
    {
        $f = $this->batchResolutionControl(-6, '1.00', ['posting_required', 'cash_returned']);
        $service = app(\App\Services\CompletedCashRefundRequest::class);
        $recorder = $this->actor();
        $byteReviewer = $this->actor();
        $sourceReviewer = $this->actor();
        $ids = [];
        $keys = [];
        foreach ($f['proposalIds'] as $uuid => $proposal) {
            $issuer = 'qa-source-'.count($ids);
            $reference = 'QA-DOC-'.count($ids);
            $sourceRef = 'QA-EXTERNAL-'.count($ids);
            $bytes = 'Synthetic QA evidence for the exact claim '.$uuid;
            \Laravel\Sanctum\Sanctum::actingAs($recorder);
            $doc = $service->recordResolutionDocument($recorder, $uuid, $proposal, $reference, $bytes, $this->nowAt());
            \Laravel\Sanctum\Sanctum::actingAs($byteReviewer);
            $service->reviewResolutionDocument($byteReviewer, $uuid, $proposal, $doc['document_id'], $reference, $bytes,
                'Independent reviewer compared the original document bytes', $this->nowAt());
            $pair = sodium_crypto_sign_keypair();
            $secret = sodium_crypto_sign_secretkey($pair);
            $keys[$issuer] = base64_encode(sodium_crypto_sign_publickey($pair));
            config(['financial_candidate.refund_source_public_keys' => $keys]);
            $sig = base64_encode(sodium_crypto_sign_detached(
                \App\Services\CompletedCashRefundRequest::sourceStatement($doc['document'], $issuer, $sourceRef), $secret));
            sodium_memzero($secret);
            \Laravel\Sanctum\Sanctum::actingAs($sourceReviewer);
            $attest = $service->attestResolutionSource($sourceReviewer, $uuid, $proposal, $doc['document_id'],
                $issuer, $sourceRef, $sig, 'Independent reviewer inspected the synthetic source statement', $this->nowAt());
            $ids[$uuid] = $attest['attestation_id'];
        }
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $review = fn (array $selected) => $service->mixedSourceReadiness(
            $f['approver'], $f['id'], $f['proposalIds'], $selected, $this->nowAt());
        $tables = ['payment_refunds', 'transactions', 'treasury_postings', 'financial_posting_operations',
            'financial_posting_events', 'audit_events'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->get()->toArray(), $tables);
        $before = $snapshot();
        $this->rejects('completed_refund_mixed_source_selection', fn () => $review([$f['uuid'] => $ids[$f['uuid']]]));
        $this->rejects('completed_refund_mixed_source_selection', fn () => $review(array_fill_keys(array_keys($ids), reset($ids))));
        $this->rejects('completed_refund_source_evidence', fn () => $review(array_combine(array_keys($ids), array_reverse(array_values($ids)))));
        $plan = $review($ids);
        $this->assertTrue($plan['all_source_signatures_revalidated']);
        $this->assertSame(10, $plan['total_claimed_minor']);
        $this->assertSame(6, $plan['posting_required_minor']);
        $this->assertSame(array_values($ids), array_column($plan['source_binding']['sources'], 'attestation_id'));
        $this->assertSame(64, strlen($plan['source_binding_sha256']));
        $this->assertSame($plan, $review(array_reverse($ids, true)));
        foreach (['physical_facts_automatically_verified', 'closure_authorized', 'financial_posting_created', 'spending_authorized',
            'writer_eligibility_verified'] as $field) {
            $this->assertFalse($plan[$field]);
        }
        $this->assertTrue($plan['incident_unresolved']);
        config(['financial_candidate.refund_source_public_keys' => ['qa-source-0' => $keys['qa-source-0']]]);
        $this->rejects('completed_refund_source_untrusted', fn () => $review($ids));
        config(['financial_candidate.refund_source_public_keys' => $keys]);
        \Laravel\Sanctum\Sanctum::actingAs($sourceReviewer);
        $this->rejects('completed_refund_document_independence', fn () =>
            $service->mixedSourceReadiness($sourceReviewer, $f['id'], $f['proposalIds'], $ids, $this->nowAt()));
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $this->assertSame($plan, $review($ids));
        $this->assertEquals($before, $snapshot());
        $this->assertSame(['count' => 2, 'claimed_minor' => 10], $this->gate(fn () => $service->unresolvedForAccount($f['id'])));
        $this->rejects('completed_cash_refund_incident_unresolved', fn () => $this->gate(fn () =>
            app(\App\Services\HeldCashReservation::class)->assertOrdinaryOutflow($f['id'], 1, '2026-11-25')));
    }

    public function test_release_refund_source_attestation_checks_trust_signature_and_independence(): void
    {
        $f = $this->batchResolutionControl(-6, '1.00', ['posting_required', 'cash_returned']);
        $service = app(\App\Services\CompletedCashRefundRequest::class);
        $recorder = $this->actor();
        $byteReviewer = $this->actor();
        $sourceReviewer = $this->actor();
        $uuid = $f['second'];
        $proposal = $f['proposalIds'][$uuid];
        $bytes = 'Synthetic returned cash source evidence; no real external document.';
        $reference = 'QA-ATTEST-DOC';
        \Laravel\Sanctum\Sanctum::actingAs($recorder);
        $doc = $service->recordResolutionDocument($recorder, $uuid, $proposal, $reference, $bytes, $this->nowAt());
        $id = $doc['document_id'];
        $issuer = 'qa-source';
        $sourceRef = 'QA-EXTERNAL-001';
        $keypair = sodium_crypto_sign_keypair();
        $key = sodium_crypto_sign_publickey($keypair);
        $secret = sodium_crypto_sign_secretkey($keypair);
        $statement = \App\Services\CompletedCashRefundRequest::sourceStatement($doc['document'], $issuer, $sourceRef);
        $signature = base64_encode(sodium_crypto_sign_detached($statement, $secret));
        $confirmation = 'Independent source reviewer inspected the claimed cash return evidence';
        $attest = fn (User $actor, string $sig, string $ref = 'QA-EXTERNAL-001') =>
            $service->attestResolutionSource($actor, $uuid, $proposal, $id, $issuer, $ref, $sig, $confirmation, $this->nowAt());
        \Laravel\Sanctum\Sanctum::actingAs($sourceReviewer);
        $this->rejects('completed_refund_source_evidence', fn () => $attest($sourceReviewer, $signature));
        \Laravel\Sanctum\Sanctum::actingAs($byteReviewer);
        $service->reviewResolutionDocument($byteReviewer, $uuid, $proposal, $id, $reference, $bytes,
            'Independent reviewer compared the original document bytes', $this->nowAt());
        \Laravel\Sanctum\Sanctum::actingAs($sourceReviewer);
        $this->rejects('completed_refund_source_untrusted', fn () => $attest($sourceReviewer, $signature));
        config(['financial_candidate.refund_source_public_keys' => [$issuer => base64_encode($key)]]);
        $this->rejects('completed_refund_source_signature', fn () => $attest($sourceReviewer, $signature, 'QA-EXTERNAL-002'));
        $wrong = $doc['document'];
        $wrong['claimed_amount'] = '0.05';
        $wrongSignature = base64_encode(sodium_crypto_sign_detached(
            \App\Services\CompletedCashRefundRequest::sourceStatement($wrong, $issuer, $sourceRef), $secret));
        $this->rejects('completed_refund_source_signature', fn () => $attest($sourceReviewer, $wrongSignature));
        \Laravel\Sanctum\Sanctum::actingAs($byteReviewer);
        $this->rejects('completed_refund_document_independence', fn () => $attest($byteReviewer, $signature));
        \Laravel\Sanctum\Sanctum::actingAs($sourceReviewer);
        $result = $attest($sourceReviewer, $signature);
        $this->assertTrue($result['created']);
        $this->assertTrue($result['attestation']['trusted_signature_verified']);
        $this->assertFalse($result['attestation']['physical_fact_automatically_verified']);
        $this->assertFalse($result['attestation']['closure_authorized']);
        $this->assertFalse($result['financial_posting_created']);
        $this->assertFalse($result['spending_authorized']);
        $this->assertTrue($result['incident_unresolved']);
        $this->assertFalse($attest($sourceReviewer, $signature)['created']);
        $attestationId = $result['attestation_id'];
        $this->rejects('completed_refund_document_independence', fn () =>
            $service->revalidateResolutionSource($sourceReviewer, $uuid, $proposal, $attestationId, $this->nowAt()));
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $revalidate = fn () => $service->revalidateResolutionSource($f['approver'], $uuid, $proposal, $attestationId, $this->nowAt());
        $tables = ['payment_refunds', 'transactions', 'treasury_postings', 'financial_posting_operations',
            'financial_posting_events', 'audit_events'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->get()->toArray(), $tables);
        $unchanged = $snapshot();
        $verified = $revalidate();
        $this->assertTrue($verified['trusted_signature_revalidated']);
        $this->assertSame($attestationId, $verified['attestation_id']);
        $this->assertSame(hash('sha256', $key), $verified['public_key_sha256']);
        $this->assertFalse($verified['physical_fact_automatically_verified']);
        $this->assertFalse($verified['closure_authorized']);
        $this->assertFalse($verified['financial_posting_created']);
        $this->assertFalse($verified['spending_authorized']);
        $this->assertTrue($verified['incident_unresolved']);
        $this->assertSame($verified, $revalidate());
        $historical = fn () => $service->inspectHistoricalResolutionSource($f['approver'], $uuid, $proposal, $attestationId, $this->nowAt());
        $history = $historical();
        $this->assertTrue($history['historical_signature_verified']);
        $this->assertFalse($history['current_trust_revalidated']);
        $this->assertFalse($history['historical_trust_at_approval_proven']);
        $this->assertFalse($history['closure_authorized']);
        $this->assertFalse($history['spending_authorized']);
        $this->assertSame($verified['attestation_hash'], $history['attestation_hash']);
        config(['financial_candidate.refund_source_public_keys' => []]);
        $this->rejects('completed_refund_source_untrusted', $revalidate);
        $this->rejects('completed_refund_source_untrusted', $historical);
        $fingerprint = hash('sha256', $key);
        config(['financial_candidate.refund_source_archived_public_keys' => [$issuer => [$fingerprint => base64_encode($key)]]]);
        $this->assertSame($history, $historical());
        $this->rejects('completed_refund_source_untrusted', $revalidate);
        $rotated = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        config(['financial_candidate.refund_source_archived_public_keys' => [$issuer => [$fingerprint => base64_encode($rotated)]]]);
        $this->rejects('completed_refund_source_untrusted', $historical);
        config(['financial_candidate.refund_source_archived_public_keys' => [$issuer => [$fingerprint => base64_encode($key)]]]);
        config(['financial_candidate.refund_source_public_keys' => [$issuer => base64_encode($rotated)]]);
        $this->rejects('completed_refund_source_signature', $revalidate);
        $this->assertSame($history, $historical());
        config(['financial_candidate.refund_source_public_keys' => [$issuer => base64_encode($key)]]);
        $this->assertSame($verified, $revalidate());
        $this->rejects('completed_refund_resolution_proposal_changed', fn () =>
            $service->revalidateResolutionSource($f['approver'], $uuid, $f['proposalIds'][$f['uuid']], $attestationId, $this->nowAt()));
        $this->assertEquals($unchanged, $snapshot());
        $audit = AuditEvent::query()->where('event', 'financial.completed_cash_refund_source_attested')->sole();
        $before = $audit->getRawOriginal();
        foreach ([fn () => DB::table('audit_events')->where('id', $audit->id)->update(['after' => '{}']),
            fn () => DB::table('audit_events')->where('id', $audit->id)->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('Source evidence was mutable.');
            } catch (\Illuminate\Database\QueryException $error) {
                $this->assertStringContainsString('Financial approval audit is immutable or invalid', $error->getMessage());
            }
        }
        $this->assertEquals($before, $audit->fresh()->getRawOriginal());
        $this->assertSame(0, DB::table('payment_refunds')->count());
        $this->assertSame(['count' => 2, 'claimed_minor' => 10], $this->gate(fn () => $service->unresolvedForAccount($f['id'])));
        $this->rejects('completed_cash_refund_incident_unresolved', fn () => $this->gate(fn () =>
            app(\App\Services\HeldCashReservation::class)->assertOrdinaryOutflow($f['id'], 1, '2026-11-25')));
        sodium_memzero($secret);
    }

    public function test_release_refund_document_independent_matching_bytes_preserve_cash_fence(): void
    {
        $f = $this->batchResolutionControl(-6, '1.00', ['posting_required', 'cash_returned']);
        $service = app(\App\Services\CompletedCashRefundRequest::class);
        $actor = $this->actor();
        $reviewer = $this->actor();
        $uuid = $f['second'];
        $proposal = $f['proposalIds'][$uuid];
        $bytes = 'Synthetic QA document of returned cash, exact original reference QA-BATCH-SECOND.';
        $reference = 'QA-DOC-RETURN-01';
        \Laravel\Sanctum\Sanctum::actingAs($f['requester']);
        $this->rejects('completed_refund_document_independence', fn () =>
            $service->recordResolutionDocument($f['requester'], $uuid, $proposal, $reference, $bytes, $this->nowAt()));
        \Laravel\Sanctum\Sanctum::actingAs($actor);
        $record = fn () => $service->recordResolutionDocument($actor, $uuid, $proposal, $reference, $bytes, $this->nowAt());
        $result = $record();
        $this->assertTrue($result['created']);
        $this->assertSame(hash('sha256', $bytes), $result['document']['sha256']);
        $this->assertSame('0.04', $result['document']['claimed_amount']);
        $this->assertSame('QA-BATCH-SECOND', $result['document']['refund_reference']);
        $this->assertFalse($result['original_bytes_stored']);
        $this->assertFalse($record()['created']);
        $id = $result['document_id'];
        $confirm = 'Independent reviewer compared the original document bytes';
        $this->rejects('completed_refund_document_independence', fn () =>
            $service->reviewResolutionDocument($actor, $uuid, $proposal, $id, $reference, $bytes, $confirm, $this->nowAt()));
        \Laravel\Sanctum\Sanctum::actingAs($reviewer);
        $this->rejects('completed_refund_document_conflict', fn () =>
            $service->reviewResolutionDocument($reviewer, $uuid, $proposal, $id, $reference, $bytes.' changed', $confirm, $this->nowAt()));
        $review = fn () => $service->reviewResolutionDocument($reviewer, $uuid, $proposal, $id, $reference, $bytes, $confirm, $this->nowAt());
        $result = $review();
        $this->assertTrue($result['created']);
        $this->assertTrue($result['independent_byte_review_recorded']);
        foreach (['external_authenticity_verified', 'financial_posting_created', 'closure_authorized', 'spending_authorized'] as $key) {
            $this->assertFalse($result[$key]);
        }
        $this->assertFalse($review()['created']);
        $this->assertSame(0, DB::table('payment_refunds')->count());
        $this->assertSame(['count' => 2, 'claimed_minor' => 10], $this->gate(fn () =>
            $service->unresolvedForAccount($f['id'])));
        $this->rejects('completed_cash_refund_incident_unresolved', fn () => $this->gate(fn () =>
            app(\App\Services\HeldCashReservation::class)->assertOrdinaryOutflow($f['id'], 1, '2026-11-25')));
        foreach (['financial.completed_cash_refund_document_recorded', 'financial.completed_cash_refund_document_reviewed'] as $event) {
            $row = AuditEvent::query()->where('event', $event)->sole();
            $before = $row->getRawOriginal();
            foreach ([fn () => DB::table('audit_events')->where('id', $row->id)->update(['after' => '{}']),
                fn () => DB::table('audit_events')->where('id', $row->id)->delete()] as $attempt) {
                try {
                    $attempt();
                    $this->fail('Document evidence was mutable.');
                } catch (\Illuminate\Database\QueryException $error) {
                    $this->assertStringContainsString('Financial approval audit is immutable or invalid', $error->getMessage());
                }
            }
            $this->assertEquals($before, $row->fresh()->getRawOriginal());
        }
    }

    public function test_release_refund_document_rejects_cross_claim_digest_and_reference_reuse(): void
    {
        $f = $this->batchResolutionControl(-6, '1.00', ['posting_required', 'no_disbursement']);
        $service = app(\App\Services\CompletedCashRefundRequest::class);
        $actor = $this->actor();
        \Laravel\Sanctum\Sanctum::actingAs($actor);
        $bytes = 'Synthetic QA document uniquely associated with the first incident.';
        $reference = 'QA-UNIQUE-DOC-01';
        $service->recordResolutionDocument($actor, $f['uuid'], $f['proposalIds'][$f['uuid']], $reference, $bytes, $this->nowAt());
        $this->rejects('completed_refund_document_conflict', fn () =>
            $service->recordResolutionDocument($actor, $f['second'], $f['proposalIds'][$f['second']], 'QA-OTHER-DOC', $bytes, $this->nowAt()));
        $this->rejects('completed_refund_document_conflict', fn () =>
            $service->recordResolutionDocument($actor, $f['second'], $f['proposalIds'][$f['second']], $reference, $bytes.' different', $this->nowAt()));
        $this->rejects('completed_refund_resolution_proposal_changed', fn () =>
            $service->recordResolutionDocument($actor, $f['second'], $f['proposalIds'][$f['uuid']], 'QA-WRONG-PROP', $bytes, $this->nowAt()));
        $this->assertSame(1, AuditEvent::query()->where('event', 'financial.completed_cash_refund_document_recorded')->count());
        $this->assertSame(0, DB::table('payment_refunds')->count());
    }

    public function test_release_refund_mixed_readiness_exact_partial_shortage_never_authorizes_posting(): void
    {
        $f = $this->batchResolutionControl(-6, '1.00', ['posting_required', 'cash_returned']);
        $service = app(\App\Services\CompletedCashRefundRequest::class);
        $tables = ['payment_refunds', 'transactions', 'treasury_postings', 'financial_posting_operations',
            'financial_posting_events', 'audit_events', 'treasury_cash_counts', 'treasury_cash_count_reviews'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->get()->toArray(), $tables);
        $before = $snapshot();
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $plan = $service->mixedResolutionReadiness($f['approver'], $f['id'], $f['proposalIds'], $this->nowAt());
        $this->assertSame(10, $plan['total_claimed_minor']);
        $this->assertSame(6, $plan['posting_required_minor']);
        $this->assertSame(4, $plan['nonposting_claimed_minor']);
        $this->assertSame(-6, $plan['expected_treasury_delta_minor']);
        $this->assertSame([-6, 0], array_column($plan['items'], 'expected_subscriber_delta_minor'));
        $this->assertSame([-6, 0], array_column($plan['items'], 'expected_treasury_delta_minor'));
        $this->assertTrue($plan['aggregate_arithmetic_verified']);
        $this->assertFalse($plan['individual_nonposting_evidence_verified']);
        $this->assertFalse($plan['evidence_ready_for_batch_review']);
        $this->assertFalse($plan['financial_posting_created']);
        $this->assertFalse($plan['closure_authorized']);
        $this->assertFalse($plan['spending_authorized']);
        $this->assertTrue($plan['incident_unresolved']);
        $this->rejects('completed_refund_resolution_evidence', fn () => $service->postBatchResolution(
            $f['approver'], $f['id'], $f['proposalIds'], 'Independent investigation of both cash claims', $this->nowAt()));
        $this->assertEquals($before, $snapshot());
        $this->assertSame(['count' => 2, 'claimed_minor' => 10], $this->gate(fn () =>
            $service->unresolvedForAccount($f['id'])));
        $this->rejects('completed_cash_refund_incident_unresolved', fn () => $this->gate(fn () =>
            app(\App\Services\HeldCashReservation::class)->assertOrdinaryOutflow($f['id'], 1, '2026-11-25')));
    }

    public function test_release_refund_mixed_readiness_rejects_total_claim_shortage(): void
    {
        $f = $this->batchResolutionControl(-10, '1.00', ['posting_required', 'no_disbursement']);
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $this->rejects('completed_refund_resolution_evidence', fn () =>
            app(\App\Services\CompletedCashRefundRequest::class)->mixedResolutionReadiness(
                $f['approver'], $f['id'], $f['proposalIds'], $this->nowAt()));
        $this->assertSame(0, DB::table('payment_refunds')->count());
    }

    public function test_release_refund_mixed_readiness_equal_count_does_not_prove_no_cash_disbursed(): void
    {
        $f = $this->batchResolutionControl(0, '1.00', ['no_disbursement', 'cash_returned']);
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $service = app(\App\Services\CompletedCashRefundRequest::class);
        $plan = $service->mixedResolutionReadiness($f['approver'], $f['id'], $f['proposalIds'], $this->nowAt());
        $this->assertSame(0, $plan['posting_required_minor']);
        $this->assertSame(10, $plan['nonposting_claimed_minor']);
        $this->assertSame([0, 0], array_column($plan['items'], 'expected_treasury_delta_minor'));
        $this->assertTrue($plan['aggregate_arithmetic_verified']);
        $this->assertFalse($plan['individual_nonposting_evidence_verified']);
        $this->assertFalse($plan['closure_authorized']);
        $this->assertTrue($plan['incident_unresolved']);
        $this->assertSame(0, DB::table('payment_refunds')->count());
        $this->assertSame(['count' => 2, 'claimed_minor' => 10], $this->gate(fn () =>
            $service->unresolvedForAccount($f['id'])));
    }

    public function test_release_refund_batch_controls_cumulative_receipt_limit_rolls_back_first_member(): void
    {
        // Explicit synthetic fixture for historical unsigned algorithm regression only.
        config(['financial_candidate.qa_legacy_unsigned_refund_fixture' => true]);
        $f = $this->batchResolutionControl(-10, '0.09');
        $service = app(\App\Services\CompletedCashRefundRequest::class);
        $payment = Payment::findOrFail(AuditEvent::query()->where('event', 'financial.completed_cash_refund_requested')
            ->where('entity_id', $f['uuid'])->sole()->after['payment_id']);
        $subscriber = Subscriber::findOrFail($payment->subscriber_id);
        $ledger = app(\App\Services\TransactionService::class);
        $this->assertSame(9, $ledger->accountBalanceMinor($subscriber));
        $this->assertSame('0.09', $payment->amount);
        $tables = ['payments', 'subscribers', 'invoices', 'payment_refunds', 'transactions', 'treasury_accounts',
            'treasury_postings', 'payment_allocations', 'payment_allocation_revisions', 'financial_posting_operations',
            'financial_posting_events', 'audit_events', 'treasury_cash_counts', 'treasury_cash_count_reviews'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->orderBy(
            $table === 'financial_posting_operations' ? 'operation_uuid' : 'id'
        )->get()->toArray(), $tables);
        $before = $snapshot();
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $plan = $service->batchPostingResolutionReadiness($f['approver'], $f['id'], $f['proposalIds'], $this->nowAt());
        $this->assertSame([6, 4], array_column($plan['items'], 'claimed_minor'));
        $this->assertFalse($plan['writer_eligibility_verified']);
        $firstRegistered = false;
        DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use ($f, &$firstRegistered): void {
            $sql = strtolower(ltrim($query->sql));
            if (str_starts_with($sql, 'insert') && str_contains($sql, 'financial_posting_operations')
                && in_array($f['uuid'], $query->bindings, true)) {
                $firstRegistered = true;
            }
        });
        try {
            $service->postBatchResolution($f['approver'], $f['id'], $f['proposalIds'],
                'Independent proof confirms both original cash refunds were disbursed', $this->nowAt());
            $this->fail('Cumulative refund exceeded the original receipt.');
        } catch (\Illuminate\Validation\ValidationException $error) {
            $this->assertSame(['يتجاوز الاسترداد الرصيد الدائن أو المبلغ المتبقي من السند.'], $error->errors()['amount']);
        }
        $this->assertTrue($firstRegistered, 'The first member must have been registered before second-member rejection.');
        $this->assertEquals($before, $snapshot());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(9, $ledger->accountBalanceMinor($subscriber->fresh()));
        $this->assertSame($f['balance'], $this->gate(fn () =>
            app(\App\Services\HeldCashRecordedLiquidityEvidence::class)->balance($f['id'], '2026-11-25')));
        $this->assertSame(['count' => 2, 'claimed_minor' => 10], $this->gate(fn () =>
            $service->unresolvedForAccount($f['id'])));
        $this->rejects('completed_cash_refund_incident_unresolved', fn () => $this->gate(fn () =>
            app(\App\Services\HeldCashReservation::class)->assertOrdinaryOutflow($f['id'], 1, '2026-11-25')));
    }

    public function test_release_refund_batch_controls_immutable_group_and_exact_replay(): void
    {
        // Explicit synthetic fixture for historical unsigned algorithm regression only.
        config(['financial_candidate.qa_legacy_unsigned_refund_fixture' => true]);
        $f = $this->batchResolutionControl();
        $service = app(\App\Services\CompletedCashRefundRequest::class);
        $confirmation = 'Independent proof confirms both original cash refunds were disbursed';
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $service->postBatchResolution($f['approver'], $f['id'], $f['proposalIds'], $confirmation, $this->nowAt());
        $group = AuditEvent::query()->where('event', 'financial.completed_cash_refund_batch_approved')->sole();
        $original = $group->getRawOriginal();
        $duplicate = (array) DB::table('audit_events')->where('id', $group->id)->sole();
        unset($duplicate['id']);
        $this->assertTrue(app(\App\Services\CompletedCashRefundBatchApprovalProtection::class)->active());
        foreach ([
            'audit_update' => fn () => DB::table('audit_events')->where('id', $group->id)->update(['after' => '{}']),
            'audit_delete' => fn () => DB::table('audit_events')->where('id', $group->id)->delete(),
            'audit_duplicate' => fn () => DB::table('audit_events')->insert($duplicate),
            'actor_delete' => fn () => DB::table('users')->where('id', $f['approver']->id)->delete(),
        ] as $kind => $attempt) {
            try {
                $attempt();
                $this->fail('Protected batch evidence was mutable: '.$kind);
            } catch (\Illuminate\Database\QueryException $error) {
                // MySQL can execute the preexisting user identity trigger
                // before the group-specific actor trigger. Both fail closed.
                if ($kind === 'actor_delete' && str_contains($error->getMessage(), 'User identity cannot be deleted')) {
                    $this->assertStringContainsString('User identity cannot be deleted', $error->getMessage());
                } else {
                    $this->assertStringContainsString('Financial approval audit is immutable or invalid', $error->getMessage());
                }
            }
        }
        $this->assertDatabaseHas('users', ['id' => $f['approver']->id]);
        $this->assertEquals($original, $group->fresh()->getRawOriginal());
        $other = $this->actor();
        \Laravel\Sanctum\Sanctum::actingAs($other);
        $this->rejects('completed_refund_batch_conflict', fn () =>
            $service->postBatchResolution($other, $f['id'], $f['proposalIds'], $confirmation, $this->nowAt()));
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $this->assertFalse($service->postBatchResolution($f['approver'], $f['id'],
            array_reverse($f['proposalIds'], true), $confirmation, $this->nowAt())['registry_created']);
        $this->assertSame(2, DB::table('payment_refunds')->count());
        $this->assertSame(1, AuditEvent::query()->where('event', 'financial.completed_cash_refund_batch_approved')->count());
    }

    public function test_release_refund_batch_atomic_posts_all_claims_once(): void
    {
        // Explicit synthetic fixture for historical unsigned algorithm regression only.
        config(['financial_candidate.qa_legacy_unsigned_refund_fixture' => true]);
        $f = $this->batchResolutionControl();
        $service = app(\App\Services\CompletedCashRefundRequest::class);
        $payment = Payment::findOrFail(AuditEvent::query()->where('event', 'financial.completed_cash_refund_requested')
            ->where('entity_id', $f['uuid'])->sole()->after['payment_id']);
        $subscriber = Subscriber::findOrFail($payment->subscriber_id);
        $ledger = app(\App\Services\TransactionService::class);
        $before = $ledger->accountBalanceMinor($subscriber);
        $confirmation = 'Independent proof confirms both original cash refunds were disbursed';
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $post = fn () => $service->postBatchResolution($f['approver'], $f['id'], $f['proposalIds'], $confirmation, $this->nowAt());
        $result = $post();
        $this->assertTrue($result['registry_created']);
        $this->assertSame(10, $result['batch']['total_claimed_minor']);
        $this->assertSame(0, $result['current_active_release_liquidity']['unresolved_refund_incident_count']);
        $this->assertSame($before - 10, $ledger->accountBalanceMinor($subscriber->fresh()));
        $this->assertSame($f['counted'], $this->gate(fn () => app(\App\Services\HeldCashRecordedLiquidityEvidence::class)->balance($f['id'], '2026-11-25')));
        $this->assertSame(2, DB::table('payment_refunds')->count());
        foreach ([$f['uuid'] => -6, $f['second'] => -4] as $uuid => $minor) {
            $legs = DB::table('financial_posting_events')->where('operation_uuid', $uuid)->orderBy('leg')->get();
            $this->assertSame(['subscriber', 'treasury'], $legs->pluck('leg')->all());
            $this->assertSame([$minor, $minor], $legs->map(fn ($leg) => (int) $leg->amount_minor)->all());
            $this->getJson($f['root'].$uuid)->assertOk()->assertJsonPath('incident_unresolved', false)
                ->assertJsonPath('completion_verified', true);
            $this->postJson($f['root'].$uuid.'/resolution-post', [
                'confirmation' => $confirmation, 'proposal_id' => $f['proposalIds'][$uuid],
            ])->assertConflict();
        }
        $this->assertSame(1, AuditEvent::query()->where('event', 'financial.completed_cash_refund_batch_approved')->count());
        $tables = ['payment_refunds', 'transactions', 'treasury_postings', 'financial_posting_operations', 'financial_posting_events', 'audit_events'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->get()->toArray(), $tables);
        $after = $snapshot();
        $this->assertFalse($post()['registry_created']);
        $this->rejects('completed_refund_batch_conflict', fn () =>
            $service->postBatchResolution($f['approver'], $f['id'], $f['proposalIds'], self::EVIDENCE, $this->nowAt()));
        $this->assertEquals($after, $snapshot());
    }

    public function test_release_refund_batch_atomic_rolls_back_when_final_group_audit_fails(): void
    {
        // Explicit synthetic fixture for historical unsigned algorithm regression only.
        config(['financial_candidate.qa_legacy_unsigned_refund_fixture' => true]);
        $f = $this->batchResolutionControl();
        $service = app(\App\Services\CompletedCashRefundRequest::class);
        $tables = ['payments', 'subscribers', 'invoices', 'payment_refunds', 'transactions', 'treasury_postings',
            'payment_allocations', 'payment_allocation_revisions', 'financial_posting_operations', 'financial_posting_events', 'audit_events'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->get()->toArray(), $tables);
        $before = $snapshot();
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $post = fn () => $service->postBatchResolution($f['approver'], $f['id'], $f['proposalIds'],
            'Independent proof confirms both original cash refunds were disbursed', $this->nowAt());
        $condition = "NEW.event = 'financial.completed_cash_refund_batch_approved'
            AND (SELECT COUNT(*) FROM financial_posting_events WHERE operation_uuid IN ('{$f['uuid']}', '{$f['second']}')) = 4";
        $body = DB::getDriverName() === 'sqlite'
            ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'qa_batch_final_audit'); END"
            : "FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'qa_batch_final_audit'; END IF; END";
        DB::unprepared("CREATE TRIGGER qa_batch_final_audit BEFORE INSERT ON audit_events {$body}");
        \Illuminate\Support\Facades\Log::spy();
        try {
            try {
                $post();
                $this->fail('Batch committed despite final audit failure.');
            } catch (\RuntimeException $error) {
                $this->assertSame('Financial operation requires a durable audit record.', $error->getMessage());
            }
            \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->with(
                'audit.record_failed', \Mockery::on(static fn ($context) =>
                    ($context['event'] ?? null) === 'financial.completed_cash_refund_batch_approved'
                    && str_contains((string) ($context['error'] ?? ''), 'qa_batch_final_audit')))->once();
            $this->assertEquals($before, $snapshot());
            $this->assertSame(['count' => 2, 'claimed_minor' => 10], $this->gate(fn () =>
                $service->unresolvedForAccount($f['id'])));
            $this->rejects('completed_cash_refund_incident_unresolved', fn () => $this->gate(fn () =>
                app(\App\Services\HeldCashReservation::class)->assertOrdinaryOutflow($f['id'], 1, '2026-11-25')));
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS qa_batch_final_audit');
        }
        $this->assertTrue($post()['registry_created']);
        $this->assertSame(2, DB::table('payment_refunds')->count());
        $this->assertFalse($post()['registry_created']);
    }

    public function test_release_refund_batch_readiness_exact_total_without_posting(): void
    {
        $f = $this->batchResolutionControl();
        $service = app(\App\Services\CompletedCashRefundRequest::class);
        $tables = ['payment_refunds', 'transactions', 'treasury_postings', 'financial_posting_operations',
            'financial_posting_events', 'audit_events', 'treasury_cash_counts', 'treasury_cash_count_reviews'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->get()->toArray(), $tables);
        $before = $snapshot();
        \Laravel\Sanctum\Sanctum::actingAs($f['requester']);
        $this->rejects('completed_refund_resolution_independence', fn () =>
            $service->batchPostingResolutionReadiness($f['requester'], $f['id'], $f['proposalIds'], $this->nowAt()));
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $proof = $service->batchPostingResolutionReadiness($f['approver'], $f['id'], $f['proposalIds'], $this->nowAt());
        $this->assertSame(2, $proof['incident_count']);
        $this->assertSame(10, $proof['total_claimed_minor']);
        $this->assertSame(-10, $proof['expected_treasury_delta_minor']);
        $this->assertSame($f['balance'], $proof['recorded_balance_minor']);
        $this->assertSame($f['counted'], $proof['observed_balance_minor']);
        $this->assertSame([6, 4], array_column($proof['items'], 'claimed_minor'));
        $this->assertSame([-6, -4], array_column($proof['items'], 'expected_subscriber_delta_minor'));
        $this->assertSame([-6, -4], array_column($proof['items'], 'expected_treasury_delta_minor'));
        $this->assertTrue($proof['evidence_ready_for_batch_review']);
        foreach (['writer_eligibility_verified', 'financial_posting_created', 'spending_authorized', 'closure_authorized'] as $key) {
            $this->assertFalse($proof[$key]);
        }
        $this->assertTrue($proof['incident_unresolved']);
        $this->assertEquals($proof, $service->batchPostingResolutionReadiness($f['approver'], $f['id'],
            array_reverse($f['proposalIds'], true), $this->nowAt()));
        foreach (array_keys($f['proposalIds']) as $claim) {
            $this->postJson($f['root'].$claim.'/resolution-post', [
                'confirmation' => 'Independent proof confirms the original cash refund was disbursed',
                'proposal_id' => $f['proposalIds'][$claim],
            ])->assertConflict();
        }
        $this->assertEquals($before, $snapshot());
        $this->assertSame(['count' => 2, 'claimed_minor' => 10], $this->gate(fn () =>
            $service->unresolvedForAccount($f['id'])));
        $this->rejects('completed_cash_refund_incident_unresolved', fn () => $this->gate(fn () =>
            app(\App\Services\HeldCashReservation::class)->assertOrdinaryOutflow($f['id'], 1, '2026-11-25')));
    }

    public function test_release_refund_batch_readiness_rejects_wrong_aggregate_shortage(): void
    {
        $f = $this->batchResolutionControl(-9);
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $this->rejects('completed_refund_resolution_evidence', fn () =>
            app(\App\Services\CompletedCashRefundRequest::class)->batchPostingResolutionReadiness(
                $f['approver'], $f['id'], $f['proposalIds'], $this->nowAt()));
        $this->assertSame(0, DB::table('payment_refunds')->count());
        $this->assertSame(0, AuditEvent::query()->where('event', 'financial.completed_cash_refund_resolution_approved')->count());
    }

    public function test_release_refund_batch_readiness_rejects_duplicate_proposal_and_omitted_claim(): void
    {
        $f = $this->batchResolutionControl();
        $service = app(\App\Services\CompletedCashRefundRequest::class);
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $duplicate = array_fill_keys(array_keys($f['proposalIds']), $f['proposalIds'][$f['uuid']]);
        $this->rejects('completed_refund_batch_selection', fn () =>
            $service->batchPostingResolutionReadiness($f['approver'], $f['id'], $duplicate, $this->nowAt()));
        \Laravel\Sanctum\Sanctum::actingAs($f['requester']);
        $this->postJson($f['submit'], array_replace($f['data'], [
            'operation_uuid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'amount' => '0.01', 'reference' => 'QA-BATCH-OMITTED',
        ]))->assertOk();
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $this->rejects('completed_refund_batch_incomplete', fn () =>
            $service->batchPostingResolutionReadiness($f['approver'], $f['id'], $f['proposalIds'], $this->nowAt()));
        $this->assertSame(['count' => 3, 'claimed_minor' => 11], $this->gate(fn () =>
            $service->unresolvedForAccount($f['id'])));
        $this->assertSame(0, DB::table('payment_refunds')->count());
    }

    private function resolutionControl(string $classification = 'no_disbursement', int $variance = 0): array
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        $issuer = $this->actor();
        $requester = $this->actor();
        $reviewer = $this->actor();
        \App\Models\Tariff::create(['name' => 'Synthetic lifecycle tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-REFUND-LIFECYCLE']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        app(\App\Services\GatedInvoiceTransferWriter::class)->issue($issuer, (int) $subscriber->id, (int) $meter->id,
            '66666666-6666-4666-8666-666666666666', $this->nowAt());
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 20);
        $id = (int) $account['id'];
        $credit = DB::table('treasury_held_cash_credits')->sole();
        $release = app(\App\Services\HeldCashReleaseProposalService::class);
        $proposal = '77777777-7777-4777-8777-777777777777';
        $release->propose($issuer, $id, (int) $credit->id, 10, $proposal, 'QA-LIFECYCLE-RELEASE', self::EVIDENCE, $this->nowAt());
        config(['financial_candidate.active_held_release' => true, 'financial_candidate.completed_cash_refund' => true]);
        $release->recordActiveRelease($reviewer, $id, $proposal, '88888888-8888-4888-8888-888888888888', self::EVIDENCE, $this->nowAt());
        $other = Subscriber::create(['name' => 'Synthetic lifecycle refund recipient', 'square_id' => $subscriber->square_id, 'status' => 'active']);
        $receipt = app(GatedCashReceiptWriter::class)->collect($issuer, (int) $other->id, '1.00',
            '99999999-9999-4999-8999-999999999999', $this->nowAt())['payment'];
        $submit = '/api/payments/'.$receipt->id.'/completed-cash-refund-requests';
        $root = '/api/completed-cash-refund-requests/';
        $data = ['account_id' => $id, 'amount' => '0.06', 'reference' => 'QA-LIFECYCLE-1', 'date' => '2026-11-25',
            'reason' => 'Independent evidence of completed cash disbursement', 'evidence' => self::EVIDENCE,
            'operation_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'disbursement_confirmed' => true];
        $uuid = $data['operation_uuid'];
        $endpoint = $root.$uuid.'/resolution-proposal';
        $counter = $this->actor();
        $countReviewer = $this->actor();
        $proposer = $this->actor();
        \Laravel\Sanctum\Sanctum::actingAs($requester);
        $this->postJson($submit, $data)->assertOk();
        \Laravel\Sanctum\Sanctum::actingAs($reviewer);
        $this->postJson($root.$uuid.'/close', ['decision' => 'rejected', 'reason' => self::EVIDENCE])->assertOk();
        $balance = $this->gate(fn () => app(\App\Services\HeldCashRecordedLiquidityEvidence::class)->balance($id, '2026-11-25'));
        $counted = $balance + $variance;
        $amount = sprintf('%d.%02d', intdiv($counted, 100), $counted % 100);
        $counts = app(TreasuryCashCountService::class);
        $count = $counts->record($counter, $id, $amount, 'QA-RESOLUTION-CONTROL', self::EVIDENCE)['count'];
        $counts->review($countReviewer, (int) $count['id'], true, self::EVIDENCE);
        \Laravel\Sanctum\Sanctum::actingAs($proposer);
        $this->postJson($endpoint, ['count_id' => (int) $count['id'], 'classification' => $classification,
            'evidence' => self::EVIDENCE])->assertOk()->assertJsonPath('created', true);
        $approver = $this->actor();
        return compact('id', 'uuid', 'root', 'submit', 'data', 'requester', 'approver',
            'counter', 'countReviewer', 'amount', 'count');
    }

    private function assertResolutionControlBlocked(array $fixture, string $reason, int $unresolved = 1): void
    {
        $tables = ['payment_refunds', 'transactions', 'treasury_postings', 'financial_posting_operations',
            'financial_posting_events', 'audit_events', 'treasury_cash_counts', 'treasury_cash_count_reviews'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->orderBy(
            $table === 'financial_posting_operations' ? 'operation_uuid' : 'id'
        )->get()->toArray(), $tables);
        $before = $snapshot();
        \Laravel\Sanctum\Sanctum::actingAs($fixture['approver']);
        $this->rejects($reason, fn () => app(\App\Services\CompletedCashRefundRequest::class)->approveResolution(
            $fixture['approver'], $fixture['uuid'], 'Independent confirmation of investigated cash claim', $this->nowAt()));
        $this->assertEquals($before, $snapshot());
        $this->assertSame(0, AuditEvent::query()->where('event', 'financial.completed_cash_refund_resolution_approved')->count());
        $this->getJson($fixture['root'].$fixture['uuid'])->assertOk()->assertJsonPath('incident_unresolved', true);
        $this->rejects('completed_cash_refund_incident_unresolved', fn () => $this->gate(fn () =>
            app(\App\Services\HeldCashReservation::class)->assertOrdinaryOutflow($fixture['id'], 1, '2026-11-25')));
        $proof = $this->gate(fn () => app(\App\Services\ActiveHeldCashRelease::class)->currentLiquidity($fixture['id']));
        $this->assertSame($unresolved, $proof['unresolved_refund_incident_count']);
        $this->assertTrue($proof['incident_outflow_blocked']);
        $this->assertSame(0, $proof['available_for_ordinary_outflow_minor']);
    }

    /** MySQL only: independent sessions, observed contention on the same scope lock. */
    private function refundResolutionRace(array $f, bool $differentReviewer, bool $batch = false, ?array $attestationIds = null, ?int $renewCountId = null): array
    {
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertSame('water_test', DB::getDatabaseName());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertTrue(function_exists('pcntl_fork') && function_exists('posix_kill'));
        if ($attestationIds === null && $renewCountId === null) {
            config(['financial_candidate.qa_legacy_unsigned_refund_fixture' => true]);
        }
        $actors = [(int) $f['approver']->id, (int) ($differentReviewer ? $this->actor()->id : $f['approver']->id)];
        $dir = sys_get_temp_dir().'/water-refund-race-'.\Illuminate\Support\Str::uuid();
        mkdir($dir, 0700);
        $wait = static function (string $path): void {
            $deadline = microtime(true) + 20;
            while (! is_file($path)) {
                if (microtime(true) > $deadline) throw new \RuntimeException('Refund race barrier timed out: '.$path);
                usleep(1000);
            }
        };
        DB::disconnect();
        $pids = [];
        try {
            foreach ($actors as $i => $actorId) {
                $pid = pcntl_fork();
                if ($pid === -1) throw new \RuntimeException('Refund race fork failed');
                if ($pid === 0) {
                    DB::purge();
                    try {
                        $connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
                        DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
                        \Laravel\Sanctum\Sanctum::actingAs(User::findOrFail($actorId));
                        file_put_contents("$dir/ready-$i", (string) $connection);
                        $wait("$dir/start");
                        touch("$dir/enter-$i");
                        $service = app(\App\Services\CompletedCashRefundRequest::class);
                        if ($renewCountId !== null) {
                            $result = $service->renewResolutionGroup(User::findOrFail($actorId), $f['id'], $f['proposalIds'],
                                $renewCountId, self::EVIDENCE, $this->nowAt());
                        } elseif ($attestationIds !== null) {
                            $result = $service->postMixedResolution(User::findOrFail($actorId), $f['id'], $f['proposalIds'],
                                $attestationIds, 'Independent verified evidence supports the complete mixed cash settlement', $this->nowAt());
                        } elseif ($batch) {
                            $result = $service->postBatchResolution(User::findOrFail($actorId), $f['id'], $f['proposalIds'],
                                'Independent proof confirms both original cash refunds were disbursed', $this->nowAt());
                        } else {
                            $result = $service->postResolution(User::findOrFail($actorId), $f['uuid'],
                                'Independent proof confirms the original cash refund was disbursed', $this->nowAt());
                        }
                        $outcome = ['ok' => true, 'created' => $renewCountId !== null ? $result['created'] : $result['registry_created'], 'actor' => $actorId];
                        if ($renewCountId !== null) $outcome += ['proposal_ids' => $result['proposal_ids'], 'group_hash' => $result['group_revision_hash']];
                    } catch (\Throwable $error) {
                        $outcome = ['ok' => false, 'error' => get_class($error), 'message' => $error->getMessage(), 'actor' => $actorId];
                    }
                    file_put_contents("$dir/result-$i", json_encode($outcome, JSON_THROW_ON_ERROR));
                    DB::disconnect();
                    exit(0);
                }
                $pids[] = $pid;
            }
            $wait("$dir/ready-0");
            $wait("$dir/ready-1");
            $connections = [(int) file_get_contents("$dir/ready-0"), (int) file_get_contents("$dir/ready-1")];
            DB::purge();
            $parentId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $this->assertCount(3, array_unique([...$connections, $parentId]));
            // Hold the exact scope row until BOTH independent workers reach
            // the actual blocked locking SELECT. No sleep-only race proof.
            DB::beginTransaction();
            try {
                DB::table('financial_scopes')->where('scope_key', 'water')->lockForUpdate()->sole();
                touch("$dir/start");
                $wait("$dir/enter-0");
                $wait("$dir/enter-1");
                $deadline = microtime(true) + 10;
                do {
                    $blocked = collect(DB::select('SHOW FULL PROCESSLIST'))->filter(
                        static fn ($row) => in_array((int) $row->Id, $connections, true)
                            && str_contains(strtolower((string) $row->Info), 'financial_scopes')
                            && str_contains(strtolower((string) $row->Info), 'for update')
                    )->count();
                    if ($blocked === 2) break;
                    usleep(10000);
                } while (microtime(true) < $deadline);
                $this->assertSame(2, $blocked, 'Both workers must contend on the real financial scope row');
                $this->assertFileDoesNotExist("$dir/result-0");
                $this->assertFileDoesNotExist("$dir/result-1");
            } finally {
                DB::rollBack();
            }
            $deadline = microtime(true) + 45;
            while ($pids !== []) {
                foreach ($pids as $key => $pid) {
                    $joined = pcntl_waitpid($pid, $status, WNOHANG);
                    if ($joined === $pid) {
                        unset($pids[$key]);
                        $this->assertTrue(pcntl_wifexited($status));
                        $this->assertSame(0, pcntl_wexitstatus($status));
                    } elseif ($joined === -1) {
                        unset($pids[$key]);
                        throw new \RuntimeException('Refund race worker join failed');
                    }
                }
                if ($pids !== [] && microtime(true) > $deadline) throw new \RuntimeException('Refund race worker timeout');
                usleep(1000);
            }
            DB::purge();
            return [
                json_decode(file_get_contents("$dir/result-0"), true, 512, JSON_THROW_ON_ERROR),
                json_decode(file_get_contents("$dir/result-1"), true, 512, JSON_THROW_ON_ERROR),
            ];
        } finally {
            foreach ($pids as $pid) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
            foreach (glob("$dir/*") as $file) unlink($file);
            rmdir($dir);
        }
    }

    private function assertRefundResolutionRace(bool $differentReviewer): void
    {
        $f = $this->resolutionControl('posting_required', -6);
        $request = AuditEvent::query()->where('event', 'financial.completed_cash_refund_requested')->sole();
        $payment = Payment::findOrFail($request->after['payment_id']);
        $subscriber = Subscriber::findOrFail($payment->subscriber_id);
        $ledger = app(\App\Services\TransactionService::class);
        $beforeBalance = $ledger->accountBalanceMinor($subscriber);
        $cash = fn () => $this->gate(fn () => app(\App\Services\HeldCashRecordedLiquidityEvidence::class)
            ->balance($f['id'], '2026-11-25'));
        $beforeCash = $cash();
        $results = $this->refundResolutionRace($f, $differentReviewer);
        $success = array_values(array_filter($results, fn ($r) => $r['ok']));
        $this->assertCount($differentReviewer ? 1 : 2, $success, json_encode($results));
        $this->assertCount(1, array_filter($success, fn ($r) => $r['created']), json_encode($results));
        if ($differentReviewer) {
            $rejected = collect($results)->firstWhere('ok', false);
            $this->assertSame(DomainException::class, $rejected['error'], json_encode($results));
            $this->assertSame('completed_refund_resolution_conflict', $rejected['message']);
        }
        $winner = collect($success)->firstWhere('created', true);
        $this->assertSame($beforeBalance - 6, $ledger->accountBalanceMinor($subscriber->fresh()));
        $this->assertSame($beforeCash - 6, $cash());
        $this->assertSame(1, DB::table('payment_refunds')->where('disbursement_reference', $f['data']['reference'])->count());
        $this->assertSame(1, DB::table('financial_posting_operations')->where('operation_uuid', $f['uuid'])->count());
        $legs = DB::table('financial_posting_events')->where('operation_uuid', $f['uuid'])->orderBy('leg')->get();
        $this->assertSame(['subscriber', 'treasury'], $legs->pluck('leg')->all());
        $this->assertSame([-6, -6], $legs->map(fn ($leg) => (int) $leg->amount_minor)->all());
        $approval = AuditEvent::query()->where('event', 'financial.completed_cash_refund_resolution_approved')->sole();
        $this->assertSame($winner['actor'], (int) $approval->actor_user_id);
        $this->assertSame(1, AuditEvent::query()->where('event', 'financial.completed_cash_refund')->count());
        $liquidity = $this->gate(fn () => app(\App\Services\ActiveHeldCashRelease::class)->currentLiquidity($f['id']));
        $this->assertSame(0, $liquidity['unresolved_refund_incident_count']);
        \Laravel\Sanctum\Sanctum::actingAs(User::findOrFail($winner['actor']));
        $this->getJson($f['root'].$f['uuid'])->assertOk()->assertJsonPath('status', 'rejected')
            ->assertJsonPath('completion_verified', true)->assertJsonPath('incident_unresolved', false);
    }

    public function test_release_refund_revision_new_count_requires_exact_revision_approval(): void
    {
        // Explicit synthetic fixture for historical unsigned algorithm regression only.
        config(['financial_candidate.qa_legacy_unsigned_refund_fixture' => true]);
        $f = $this->resolutionControl();
        $old = AuditEvent::query()->where('event', 'financial.completed_cash_refund_resolution_proposed')->sole();
        $endpoint = $f['root'].$f['uuid'].'/resolution-proposal';
        $approval = $f['root'].$f['uuid'].'/resolution-approve';
        $counts = app(TreasuryCashCountService::class);
        $new = $counts->record($f['counter'], $f['id'], $f['amount'], 'QA-REVISION-COUNT', self::EVIDENCE)['count'];
        $counts->review($f['countReviewer'], (int) $new['id'], true, self::EVIDENCE);
        $proposer = $this->actor();
        \Laravel\Sanctum\Sanctum::actingAs($proposer);
        $input = ['count_id' => (int) $new['id'], 'classification' => 'no_disbursement',
            'evidence' => self::EVIDENCE, 'supersedes_id' => (int) $old->id];
        $this->postJson($endpoint, array_replace($input, ['count_id' => (int) $f['count']['id']]))->assertConflict();
        $this->postJson($endpoint, $input)->assertOk()->assertJsonPath('created', true);
        $revision = AuditEvent::query()->where('event', 'financial.completed_cash_refund_resolution_revised')->sole();
        $this->postJson($endpoint, $input)->assertOk()->assertJsonPath('created', false)->assertJsonPath('proposal_id', $revision->id);
        $this->postJson($endpoint, array_replace($input, ['evidence' => self::EVIDENCE.' changed']))->assertConflict();
        $this->assertEquals($old->getRawOriginal(), $old->fresh()->getRawOriginal());
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $this->getJson($f['root'].$f['uuid'])->assertOk()->assertJsonPath('current_proposal_id', $revision->id)
            ->assertJsonPath('incident_unresolved', true);
        $data = ['confirmation' => 'Independent investigation confirms no cash left the treasury'];
        $this->postJson($approval, $data)->assertConflict();
        $this->postJson($approval, $data + ['proposal_id' => $old->id])->assertConflict();
        $this->postJson($approval, $data + ['proposal_id' => $revision->id])->assertOk()->assertJsonPath('incident_unresolved', false);
        $this->assertSame(0, DB::table('payment_refunds')->count());
        $this->assertSame(1, AuditEvent::query()->where('event', 'financial.completed_cash_refund_resolution_approved')->count());
        \Laravel\Sanctum\Sanctum::actingAs($proposer);
        $this->postJson($endpoint, $input)->assertConflict();
        foreach ([fn () => DB::table('audit_events')->where('id', $revision->id)->update(['after' => '{}']),
            fn () => DB::table('audit_events')->where('id', $revision->id)->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('Revision evidence was mutable.');
            } catch (\Illuminate\Database\QueryException $error) {
                $this->assertStringContainsString('Financial approval audit is immutable or invalid', $error->getMessage());
            }
        }
    }

    public function test_release_refund_revision_posting_requires_current_proposal(): void
    {
        // Explicit synthetic fixture for historical unsigned algorithm regression only.
        config(['financial_candidate.qa_legacy_unsigned_refund_fixture' => true]);
        $f = $this->resolutionControl('posting_required', -6);
        $old = AuditEvent::query()->where('event', 'financial.completed_cash_refund_resolution_proposed')->sole();
        $counts = app(TreasuryCashCountService::class);
        $new = $counts->record($f['counter'], $f['id'], $f['amount'], 'QA-REVISION-POST-COUNT', self::EVIDENCE)['count'];
        $counts->review($f['countReviewer'], (int) $new['id'], true, self::EVIDENCE);
        \Laravel\Sanctum\Sanctum::actingAs($this->actor());
        $this->postJson($f['root'].$f['uuid'].'/resolution-proposal', ['count_id' => (int) $new['id'],
            'classification' => 'posting_required', 'evidence' => self::EVIDENCE, 'supersedes_id' => $old->id])->assertOk();
        $revision = AuditEvent::query()->where('event', 'financial.completed_cash_refund_resolution_revised')->sole();
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $endpoint = $f['root'].$f['uuid'].'/resolution-post';
        $data = ['confirmation' => 'Independent proof confirms the original cash refund was disbursed'];
        $this->postJson($endpoint, $data)->assertConflict();
        $this->postJson($endpoint, $data + ['proposal_id' => $old->id])->assertConflict();
        $this->assertSame(0, DB::table('payment_refunds')->count());
        $this->postJson($endpoint, $data + ['proposal_id' => $revision->id])->assertOk()->assertJsonPath('registry_created', true);
        $this->postJson($endpoint, $data + ['proposal_id' => $revision->id])->assertOk()->assertJsonPath('registry_created', false);
        $this->assertSame(1, DB::table('payment_refunds')->count());
        $this->assertSame(2, DB::table('financial_posting_events')->where('operation_uuid', $f['uuid'])->count());
    }

    private function assertRefundBatchRace(bool $differentReviewer): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent shared-database connections require the MySQL QA runner.');
        }
        $f = $this->batchResolutionControl();
        $request = AuditEvent::query()->where('event', 'financial.completed_cash_refund_requested')
            ->where('entity_id', $f['uuid'])->sole();
        $payment = Payment::findOrFail($request->after['payment_id']);
        $subscriber = Subscriber::findOrFail($payment->subscriber_id);
        $ledger = app(\App\Services\TransactionService::class);
        $beforeBalance = $ledger->accountBalanceMinor($subscriber);
        $cash = fn () => $this->gate(fn () => app(\App\Services\HeldCashRecordedLiquidityEvidence::class)
            ->balance($f['id'], '2026-11-25'));
        $beforeCash = $cash();
        $results = $this->refundResolutionRace($f, $differentReviewer, true);
        $success = array_values(array_filter($results, fn ($r) => $r['ok']));
        $this->assertCount($differentReviewer ? 1 : 2, $success, json_encode($results));
        $this->assertCount(1, array_filter($success, fn ($r) => $r['created']), json_encode($results));
        if ($differentReviewer) {
            $rejected = collect($results)->firstWhere('ok', false);
            $this->assertSame(DomainException::class, $rejected['error'], json_encode($results));
            $this->assertSame('completed_refund_batch_conflict', $rejected['message']);
        }
        $winner = collect($success)->firstWhere('created', true);
        $this->assertSame($beforeBalance - 10, $ledger->accountBalanceMinor($subscriber->fresh()));
        $this->assertSame($beforeCash - 10, $cash());
        $this->assertSame($f['counted'], $cash());
        $this->assertSame(2, DB::table('payment_refunds')->count());
        $this->assertSame(2, DB::table('financial_posting_operations')
            ->whereIn('operation_uuid', array_keys($f['proposalIds']))->count());
        $this->assertSame(4, DB::table('financial_posting_events')
            ->whereIn('operation_uuid', array_keys($f['proposalIds']))->count());
        \Laravel\Sanctum\Sanctum::actingAs(User::findOrFail($winner['actor']));
        foreach ([$f['uuid'] => -6, $f['second'] => -4] as $uuid => $minor) {
            $legs = DB::table('financial_posting_events')->where('operation_uuid', $uuid)->orderBy('leg')->get();
            $this->assertSame(['subscriber', 'treasury'], $legs->pluck('leg')->all());
            $this->assertSame([$minor, $minor], $legs->map(fn ($leg) => (int) $leg->amount_minor)->all());
            $this->getJson($f['root'].$uuid)->assertOk()->assertJsonPath('status', 'rejected')
                ->assertJsonPath('completion_verified', true)->assertJsonPath('incident_unresolved', false);
        }
        $approval = AuditEvent::query()->where('event', 'financial.completed_cash_refund_batch_approved')->sole();
        $this->assertSame($winner['actor'], (int) $approval->actor_user_id);
        $this->assertSame(10, $approval->after['total_claimed_minor']);
        $this->assertSame(2, AuditEvent::query()->where('event', 'financial.completed_cash_refund')->count());
        $this->assertSame(0, AuditEvent::query()->where('event', 'financial.completed_cash_refund_resolution_approved')->count());
        $liquidity = $this->gate(fn () => app(\App\Services\ActiveHeldCashRelease::class)->currentLiquidity($f['id']));
        $this->assertSame(0, $liquidity['unresolved_refund_incident_count']);
    }

    public function test_release_refund_batch_concurrency_identical_retries_commit_once(): void
    {
        $this->assertRefundBatchRace(false);
    }

    public function test_release_refund_batch_concurrency_competing_reviewers_commit_once(): void
    {
        $this->assertRefundBatchRace(true);
    }

    public function test_release_refund_concurrency_identical_retries_commit_once(): void
    {
        $this->assertRefundResolutionRace(false);
    }

    public function test_release_refund_concurrency_competing_reviewers_commit_once(): void
    {
        $this->assertRefundResolutionRace(true);
    }

    public function test_release_refund_atomic_resolution_rollback_after_both_legs_before_approval(): void
    {
        // Explicit synthetic fixture for historical unsigned algorithm regression only.
        config(['financial_candidate.qa_legacy_unsigned_refund_fixture' => true]);
        $f = $this->resolutionControl('posting_required', -6);
        $uuid = $f['uuid'];
        $request = AuditEvent::query()->where('event', 'financial.completed_cash_refund_requested')->sole();
        $payment = Payment::findOrFail($request->after['payment_id']);
        $subscriber = Subscriber::findOrFail($payment->subscriber_id);
        $ledger = app(\App\Services\TransactionService::class);
        $beforeBalance = $ledger->accountBalanceMinor($subscriber);
        $cash = fn () => $this->gate(fn () => app(\App\Services\HeldCashRecordedLiquidityEvidence::class)
            ->balance($f['id'], '2026-11-25'));
        $beforeCash = $cash();
        $tables = ['payments', 'subscribers', 'invoices', 'payment_refunds', 'transactions',
            'treasury_accounts', 'treasury_postings', 'payment_allocations', 'payment_allocation_revisions',
            'financial_posting_operations', 'financial_posting_events', 'audit_events',
            'treasury_cash_counts', 'treasury_cash_count_reviews', 'treasury_cash_adjustments'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->orderBy(
            $table === 'financial_posting_operations' ? 'operation_uuid' : 'id'
        )->get()->toArray(), $tables);
        $before = $snapshot();
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $record = fn () => app(\App\Services\CompletedCashRefundRequest::class)->postResolution(
            $f['approver'], $uuid, 'Independent proof confirms the original cash refund was disbursed', $this->nowAt());
        // Fail only after the exact operation has both registered legs AND its
        // completed-refund audit. This proves the late failure point is reached.
        $condition = "NEW.event = 'financial.completed_cash_refund_resolution_approved' AND NEW.entity_id = '{$uuid}'
            AND EXISTS (SELECT 1 FROM financial_posting_events WHERE operation_uuid = '{$uuid}' AND leg = 'subscriber' AND amount_minor = -6)
            AND EXISTS (SELECT 1 FROM financial_posting_events WHERE operation_uuid = '{$uuid}' AND leg = 'treasury' AND amount_minor = -6)
            AND EXISTS (SELECT 1 FROM audit_events WHERE event = 'financial.completed_cash_refund')";
        $body = DB::getDriverName() === 'sqlite'
            ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'qa_refund_resolution_final_audit'); END"
            : "FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'qa_refund_resolution_final_audit'; END IF; END";
        DB::unprepared("CREATE TRIGGER qa_refund_resolution_final_audit BEFORE INSERT ON audit_events {$body}");
        \Illuminate\Support\Facades\Log::spy();
        try {
            try {
                $record();
                $this->fail('Refund incident committed despite final approval audit failure.');
            } catch (\RuntimeException $error) {
                $this->assertSame('Financial operation requires a durable audit record.', $error->getMessage());
            }
            \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->with(
                'audit.record_failed', \Mockery::on(static fn ($context) =>
                    is_array($context) && ($context['event'] ?? null) === 'financial.completed_cash_refund_resolution_approved'
                    && str_contains((string) ($context['error'] ?? ''), 'qa_refund_resolution_final_audit'))
            )->once();
            $this->assertEquals($before, $snapshot());
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame($beforeBalance, $ledger->accountBalanceMinor($subscriber->fresh()));
            $this->assertSame($beforeCash, $cash());
            $this->getJson($f['root'].$uuid)->assertOk()->assertJsonPath('incident_unresolved', true)
                ->assertJsonPath('operation_exists', false);
            $this->rejects('completed_cash_refund_incident_unresolved', fn () => $this->gate(fn () =>
                app(\App\Services\HeldCashReservation::class)->assertOrdinaryOutflow($f['id'], 1, '2026-11-25')));
            $liquidity = $this->gate(fn () => app(\App\Services\ActiveHeldCashRelease::class)->currentLiquidity($f['id']));
            $this->assertSame(1, $liquidity['unresolved_refund_incident_count']);
            $this->assertSame(0, $liquidity['available_for_ordinary_outflow_minor']);
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS qa_refund_resolution_final_audit');
        }
        $result = $record();
        $this->assertTrue($result['registry_created']);
        $this->assertSame($beforeBalance - 6, $ledger->accountBalanceMinor($subscriber->fresh()));
        $this->assertSame($beforeCash - 6, $cash());
        $this->assertSame(2, DB::table('financial_posting_events')->where('operation_uuid', $uuid)->count());
        $this->assertSame(1, DB::table('payment_refunds')->where('disbursement_reference', $f['data']['reference'])->count());
        $this->assertSame(1, AuditEvent::query()->where('event', 'financial.completed_cash_refund_resolution_approved')->count());
        $this->assertSame(0, $result['current_active_release_liquidity']['unresolved_refund_incident_count']);
        $after = $snapshot();
        $this->assertFalse($record()['registry_created']);
        $this->assertEquals($after, $snapshot());
    }

    public function test_release_refund_atomic_resolution_posts_exact_two_legs_once(): void
    {
        // Explicit synthetic fixture for historical unsigned algorithm regression only.
        config(['financial_candidate.qa_legacy_unsigned_refund_fixture' => true]);
        $f = $this->resolutionControl('posting_required', -6);
        $endpoint = $f['root'].$f['uuid'].'/resolution-post';
        $input = ['confirmation' => 'Independent proof confirms the original cash refund was disbursed'];
        $payment = Payment::query()->findOrFail(AuditEvent::query()->where('event', 'financial.completed_cash_refund_requested')->sole()->after['payment_id']);
        $subscriber = Subscriber::findOrFail($payment->subscriber_id);
        $ledger = app(\App\Services\TransactionService::class);
        $before = $ledger->accountBalanceMinor($subscriber);
        $cash = $this->gate(fn () => app(\App\Services\HeldCashRecordedLiquidityEvidence::class)->balance($f['id'], '2026-11-25'));
        \Laravel\Sanctum\Sanctum::actingAs($f['requester']);
        $this->postJson($endpoint, $input)->assertConflict();
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $this->postJson($endpoint, $input + ['amount' => '0.05'])->assertUnprocessable();
        $this->postJson($f['root'].$f['uuid'].'/approve', $input)->assertConflict();
        $this->postJson($endpoint, $input)->assertOk()->assertJsonPath('registry_created', true)
            ->assertJsonPath('current_active_release_liquidity.unresolved_refund_incident_count', 0);
        $this->assertSame($before - 6, $ledger->accountBalanceMinor($subscriber->fresh()));
        $this->assertSame($cash - 6, $this->gate(fn () => app(\App\Services\HeldCashRecordedLiquidityEvidence::class)->balance($f['id'], '2026-11-25')));
        $this->assertSame(1, DB::table('payment_refunds')->where('disbursement_reference', $f['data']['reference'])->count());
        $legs = DB::table('financial_posting_events')->where('operation_uuid', $f['uuid'])->get();
        $this->assertCount(2, $legs);
        $this->assertSame([-6, -6], $legs->map(fn ($leg) => (int) $leg->amount_minor)->all());
        $this->assertEqualsCanonicalizing(['subscriber', 'treasury'], $legs->pluck('leg')->all());
        $this->getJson($f['root'].$f['uuid'])->assertOk()->assertJsonPath('status', 'rejected')
            ->assertJsonPath('incident_unresolved', false)->assertJsonPath('completion_verified', true)
            ->assertJsonPath('resolution.financial_posting_created', true);
        $tables = ['payment_refunds', 'transactions', 'treasury_postings', 'financial_posting_operations', 'financial_posting_events', 'audit_events'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->get()->toArray(), $tables);
        $after = $snapshot();
        $this->postJson($endpoint, $input)->assertOk()->assertJsonPath('registry_created', false);
        $this->postJson($endpoint, ['confirmation' => self::EVIDENCE])->assertConflict();
        $this->assertEquals($after, $snapshot());
        $this->assertSame(1, AuditEvent::query()->where('event', 'financial.completed_cash_refund_resolution_approved')->count());
    }

    public function test_release_refund_atomic_resolution_refuses_wrong_shortage(): void
    {
        $f = $this->resolutionControl('posting_required', -5);
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $this->postJson($f['root'].$f['uuid'].'/resolution-post', [
            'confirmation' => 'Independent proof confirms the original cash refund was disbursed',
        ])->assertConflict();
        $this->assertSame(0, DB::table('payment_refunds')->count());
        $this->assertResolutionControlBlocked($f, 'completed_refund_resolution_evidence');
    }

    public function test_release_refund_posting_readiness_exact_shortage_does_not_post(): void
    {
        $f = $this->resolutionControl('posting_required', -6);
        $tables = ['payment_refunds', 'transactions', 'treasury_postings', 'financial_posting_operations', 'financial_posting_events', 'audit_events'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->get()->toArray(), $tables);
        $before = $snapshot();
        \Laravel\Sanctum\Sanctum::actingAs($f['requester']);
        $service = app(\App\Services\CompletedCashRefundRequest::class);
        $this->rejects('completed_refund_resolution_independence', fn () => $service->postingResolutionReadiness(
            $f['requester'], $f['uuid'], $this->nowAt()));
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $proof = $service->postingResolutionReadiness($f['approver'], $f['uuid'], $this->nowAt());
        $this->assertSame(6, $proof['claimed_minor']);
        $this->assertSame(-6, $proof['expected_subscriber_delta_minor']);
        $this->assertSame(-6, $proof['expected_treasury_delta_minor']);
        $this->assertSame($proof['recorded_balance_minor'] - 6, $proof['observed_balance_minor']);
        $this->assertTrue($proof['evidence_ready_for_writer_review']);
        $this->assertFalse($proof['writer_eligibility_verified']);
        $this->assertFalse($proof['financial_posting_created']);
        $this->assertFalse($proof['spending_authorized']);
        $this->assertFalse($proof['closure_authorized']);
        $this->assertTrue($proof['incident_unresolved']);
        $this->assertEquals($before, $snapshot());
        $this->assertResolutionControlBlocked($f, 'completed_refund_resolution_evidence');
    }

    public function test_release_refund_posting_readiness_refuses_wrong_shortage(): void
    {
        $f = $this->resolutionControl('posting_required', -5);
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $this->rejects('completed_refund_resolution_evidence', fn () =>
            app(\App\Services\CompletedCashRefundRequest::class)->postingResolutionReadiness($f['approver'], $f['uuid'], $this->nowAt()));
        $this->assertResolutionControlBlocked($f, 'completed_refund_resolution_evidence');
    }

    public function test_release_refund_posting_readiness_refuses_newer_observation(): void
    {
        $f = $this->resolutionControl('posting_required', -6);
        $counts = app(TreasuryCashCountService::class);
        $new = $counts->record($f['counter'], $f['id'], $f['amount'], 'QA-NEWER-POSTING-COUNT', self::EVIDENCE)['count'];
        $counts->review($f['countReviewer'], (int) $new['id'], true, self::EVIDENCE);
        \Laravel\Sanctum\Sanctum::actingAs($f['approver']);
        $this->rejects('completed_refund_resolution_stale_count', fn () =>
            app(\App\Services\CompletedCashRefundRequest::class)->postingResolutionReadiness($f['approver'], $f['uuid'], $this->nowAt()));
        $this->assertResolutionControlBlocked($f, 'completed_refund_resolution_evidence');
    }

    public function test_release_refund_resolution_control_refuses_newer_cash_count(): void
    {
        $f = $this->resolutionControl();
        $counts = app(TreasuryCashCountService::class);
        $new = $counts->record($f['counter'], $f['id'], $f['amount'], 'QA-NEWER-COUNT', self::EVIDENCE)['count'];
        $counts->review($f['countReviewer'], (int) $new['id'], true, self::EVIDENCE);
        $this->assertResolutionControlBlocked($f, 'completed_refund_resolution_stale_count');
    }

    public function test_release_refund_resolution_control_refuses_competing_cash_assertion(): void
    {
        $f = $this->resolutionControl();
        \Laravel\Sanctum\Sanctum::actingAs($f['requester']);
        $this->postJson($f['submit'], array_replace($f['data'], [
            'operation_uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'reference' => 'QA-RESOLUTION-COMPETING',
        ]))->assertOk();
        $this->assertResolutionControlBlocked($f, 'completed_refund_resolution_competing_incidents', 2);
    }

    public function test_release_refund_resolution_control_refuses_posting_required(): void
    {
        $this->assertResolutionControlBlocked($this->resolutionControl('posting_required'),
            'completed_refund_resolution_evidence');
    }

    public function test_release_refund_resolution_control_refuses_unadjusted_shortage(): void
    {
        $this->assertResolutionControlBlocked($this->resolutionControl('cash_returned', -1),
            'completed_refund_resolution_evidence');
    }

    public function test_release_refund_resolution_proposal_preserves_cash_fence(): void
    {
        // Explicit synthetic fixture for historical unsigned algorithm regression only.
        config(['financial_candidate.qa_legacy_unsigned_refund_fixture' => true]);
        [, , $account, , $lot, $subscriber] = $this->held();
        $issuer = $this->actor();
        $requester = $this->actor();
        $reviewer = $this->actor();
        \App\Models\Tariff::create(['name' => 'Synthetic lifecycle tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-REFUND-LIFECYCLE']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        app(\App\Services\GatedInvoiceTransferWriter::class)->issue($issuer, (int) $subscriber->id, (int) $meter->id,
            '66666666-6666-4666-8666-666666666666', $this->nowAt());
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 20);
        $id = (int) $account['id'];
        $credit = DB::table('treasury_held_cash_credits')->sole();
        $release = app(\App\Services\HeldCashReleaseProposalService::class);
        $proposal = '77777777-7777-4777-8777-777777777777';
        $release->propose($issuer, $id, (int) $credit->id, 10, $proposal, 'QA-LIFECYCLE-RELEASE', self::EVIDENCE, $this->nowAt());
        config(['financial_candidate.active_held_release' => true, 'financial_candidate.completed_cash_refund' => true]);
        $release->recordActiveRelease($reviewer, $id, $proposal, '88888888-8888-4888-8888-888888888888', self::EVIDENCE, $this->nowAt());
        $other = Subscriber::create(['name' => 'Synthetic lifecycle refund recipient', 'square_id' => $subscriber->square_id, 'status' => 'active']);
        $receipt = app(GatedCashReceiptWriter::class)->collect($issuer, (int) $other->id, '1.00',
            '99999999-9999-4999-8999-999999999999', $this->nowAt())['payment'];
        $submit = '/api/payments/'.$receipt->id.'/completed-cash-refund-requests';
        $root = '/api/completed-cash-refund-requests/';
        $data = ['account_id' => $id, 'amount' => '0.06', 'reference' => 'QA-LIFECYCLE-1', 'date' => '2026-11-25',
            'reason' => 'Independent evidence of completed cash disbursement', 'evidence' => self::EVIDENCE,
            'operation_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'disbursement_confirmed' => true];
        $uuid = $data['operation_uuid'];
        $endpoint = $root.$uuid.'/resolution-proposal';
        $counter = $this->actor();
        $countReviewer = $this->actor();
        $proposer = $this->actor();
        \Laravel\Sanctum\Sanctum::actingAs($requester);
        $this->postJson($submit, $data)->assertOk();
        $input = ['count_id' => 1, 'classification' => 'no_disbursement', 'evidence' => self::EVIDENCE];
        \Laravel\Sanctum\Sanctum::actingAs($proposer);
        $this->postJson($endpoint, $input)->assertConflict();
        \Laravel\Sanctum\Sanctum::actingAs($reviewer);
        $this->postJson($root.$uuid.'/close', ['decision' => 'rejected', 'reason' => self::EVIDENCE])->assertOk();
        $balance = $this->gate(fn () => app(\App\Services\HeldCashRecordedLiquidityEvidence::class)->balance($id, '2026-11-25'));
        $counts = app(TreasuryCashCountService::class);
        $amount = sprintf('%d.%02d', intdiv($balance, 100), $balance % 100);
        $count = $counts->record($counter, $id, $amount, 'QA-RESOLUTION-COUNT', self::EVIDENCE)['count'];
        $input['count_id'] = (int) $count['id'];
        \Laravel\Sanctum\Sanctum::actingAs($proposer);
        $this->postJson($endpoint, $input)->assertConflict();
        $counts->review($countReviewer, (int) $count['id'], true, self::EVIDENCE);
        $tables = ['payment_refunds', 'transactions', 'treasury_postings', 'financial_posting_operations', 'financial_posting_events'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->get()->toArray(), $tables);
        $before = $snapshot();
        \Laravel\Sanctum\Sanctum::actingAs($requester);
        $this->postJson($endpoint, $input)->assertConflict();
        \Laravel\Sanctum\Sanctum::actingAs($proposer);
        $this->postJson($endpoint, $input + ['actor_id' => $reviewer->id])->assertUnprocessable();
        $this->postJson($endpoint, $input)->assertOk()->assertJsonPath('created', true)
            ->assertJsonPath('incident_unresolved', true)->assertJsonPath('resolution_proposal.count_id', (int) $count['id']);
        $this->postJson($endpoint, $input)->assertOk()->assertJsonPath('created', false);
        $this->postJson($endpoint, array_replace($input, ['classification' => 'cash_returned']))->assertConflict();
        $this->postJson($endpoint, array_replace($input, ['evidence' => self::EVIDENCE.' changed']))->assertConflict();
        $this->assertSame(1, AuditEvent::query()->where('event', 'financial.completed_cash_refund_resolution_proposed')->count());
        $this->assertEquals($before, $snapshot());
        $audit = DB::table('audit_events')->where('event', 'financial.completed_cash_refund_resolution_proposed')->sole();
        foreach ([fn () => DB::table('audit_events')->where('id', $audit->id)->update(['after' => '{}']),
            fn () => DB::table('audit_events')->where('id', $audit->id)->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('Resolution proposal evidence was mutable.');
            } catch (\Illuminate\Database\QueryException $error) {
                $this->assertStringContainsString('Financial approval audit is immutable or invalid', $error->getMessage());
            }
        }
        $this->rejects('completed_cash_refund_incident_unresolved', fn () => $this->gate(fn () =>
            app(\App\Services\HeldCashReservation::class)->assertOrdinaryOutflow($id, 1, '2026-11-25')));
        $proof = $this->gate(fn () => app(\App\Services\ActiveHeldCashRelease::class)->currentLiquidity($id));
        $this->assertSame(1, $proof['unresolved_refund_incident_count']);
        $this->assertSame(0, $proof['available_for_ordinary_outflow_minor']);
        $approval = $root.$uuid.'/resolution-approve';
        $input = ['confirmation' => 'Independent investigation confirms no cash left the treasury'];
        $approver = $this->actor();
        foreach ([$requester, $proposer, $reviewer, $counter, $countReviewer] as $excluded) {
            \Laravel\Sanctum\Sanctum::actingAs($excluded);
            $this->postJson($approval, $input)->assertConflict();
        }
        \Laravel\Sanctum\Sanctum::actingAs($approver);
        $this->postJson($approval, $input + ['reviewer_id' => $reviewer->id])->assertUnprocessable();
        $this->postJson($approval, $input)->assertOk()->assertJsonPath('created', true)
            ->assertJsonPath('incident_unresolved', false);
        $this->postJson($approval, $input)->assertOk()->assertJsonPath('created', false);
        $this->postJson($approval, ['confirmation' => self::EVIDENCE])->assertConflict();
        $this->getJson($root.$uuid)->assertOk()->assertJsonPath('status', 'rejected')
            ->assertJsonPath('incident_unresolved', false)->assertJsonPath('resolution.approved_by', $approver->id);
        $this->assertEquals($before, $snapshot());
        $this->gate(fn () => app(\App\Services\CompletedCashRefundRequest::class)->assertNoUnresolvedForAccount($id));
        $proof = $this->gate(fn () => app(\App\Services\ActiveHeldCashRelease::class)->currentLiquidity($id));
        $this->assertSame(0, $proof['unresolved_refund_incident_count']);
        $this->assertFalse($proof['incident_outflow_blocked']);
        $approved = DB::table('audit_events')->where('event', 'financial.completed_cash_refund_resolution_approved')->sole();
        foreach ([fn () => DB::table('audit_events')->where('id', $approved->id)->update(['after' => '{}']),
            fn () => DB::table('audit_events')->where('id', $approved->id)->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('Resolution approval evidence was mutable.');
            } catch (\Illuminate\Database\QueryException $error) {
                $this->assertStringContainsString('Financial approval audit is immutable or invalid', $error->getMessage());
            }
        }
    }

    public function test_release_completed_refund_lifecycle_preserves_rejected_and_withdrawn_incidents(): void
    {
        // Explicit synthetic fixture for historical unsigned algorithm regression only.
        config(['financial_candidate.qa_legacy_unsigned_refund_fixture' => true]);
        [, , $account, , $lot, $subscriber] = $this->held();
        $issuer = $this->actor();
        $requester = $this->actor();
        $reviewer = $this->actor();
        \App\Models\Tariff::create(['name' => 'Synthetic lifecycle tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-REFUND-LIFECYCLE']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        app(\App\Services\GatedInvoiceTransferWriter::class)->issue($issuer, (int) $subscriber->id, (int) $meter->id,
            '66666666-6666-4666-8666-666666666666', $this->nowAt());
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 20);
        $id = (int) $account['id'];
        $credit = DB::table('treasury_held_cash_credits')->sole();
        $release = app(\App\Services\HeldCashReleaseProposalService::class);
        $proposal = '77777777-7777-4777-8777-777777777777';
        $release->propose($issuer, $id, (int) $credit->id, 10, $proposal, 'QA-LIFECYCLE-RELEASE', self::EVIDENCE, $this->nowAt());
        config(['financial_candidate.active_held_release' => true, 'financial_candidate.completed_cash_refund' => true]);
        $release->recordActiveRelease($reviewer, $id, $proposal, '88888888-8888-4888-8888-888888888888', self::EVIDENCE, $this->nowAt());
        $other = Subscriber::create(['name' => 'Synthetic lifecycle refund recipient', 'square_id' => $subscriber->square_id, 'status' => 'active']);
        $receipt = app(GatedCashReceiptWriter::class)->collect($issuer, (int) $other->id, '1.00',
            '99999999-9999-4999-8999-999999999999', $this->nowAt())['payment'];
        $submit = '/api/payments/'.$receipt->id.'/completed-cash-refund-requests';
        $root = '/api/completed-cash-refund-requests/';
        $data = ['account_id' => $id, 'amount' => '0.06', 'reference' => 'QA-LIFECYCLE-1', 'date' => '2026-11-25',
            'reason' => 'Independent evidence of completed cash disbursement', 'evidence' => self::EVIDENCE,
            'operation_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'disbursement_confirmed' => true];
        $assertBlocked = fn () => $this->rejects('completed_cash_refund_incident_unresolved', fn () => $this->gate(fn () =>
            app(\App\Services\HeldCashReservation::class)->assertOrdinaryOutflow($id, 1, '2026-11-25')));
        $assertIncidentLiquidity = function (int $count, int $claimed) use ($id): void {
            $proof = app(\App\Services\HeldCashReleaseReadiness::class)->preview($id, $this->nowAt());
            $this->assertSame($count, $proof['unresolved_refund_incident_count']);
            $this->assertSame($claimed, $proof['unresolved_refund_claimed_minor']);
            $this->assertTrue($proof['incident_outflow_blocked']);
            $this->assertSame(0, $proof['candidate_additional_release_minor']);
            $actual = $this->gate(fn () => app(\App\Services\ActiveHeldCashRelease::class)->currentLiquidity($id));
            $this->assertTrue($actual['additional_outflow_blocked']);
            $this->assertSame(0, $actual['available_for_ordinary_outflow_minor']);
            $this->assertSame($count, $actual['unresolved_refund_incident_count']);
        };
        $first = $data['operation_uuid'];
        $second = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $third = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $reason = 'The evidence needs independent further reconciliation';
        $confirm = 'Independent signed confirmation of cash already received';
        $snapshot = fn ($table) => DB::table($table)->orderBy('id')->get()->toArray();
        $tables = ['payment_refunds', 'transactions', 'treasury_postings', 'payment_allocations', 'payment_allocation_revisions'];
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        \Laravel\Sanctum\Sanctum::actingAs($requester);
        $this->postJson($submit, $data)->assertOk()->assertJsonPath('status', 'pending');
        $assertBlocked();
        $assertIncidentLiquidity(1, 6);
        $this->postJson($root.$first.'/close', ['decision' => 'rejected', 'reason' => $reason])->assertConflict();
        \Laravel\Sanctum\Sanctum::actingAs($issuer);
        $this->postJson($root.$first.'/close', ['decision' => 'rejected', 'reason' => $reason])->assertConflict();
        \Laravel\Sanctum\Sanctum::actingAs($reviewer);
        $this->postJson($root.$first.'/close', ['decision' => 'withdrawn', 'reason' => $reason])->assertConflict();
        $this->postJson($root.$first.'/close', ['decision' => 'rejected', 'reason' => $reason])
            ->assertOk()->assertJsonPath('created', true)->assertJsonPath('status', 'rejected')->assertJsonPath('incident_unresolved', true);
        $assertBlocked();
        $assertIncidentLiquidity(1, 6);
        $this->postJson($root.$first.'/close', ['decision' => 'rejected', 'reason' => $reason])->assertOk()->assertJsonPath('created', false);
        $this->postJson($root.$first.'/close', ['decision' => 'rejected', 'reason' => $reason.' changed'])->assertConflict();
        $this->postJson($root.$first.'/approve', ['confirmation' => $confirm])->assertConflict();
        $this->getJson($root.$first)->assertOk()->assertJsonPath('status', 'rejected')
            ->assertJsonPath('operation_exists', false)->assertJsonPath('incident_unresolved', true);
        \Laravel\Sanctum\Sanctum::actingAs($requester);
        $this->postJson($submit, $data)->assertOk()->assertJsonPath('created', false)->assertJsonPath('status', 'rejected');
        $this->postJson($submit, array_replace($data, ['operation_uuid' => $second]))->assertConflict();
        $this->postJson($submit, array_replace($data, ['operation_uuid' => $second, 'reference' => 'QA-LIFECYCLE-2']))->assertOk();
        $this->postJson($root.$second.'/close', ['decision' => 'withdrawn', 'reason' => $reason])
            ->assertOk()->assertJsonPath('status', 'withdrawn')->assertJsonPath('incident_unresolved', true);
        $assertBlocked();
        $assertIncidentLiquidity(2, 12);
        \Laravel\Sanctum\Sanctum::actingAs($reviewer);
        $this->postJson($root.$second.'/approve', ['confirmation' => $confirm])->assertConflict();
        foreach ($before as $table => $rows) $this->assertEquals($rows, $snapshot($table), $table);
        $this->assertSame(2, AuditEvent::query()->where('event', 'financial.completed_cash_refund_closed')->count());
        $closed = DB::table('audit_events')->where('event', 'financial.completed_cash_refund_closed')->where('entity_id', $first)->sole();
        foreach ([fn () => DB::table('audit_events')->where('id', $closed->id)->delete(),
            fn () => DB::table('audit_events')->where('id', $closed->id)->update(['after' => '{}'])] as $attempt) {
            try {
                $attempt();
                $this->fail('Closed request evidence was mutable.');
            } catch (\Illuminate\Database\QueryException $error) {
                $this->assertStringContainsString('Financial approval audit is immutable or invalid', $error->getMessage());
            }
        }
        \Laravel\Sanctum\Sanctum::actingAs($requester);
        $this->postJson($submit, array_replace($data, ['operation_uuid' => $third, 'reference' => 'QA-LIFECYCLE-3']))->assertOk();
        \Laravel\Sanctum\Sanctum::actingAs($reviewer);
        $this->postJson($root.$third.'/approve', ['confirmation' => $confirm])->assertOk()->assertJsonPath('registry_created', true);
        $this->postJson($root.$third.'/close', ['decision' => 'rejected', 'reason' => $reason])->assertConflict();
        $this->getJson($root.$third)->assertOk()->assertJsonPath('status', 'posting_recorded_unverified')
            ->assertJsonPath('operation_exists', true)->assertJsonPath('completion_verified', false);
        $this->assertDatabaseCount('payment_refunds', 1);
        $assertIncidentLiquidity(2, 12); // posted third request must not be counted twice
    }

    public function test_release_completed_cash_refund_preserves_factual_deficit_and_exact_identity(): void
    {
        // Explicit synthetic fixture for historical unsigned algorithm regression only.
        config(['financial_candidate.qa_legacy_unsigned_refund_fixture' => true]);
        [, , $account, , $lot, $subscriber] = $this->held();
        $actor = $this->actor();
        $reviewer = $this->actor();
        $witness = $this->actor();
        \App\Models\Tariff::create(['name' => 'Synthetic completed refund tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-COMPLETED-REFUND']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        app(\App\Services\GatedInvoiceTransferWriter::class)->issue($actor, (int) $subscriber->id, (int) $meter->id,
            '66666666-6666-4666-8666-666666666666', $this->nowAt());
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 20);
        $id = (int) $account['id'];
        $credit = DB::table('treasury_held_cash_credits')->sole();
        $release = app(\App\Services\HeldCashReleaseProposalService::class);
        $proposal = '77777777-7777-4777-8777-777777777777';
        $release->propose($actor, $id, (int) $credit->id, 10, $proposal, 'QA-COMPLETED-RELEASE', self::EVIDENCE, $this->nowAt());
        config(['financial_candidate.active_held_release' => true]);
        $release->recordActiveRelease($reviewer, $id, $proposal, '88888888-8888-4888-8888-888888888888',
            self::EVIDENCE, $this->nowAt());
        $date = '2026-11-25';
        $destination = app(GatedTreasuryOpeningFundingWriter::class)->open($reviewer, [
            'code' => 'QA-REFUND-DEST', 'name' => 'Synthetic completed refund destination', 'channel' => 'cash',
            'opening_on' => $date, 'opening_amount' => '1.00',
            'opening_reference' => 'QA-COMPLETED-OPEN', 'opening_evidence' => self::EVIDENCE,
        ], '99999999-9999-4999-8999-999999999999', $this->nowAt())['account'];
        app(\App\Services\GatedTreasuryTransferWriter::class)->dispatch($actor, [
            'from_account_id' => $id, 'to_account_id' => (int) $destination['id'], 'amount' => '100.10',
            'reference' => 'QA-COMPLETED-DISPATCH', 'dispatched_on' => $date, 'evidence' => self::EVIDENCE],
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $this->nowAt());
        $other = Subscriber::create(['name' => 'Synthetic refund recipient', 'square_id' => $subscriber->square_id, 'status' => 'active']);
        $receipt = app(GatedCashReceiptWriter::class)->collect($actor, (int) $other->id, '1.00',
            'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $this->nowAt())['payment'];
        $writer = app(\App\Services\GatedRefundWriter::class);
        $uuid = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $reason = 'Independently documented excess credit refund';
        $confirmation = 'Independent signed confirmation of cash already received';
        $ordinary = fn () => $writer->record($reviewer, (int) $receipt->id, $id, '0.06', 'QA-COMPLETED-REFUND',
            'treasury_cash', $date, $reason, self::EVIDENCE, $uuid, $this->nowAt());
        $complete = function ($verifier, $proof = null) use ($writer, $reviewer, $receipt, $id, $date, $reason, $confirmation, $uuid) {
            \Laravel\Sanctum\Sanctum::actingAs($verifier);
            return $writer->recordCompletedCashRefund($reviewer, $verifier,
                (int) $receipt->id, $id, '0.06', 'QA-COMPLETED-REFUND', $date, $reason, self::EVIDENCE,
                $proof ?? $confirmation, $uuid, $this->nowAt());
        };
        $this->rejects('held_cash_reserved', $ordinary);
        $this->rejects('completed_cash_refund_disabled', fn () => $complete($witness));
        config(['financial_candidate.completed_cash_refund' => true]);
        $this->rejects('completed_cash_refund_request_required', fn () => $complete($witness));
        $submitUrl = '/api/payments/'.$receipt->id.'/completed-cash-refund-requests';
        $approveUrl = '/api/completed-cash-refund-requests/'.$uuid.'/approve';
        $data = ['account_id' => $id, 'amount' => '0.06', 'reference' => 'QA-COMPLETED-REFUND',
            'date' => $date, 'reason' => $reason, 'evidence' => self::EVIDENCE,
            'operation_uuid' => $uuid, 'disbursement_confirmed' => true];
        \Laravel\Sanctum\Sanctum::actingAs($reviewer);
        $this->postJson($submitUrl, $data + ['reviewer_id' => $witness->id])->assertUnprocessable();
        $this->postJson($submitUrl, $data)->assertOk()->assertJsonPath('created', true);
        $this->postJson($submitUrl, $data)->assertOk()->assertJsonPath('created', false);
        $this->postJson($submitUrl, array_replace($data, ['amount' => '0.07']))->assertConflict();
        $this->postJson($approveUrl, ['confirmation' => $confirmation])->assertConflict();
        $this->rejects('completed_cash_refund_request_conflict', $ordinary);
        try {
            $writer->recordCompletedCashRefund($reviewer, $witness, (int) $receipt->id, $id, '0.06',
                'QA-COMPLETED-REFUND', $date, $reason, self::EVIDENCE, $confirmation, $uuid, $this->nowAt());
            $this->fail('Unauthenticated reviewer identity was accepted.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->rejects('completed_cash_refund_independence', fn () => $complete($reviewer));
        $this->rejects('completed_cash_refund_independence', fn () => $complete($actor));
        $this->assertDatabaseCount('payment_refunds', 0);
        \Laravel\Sanctum\Sanctum::actingAs($witness);
        $this->getJson('/api/completed-cash-refund-requests/'.$uuid)->assertOk()
            ->assertJsonPath('request.amount', '0.06')->assertJsonPath('request.requested_by', $reviewer->id)
            ->assertJsonPath('request.evidence', self::EVIDENCE)->assertJsonPath('operation_exists', false);
        $this->postJson($approveUrl, ['confirmation' => $confirmation, 'amount' => '0.07'])->assertUnprocessable();
        $this->postJson($approveUrl, ['confirmation' => $confirmation, 'reviewer_id' => $actor->id])->assertUnprocessable();
        $this->rejects('completed_cash_refund_request_conflict', fn () => $writer->recordCompletedCashRefund(
            $reviewer, $witness, (int) $receipt->id, $id, '0.07', 'QA-COMPLETED-REFUND', $date,
            $reason, self::EVIDENCE, $confirmation, $uuid, $this->nowAt()));
        $result = $this->postJson($approveUrl, ['confirmation' => $confirmation])->assertOk()->json();
        $this->assertTrue($result['registry_created']);
        $this->gate(fn () => app(\App\Services\CompletedCashRefundRequest::class)->assertNoUnresolvedForAccount($id));
        $this->assertSame(-6, (int) $result['posting']['amount_minor']);
        $events = DB::table('financial_posting_events')->where('operation_uuid', $uuid)->orderBy('leg')->get();
        $this->assertSame(['subscriber', 'treasury'], $events->pluck('leg')->all());
        $this->assertSame([-6, -6], $events->map(fn ($row) => (int) $row->amount_minor)->all());
        $liquidity = $result['current_active_release_liquidity'];
        foreach (['recorded_balance_minor' => 9, 'pending_refunds_minor' => 0, 'active_released_minor' => 10,
            'protected_minor' => 15, 'margin_minor' => -6, 'shortfall_minor' => 6] as $key => $value) {
            $this->assertSame($value, $liquidity[$key], $key);
        }
        $this->assertTrue($liquidity['additional_outflow_blocked']);
        $this->assertFalse($liquidity['incident_outflow_blocked']);
        $this->assertSame(0, $liquidity['unresolved_refund_incident_count']);
        $this->assertSame(0, $liquidity['unresolved_refund_claimed_minor']);
        $ledger = app(\App\Services\TransactionService::class);
        $this->assertSame(94, $ledger->accountBalanceMinor($other));
        $this->assertSame(10, $ledger->accountBalanceMinor($subscriber));
        $this->assertTrue(app(\App\Services\FinancialReconciliationService::class)->inspect($other)['matches']);
        $this->assertTrue(app(\App\Services\FinancialReconciliationService::class)->inspect($subscriber)['matches']);
        $tables = ['payment_refunds', 'transactions', 'treasury_postings', 'audit_events', 'payment_allocation_revisions',
            'payment_allocations', 'financial_posting_operations', 'financial_posting_events', 'held_cash_active_releases'];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        config(['financial_candidate.completed_cash_refund' => false]);
        $this->assertFalse($complete($witness)['registry_created']);
        $this->rejects('completed_cash_refund_replay_conflict', fn () => $complete($witness, 'Changed independent cash confirmation evidence'));
        $this->rejects('completed_cash_refund_replay_conflict', $ordinary);
        foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $this->rejects('held_cash_reserved', fn () => $this->gate(fn () =>
            app(\App\Services\HeldCashReservation::class)->assertOrdinaryOutflow($id, 1, $date)));
        $this->assertSame(1, AuditEvent::query()->where('event', 'financial.completed_cash_refund')->count());
        // Attack only the newly protected approval records on the disposable DB.
        $audit = DB::table('audit_events')->where('event', 'financial.completed_cash_refund')->sole();
        $activation = DB::table('audit_events')->where('event', 'treasury.held_release_activated')->sole();
        $assertBlocked = function (callable $attempt): void {
            try {
                $attempt();
                $this->fail('Protected financial approval mutation unexpectedly succeeded.');
            } catch (\Illuminate\Database\QueryException $error) {
                $this->assertStringContainsString('Financial approval audit is immutable or invalid', $error->getMessage());
            }
        };
        $requestAudit = DB::table('audit_events')->where('event', 'financial.completed_cash_refund_requested')->sole();
        foreach ([$audit, $activation, $requestAudit] as $protectedAudit) {
            $assertBlocked(fn () => DB::table('audit_events')->where('id', $protectedAudit->id)->update(['actor_user_id' => $actor->id]));
            $assertBlocked(fn () => DB::table('audit_events')->where('id', $protectedAudit->id)->update(['event' => 'test.unrelated']));
            $assertBlocked(fn () => DB::table('audit_events')->where('id', $protectedAudit->id)->update(['after' => '{}']));
            $assertBlocked(fn () => DB::table('audit_events')->where('id', $protectedAudit->id)->delete());
            $duplicate = (array) $protectedAudit;
            unset($duplicate['id']);
            $assertBlocked(fn () => DB::table('audit_events')->insert($duplicate));
        }
        if (DB::getDriverName() === 'mysql') {
            // The older, broader identity guards execute first on MySQL.
            // Prove that the new guards independently reject these mutations
            // on this disposable test DB, then restore both original guards.
            DB::unprepared('DROP TRIGGER protect_user_identity_delete');
            try {
                $assertBlocked(fn () => DB::table('users')->where('id', $witness->id)->delete());
            } finally {
                (require database_path('migrations/2026_11_25_010000_protect_user_identity.php'))->up();
            }
            DB::unprepared('DROP TRIGGER protect_user_identity_update');
            try {
                $assertBlocked(fn () => DB::table('users')->where('id', $witness->id)->update(['id' => 999999]));
            } finally {
                (require database_path('migrations/2026_11_25_010100_harden_user_identity_guards.php'))->up();
            }
            $this->assertSame(2, DB::table('information_schema.TRIGGERS')
                ->whereRaw('TRIGGER_SCHEMA = DATABASE()')
                ->whereIn('TRIGGER_NAME', ['protect_user_identity_delete', 'protect_user_identity_update'])->count());
        } else {
            $assertBlocked(fn () => DB::table('users')->where('id', $witness->id)->delete());
            $assertBlocked(fn () => DB::table('users')->where('id', $witness->id)->update(['id' => 999999]));
        }
        if (DB::getDriverName() === 'sqlite') {
            $replacement = (array) $audit;
            $replacement['event'] = 'test.unrelated';
            $cols = array_keys($replacement);
            $assertBlocked(fn () => DB::statement('INSERT OR REPLACE INTO audit_events ('
                .implode(', ', array_map(fn ($key) => chr(96).$key.chr(96), $cols)).') VALUES ('
                .implode(', ', array_fill(0, count($cols), '?')).')', array_values($replacement)));
            $user = (array) DB::table('users')->where('id', $witness->id)->sole();
            $cols = array_keys($user);
            $assertBlocked(fn () => DB::statement('INSERT OR REPLACE INTO users ('
                .implode(', ', array_map(fn ($key) => chr(96).$key.chr(96), $cols)).') VALUES ('
                .implode(', ', array_fill(0, count($cols), '?')).')', array_values($user)));
        }
        $this->assertEquals($audit, DB::table('audit_events')->where('id', $audit->id)->sole());
        $this->assertEquals($activation, DB::table('audit_events')->where('id', $activation->id)->sole());
        $this->assertEquals($requestAudit, DB::table('audit_events')->where('id', $requestAudit->id)->sole());
        $this->assertTrue(app(\App\Services\FinancialApprovalAuditProtection::class)->active());
        DB::unprepared('DROP TRIGGER fin_approval_audit_update');
        try {
            $this->rejects('financial_approval_audit_protection_missing', fn () => $complete($witness));
            $this->rejects('financial_approval_audit_protection_missing', fn () => $release->inspectJournal($id, $this->nowAt()));
        } finally {
            app(\App\Services\FinancialApprovalAuditProtection::class)->install();
        }
        $this->assertFalse($complete($witness)['registry_created']);
        // Nonfinancial audit events retain their existing behavior.
        $unrelated = AuditEvent::create(['event' => 'test.unrelated', 'source' => 'internal', 'actor_user_id' => $actor->id]);
        $this->assertSame(1, DB::table('audit_events')->where('id', $unrelated->id)->delete());

    }

    public function test_release_active_cash_loss_records_real_deficit_and_recovery_without_releasing_more(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        $actor = $this->actor();
        $reviewer = $this->actor();
        \App\Models\Tariff::create(['name' => 'Synthetic actual loss tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-ACTIVE-CASH-LOSS']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $invoice = app(\App\Services\GatedInvoiceTransferWriter::class)->issue($actor, (int) $subscriber->id, (int) $meter->id,
            '66666666-6666-4666-8666-666666666666', $this->nowAt())['invoice'];
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 20);
        $id = (int) $account['id'];
        $credit = DB::table('treasury_held_cash_credits')->sole();
        $release = app(\App\Services\HeldCashReleaseProposalService::class);
        $proposal = '77777777-7777-4777-8777-777777777777';
        $release->propose($actor, $id, (int) $credit->id, 10, $proposal, 'QA-LOSS-ACTIVE', self::EVIDENCE, $this->nowAt());
        config(['financial_candidate.active_held_release' => true]);
        $release->recordActiveRelease($reviewer, $id, $proposal, '88888888-8888-4888-8888-888888888888',
            self::EVIDENCE, $this->nowAt());
        $date = '2026-11-25';
        $destination = app(GatedTreasuryOpeningFundingWriter::class)->open($reviewer, [
            'code' => 'QA-LOSS-DEST', 'name' => 'Synthetic loss destination', 'channel' => 'cash',
            'opening_on' => $date, 'opening_amount' => '1.00',
            'opening_reference' => 'QA-LOSS-OPEN', 'opening_evidence' => self::EVIDENCE,
        ], '99999999-9999-4999-8999-999999999999', $this->nowAt())['account'];
        $to = (int) $destination['id'];
        $transfer = app(\App\Services\GatedTreasuryTransferWriter::class);
        $dispatch = $transfer->dispatch($actor, ['from_account_id' => $id, 'to_account_id' => $to, 'amount' => '100.10',
            'reference' => 'QA-LOSS-DISPATCH', 'dispatched_on' => $date, 'evidence' => self::EVIDENCE],
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $this->nowAt());
        $this->assertTrue($dispatch['registry_created']);
        $this->assertTrue($transfer->receive($reviewer, (int) $dispatch['transfer']['id'], [
            'amount' => '100.10', 'reference' => 'QA-LOSS-RECEIVE', 'received_on' => $date, 'evidence' => self::EVIDENCE],
            'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $this->nowAt())['registry_created']);
        $counts = app(TreasuryCashCountService::class);
        $writer = app(GatedCashCountAdjustmentWriter::class);
        $observe = function (string $amount, string $ref) use ($counts, $actor, $reviewer, $id): int {
            $observation = $counts->record($actor, $id, $amount, $ref, self::EVIDENCE);
            $countId = (int) $observation['count']['id'];
            $counts->review($reviewer, $countId, true, 'Independent review of actual cash loss');
            return $countId;
        };
        $first = $observe('0.10', 'QA-ACTIVE-LOSS-COUNT-1');
        $postFirst = fn () => $writer->adjust($reviewer, $first, 'QA-ACTIVE-LOSS-1', 'confirmed_cash_loss',
            self::REASON, self::EVIDENCE, 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', $this->nowAt());
        $result = $postFirst();
        $this->assertTrue($result['registry_created']);
        $this->assertSame(-5, (int) $result['posting']['amount_minor']);
        $liquidity = $result['current_active_release_liquidity'];
        foreach (['recorded_balance_minor' => 10, 'conservative_protected_minor' => 25,
            'active_released_minor' => 10, 'protected_minor' => 15, 'margin_minor' => -5, 'shortfall_minor' => 5] as $key => $value) {
            $this->assertSame($value, $liquidity[$key], $key);
        }
        $this->assertTrue($liquidity['additional_outflow_blocked']);
        $this->assertFalse($liquidity['closure_authorized']);
        $this->assertFalse($postFirst()['registry_created']);
        $second = $observe('0.09', 'QA-ACTIVE-LOSS-COUNT-2');
        $postSecond = fn () => $writer->adjust($reviewer, $second, 'QA-ACTIVE-LOSS-2', 'confirmed_cash_loss',
            self::REASON, self::EVIDENCE, 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', $this->nowAt());
        $result = $postSecond();
        $this->assertTrue($result['registry_created']);
        $this->assertSame(-1, (int) $result['posting']['amount_minor']);
        $this->assertSame(6, $result['current_active_release_liquidity']['shortfall_minor']);
        $this->assertSame(6, $postFirst()['current_active_release_liquidity']['shortfall_minor']);
        $this->assertFalse($postSecond()['registry_created']);
        $reserve = app(\App\Services\HeldCashReservation::class);
        $this->rejects('held_cash_reserved', fn () => $this->gate(fn () => $reserve->assertOrdinaryOutflow($id, 1, $date)));
        $this->assertSame(15, $this->gate(fn () => $reserve->protectedForAccount($id)));
        $this->assertDatabaseCount('treasury_cash_adjustments', 3);
        $this->assertDatabaseCount('held_cash_active_releases', 1);
        $this->assertDatabaseCount('held_cash_shadow_reinstatements', 0);
        $this->assertSame(10, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        $this->assertNotSame('cancelled', $invoice->fresh()->status);
        $back = $transfer->dispatch($reviewer, ['from_account_id' => $to, 'to_account_id' => $id, 'amount' => '0.07',
            'reference' => 'QA-ACTIVE-LOSS-RECOVERY', 'dispatched_on' => $date, 'evidence' => self::EVIDENCE],
            'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', $this->nowAt());
        $this->assertSame(6, $this->gate(fn () => app(\App\Services\ActiveHeldCashRelease::class)->currentLiquidity($id))['shortfall_minor']);
        $this->assertTrue($transfer->receive($actor, (int) $back['transfer']['id'], [
            'amount' => '0.07', 'reference' => 'QA-ACTIVE-LOSS-RECOVERY-RECEIPT', 'received_on' => $date, 'evidence' => self::EVIDENCE],
            'ffffffff-ffff-4fff-8fff-ffffffffffff', $this->nowAt())['registry_created']);
        $recovered = $this->gate(fn () => app(\App\Services\ActiveHeldCashRelease::class)->currentLiquidity($id));
        $this->assertSame(16, $recovered['recorded_balance_minor']);
        $this->assertSame(15, $recovered['protected_minor']);
        $this->assertSame(1, $recovered['margin_minor']);
        $this->assertSame(0, $recovered['shortfall_minor']);
        $this->assertFalse($recovered['additional_outflow_blocked']);
        $this->gate(fn () => $reserve->assertOrdinaryOutflow($id, 1, $date));
        $this->rejects('held_cash_reserved', fn () => $this->gate(fn () => $reserve->assertOrdinaryOutflow($id, 2, $date)));
        $this->assertDatabaseCount('treasury_cash_adjustments', 3);
        $this->assertDatabaseCount('held_cash_active_releases', 1);
        $this->assertSame(10, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
    }

    public function test_release_active_reserve_restores_with_liquidity_and_exact_replay(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        $actor = $this->actor();
        $reviewer = $this->actor();
        \App\Models\Tariff::create(['name' => 'Synthetic active restoration tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-ACTIVE-RESTORE']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $invoiceWriter = app(\App\Services\GatedInvoiceTransferWriter::class);
        $invoice = $invoiceWriter->issue($actor, (int) $subscriber->id, (int) $meter->id,
            '66666666-6666-4666-8666-666666666666', $this->nowAt())['invoice'];
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 20);
        $id = (int) $account['id'];
        $credit = DB::table('treasury_held_cash_credits')->sole();
        $service = app(\App\Services\HeldCashReleaseProposalService::class);
        $proposal = '77777777-7777-4777-8777-777777777777';
        $release = '88888888-8888-4888-8888-888888888888';
        $service->propose($actor, $id, (int) $credit->id, 10, $proposal, 'QA-ACTIVE-RESTORE', self::EVIDENCE, $this->nowAt());
        $activate = fn () => $service->recordActiveRelease($reviewer, $id, $proposal, $release, self::EVIDENCE, $this->nowAt());
        config(['financial_candidate.active_held_release' => true]);
        $initial = $activate();
        $this->assertTrue($initial['created']);
        $this->assertSame(10, $initial['after_minor']);
        config(['financial_candidate.active_held_release' => false]);
        $this->assertSame(array_replace($initial, ['created' => false]), $activate());
        $this->rejects('active_held_release_replay_conflict', fn () => $service->recordActiveRelease($reviewer, $id, $proposal,
            $release, 'Changed independent approval evidence', $this->nowAt()));
        $this->rejects('active_held_release_replay_conflict', fn () => $service->recordActiveRelease($actor, $id, $proposal,
            $release, self::EVIDENCE, $this->nowAt()));
        $reserve = app(\App\Services\HeldCashReservation::class);
        $this->assertSame(15, $this->gate(fn () => $reserve->protectedForAccount($id)));
        $date = '2026-11-25';
        $destination = app(GatedTreasuryOpeningFundingWriter::class)->open($reviewer, [
            'code' => 'QA-RESTORE-DEST', 'name' => 'Synthetic restoration destination', 'channel' => 'cash',
            'opening_on' => $date, 'opening_amount' => '1.00',
            'opening_reference' => 'QA-RESTORE-OPEN', 'opening_evidence' => self::EVIDENCE,
        ], '99999999-9999-4999-8999-999999999999', $this->nowAt())['account'];
        $transfer = app(\App\Services\GatedTreasuryTransferWriter::class);
        $data = ['from_account_id' => $id, 'to_account_id' => (int) $destination['id'], 'amount' => '100.00',
            'reference' => 'QA-RESTORE-DISPATCH', 'dispatched_on' => $date, 'evidence' => self::EVIDENCE];
        $this->assertTrue($transfer->dispatch($actor, $data, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $this->nowAt())['registry_created']);
        $postings = DB::table('treasury_postings')->orderBy('id')->get()->toArray();
        $this->assertSame(10, $service->inspectJournal($id, $this->nowAt())['actual_margin_minor']);
        $cancel = fn () => $invoiceWriter->cancel($actor, (int) $invoice->id, 'Independent documented cancellation',
            'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $this->nowAt());
        $this->assertTrue($cancel()['registry_created']);
        $this->assertSame('cancelled', $invoice->fresh()->status);
        $restored = $service->inspectJournal($id, $this->nowAt());
        $this->assertSame(0, $restored['active_released_minor']);
        $this->assertSame(25, $restored['actual_protected_minor']);
        $this->assertSame(0, $restored['actual_margin_minor']);
        $this->assertSame(0, $restored['actual_shortfall_minor']);
        $this->assertSame(25, $this->gate(fn () => $reserve->protectedForAccount($id)));
        $this->assertDatabaseCount('held_cash_shadow_reinstatements', 1);
        $event = DB::table('held_cash_shadow_reinstatements')->sole();
        $this->assertSame(10, (int) $event->before_minor);
        $this->assertSame(0, (int) $event->after_minor);
        $this->assertSame(20, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        $this->assertTrue(app(\App\Services\FinancialReconciliationService::class)->inspect($subscriber)['matches']);
        $tables = ['invoices', 'transactions', 'payment_allocation_revisions', 'payment_allocations',
            'held_cash_shadow_reinstatements', 'held_cash_active_releases', 'held_cash_release_journal',
            'financial_posting_events', 'financial_posting_operations', 'audit_events', 'treasury_postings'];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        $this->assertFalse($cancel()['registry_created']);
        $this->assertSame(array_replace($initial, ['created' => false]), $activate());
        foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $this->assertEquals($postings, DB::table('treasury_postings')->orderBy('id')->get()->toArray());
        $this->rejects('held_cash_reserved', fn () => $this->gate(fn () =>
            $reserve->assertOrdinaryOutflow($id, 1, $date)));
    }

    public function test_release_active_reserve_rejects_optional_cancellation_after_cash_spent(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        $actor = $this->actor();
        $reviewer = $this->actor();
        \App\Models\Tariff::create(['name' => 'Synthetic active reserve tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-ACTIVE-RESERVE']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $invoiceWriter = app(\App\Services\GatedInvoiceTransferWriter::class);
        $invoice = $invoiceWriter->issue($actor, (int) $subscriber->id, (int) $meter->id,
            '66666666-6666-4666-8666-666666666666', $this->nowAt())['invoice'];
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 20);
        $id = (int) $account['id'];
        $credit = DB::table('treasury_held_cash_credits')->sole();
        $service = app(\App\Services\HeldCashReleaseProposalService::class);
        $proposal = '77777777-7777-4777-8777-777777777777';
        $service->propose($actor, $id, (int) $credit->id, 10, $proposal, 'QA-ACTIVE-RELEASE', self::EVIDENCE, $this->nowAt());
        $activate = fn () => $service->recordActiveRelease($reviewer, $id, $proposal,
            '88888888-8888-4888-8888-888888888888', self::EVIDENCE, $this->nowAt());
        $this->rejects('active_held_release_disabled', $activate);
        config(['financial_candidate.active_held_release' => true]);
        $postings = DB::table('treasury_postings')->get()->toArray();
        $this->assertTrue($activate()['active_release_recorded']);
        $this->assertEquals($postings, DB::table('treasury_postings')->get()->toArray());
        $reserve = app(\App\Services\HeldCashReservation::class);
        $this->assertSame(15, $this->gate(fn () => $reserve->protectedForAccount($id)));
        config(['financial_candidate.active_held_release' => false]);
        $this->assertSame(15, $this->gate(fn () => $reserve->protectedForAccount($id)));
        $this->rejects('active_held_release_requires_gate', fn () => DB::transaction(fn () => $reserve->protectedForAccount($id)));
        $date = '2026-11-25';
        $destination = app(GatedTreasuryOpeningFundingWriter::class)->open($reviewer, [
            'code' => 'QA-ACTIVE-DEST', 'name' => 'Synthetic active reserve destination', 'channel' => 'cash',
            'opening_on' => $date, 'opening_amount' => '1.00',
            'opening_reference' => 'QA-ACTIVE-OPEN', 'opening_evidence' => self::EVIDENCE,
        ], '99999999-9999-4999-8999-999999999999', $this->nowAt())['account'];
        $transfer = app(\App\Services\GatedTreasuryTransferWriter::class);
        $data = ['from_account_id' => $id, 'to_account_id' => (int) $destination['id'], 'amount' => '100.10',
            'reference' => 'QA-ACTIVE-DISPATCH', 'dispatched_on' => $date, 'evidence' => self::EVIDENCE];
        $this->rejects('held_cash_reserved', fn () => $transfer->dispatch($actor, array_replace($data, ['amount' => '100.11']),
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $this->nowAt()));
        $this->assertTrue($transfer->dispatch($actor, $data, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $this->nowAt())['registry_created']);
        $tables = ['invoices', 'transactions', 'payment_allocation_revisions', 'payment_allocations',
            'held_cash_shadow_reinstatements', 'held_cash_active_releases', 'financial_posting_events',
            'financial_posting_operations', 'audit_events', 'treasury_postings'];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        $this->rejects('active_held_release_reinstatement_liquidity', fn () => $invoiceWriter->cancel($actor, (int) $invoice->id,
            'Independent documented cancellation', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $this->nowAt()));
        foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        $this->assertNotSame('cancelled', $invoice->fresh()->status);
        $this->assertSame(15, $this->gate(fn () => $reserve->protectedForAccount($id)));
    }

    public function test_release_mixed_reserve_keeps_handover_cash_protected_and_reports_shadow_liquidity(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        $actor = $this->actor();
        $collector = $this->actor();
        $reviewer = $this->actor();
        $other = Subscriber::create(['name' => 'Synthetic separate collection owner',
            'square_id' => $subscriber->square_id, 'status' => 'active']);
        \App\Models\Tariff::create(['name' => 'Synthetic mixed reserve tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-MIXED-RESERVE']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $invoiceWriter = app(\App\Services\GatedInvoiceTransferWriter::class);
        $invoice = $invoiceWriter->issue($actor, (int) $subscriber->id, (int) $meter->id,
            '66666666-6666-4666-8666-666666666666', $this->nowAt())['invoice'];
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 20);
        $sessions = app(CashSessionService::class);
        $session = $sessions->open($collector);
        app(GatedCashReceiptWriter::class)->collect($collector, (int) $other->id, '10.00',
            '77777777-7777-4777-8777-777777777777', $this->nowAt(), (int) $session->id);
        $session = $sessions->decide($sessions->close($session, '15.00'), true, $reviewer);
        $accountId = (int) $account['id'];
        app(\App\Services\GatedCashHandoverWriter::class)->receive($reviewer, (int) $session->id, $accountId,
            '15.00', 'QA-MIXED-RESERVE-HANDOVER', '2026-11-25', self::EVIDENCE,
            '88888888-8888-4888-8888-888888888888', $this->nowAt());
        $preview = app(\App\Services\HeldCashReleaseReadiness::class);
        $proof = $preview->preview($accountId, $this->nowAt());
        foreach (['recorded_balance_minor' => 11525, 'outstanding_minor' => 5, 'credited_minor' => 20,
            'applied_minor' => 10, 'unapplied_minor' => 10, 'handover_reserved_minor' => 500,
            'protected_minor' => 525, 'margin_minor' => 11000, 'candidate_additional_release_minor' => 10,
            'candidate_protected_minor' => 515, 'candidate_margin_minor' => 11010] as $key => $value) {
            $this->assertSame($value, $proof[$key], $key);
        }
        $service = app(\App\Services\HeldCashReleaseProposalService::class);
        $credit = DB::table('treasury_held_cash_credits')->sole();
        $proposal = '99999999-9999-4999-8999-999999999999';
        $service->propose($this->actor(), $accountId, (int) $credit->id, 10, $proposal,
            'QA-MIXED-RESERVE-RELEASE', self::EVIDENCE, $this->nowAt());
        $postings = DB::table('treasury_postings')->orderBy('id')->get()->toArray();
        $service->recordRelease($this->actor(), $accountId, $proposal,
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', self::EVIDENCE, $this->nowAt());
        $shadow = $service->inspectJournal($accountId, $this->nowAt());
        foreach (['actual_protected_minor' => 525, 'actual_margin_minor' => 11000, 'handover_reserved_minor' => 500,
            'shadow_released_minor' => 10, 'shadow_protected_minor' => 515, 'shadow_margin_minor' => 11010,
            'actual_shortfall_minor' => 0, 'shadow_shortfall_minor' => 0] as $key => $value) {
            $this->assertSame($value, $shadow[$key], $key);
        }
        $this->assertFalse($shadow['spending_release_authorized']);
        $this->assertFalse($shadow['closure_authorized']);
        $this->rejects('held_cash_reserved', fn () => DB::transaction(fn () =>
            app(\App\Services\HeldCashReservation::class)->assertOrdinaryOutflow($accountId, 11001, '2026-11-25')));
        $invoiceWriter->cancel($actor, (int) $invoice->id, 'Independent documented cancellation',
            'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $this->nowAt());
        $restored = $service->inspectJournal($accountId, $this->nowAt());
        $this->assertSame(0, $restored['shadow_released_minor']);
        $this->assertSame(525, $restored['shadow_protected_minor']);
        $this->assertSame(11000, $restored['shadow_margin_minor']);
        $this->assertSame(500, $restored['handover_reserved_minor']);
        $this->assertSame(0, $preview->preview($accountId, $this->nowAt())['candidate_additional_release_minor']);
        $this->assertEquals($postings, DB::table('treasury_postings')->orderBy('id')->get()->toArray());
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

    public function test_release_invoice_projection_db_fence_requires_allocation_and_reversal_evidence(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $actor = $this->actor();
        \App\Models\Tariff::create(['name' => 'Synthetic projection protection tariff', 'unit_price' => '1.00', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-PROJECTION-GUARD']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $writer = app(\App\Services\GatedInvoiceTransferWriter::class);
        $invoice = $writer->issue($actor, (int) $subscriber->id, (int) $meter->id,
            '66666666-6666-4666-8666-666666666666', $this->nowAt())['invoice'];
        $this->assertTrue(app(\App\Services\InvoiceProjectionProtection::class)->active());
        $tables = ['invoices', 'transactions', 'payments', 'payment_allocation_revisions', 'payment_allocations',
            'financial_posting_operations', 'financial_posting_events', 'audit_events', 'treasury_postings'];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        $unchanged = function (callable $action) use ($tables, $snapshot): void {
            $before = [];
            foreach ($tables as $table) $before[$table] = $snapshot($table);
            try {
                DB::transaction($action);
                $this->fail('Unproved invoice projection accepted.');
            } catch (\Illuminate\Database\QueryException $error) {
                $this->assertStringContainsString('Registered invoice projection requires financial evidence', $error->getMessage());
            }
            foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        };
        $update = fn (array $fields) => DB::table('invoices')->where('id', $invoice->id)->update($fields);
        $unchanged(fn () => $update(['amount_paid' => '0.50']));
        $unchanged(fn () => $update(['status' => 'paid']));
        $unchanged(fn () => $update(['status' => 'draft']));
        $unchanged(fn () => $update(['status' => 'cancelled', 'voided_at' => now(), 'void_reason' => 'Unproved direct cancellation']));
        $unchanged(fn () => $invoice->fresh()->forceFill(['amount_paid' => '0.50'])->saveQuietly());
        app(GatedCashReceiptWriter::class)->collect($actor, (int) $subscriber->id, '0.50',
            '77777777-7777-4777-8777-777777777777', $this->nowAt());
        $this->assertSame('0.50', $invoice->fresh()->amount_paid);
        $unchanged(fn () => $update(['amount_paid' => '0.00']));
        $unchanged(fn () => $update(['status' => 'issued']));
        $unchanged(fn () => $update(['void_reason' => 'Unproved void metadata']));
        $cancel = fn () => $writer->cancel($actor, (int) $invoice->id, 'Independent documented invoice cancellation',
            '88888888-8888-4888-8888-888888888888', $this->nowAt());
        $this->assertTrue($cancel()['registry_created']);
        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->assertSame('0.00', $invoice->fresh()->amount_paid);
        $unchanged(fn () => $update(['status' => 'issued']));
        $unchanged(fn () => $update(['void_reason' => 'Forged cancellation reason']));
        $unchanged(fn () => $update(['amount_paid' => '0.50']));
        $this->assertFalse($cancel()['registry_created']);
        $this->assertSame(50, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $account['id'])));
    }

    public function test_release_registered_document_db_fence_preserves_identity_and_meter_retirement(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $actor = $this->actor();
        $issuer = $this->actor();
        $other = Subscriber::create(['name' => 'Synthetic other account', 'square_id' => $subscriber->square_id, 'status' => 'active']);
        \App\Models\Tariff::create(['name' => 'Synthetic document guard tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-DOC-GUARD']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $invoice = app(\App\Services\GatedInvoiceTransferWriter::class)->issue($actor, (int) $subscriber->id, (int) $meter->id,
            '66666666-6666-4666-8666-666666666666', $this->nowAt())['invoice'];
        $session = app(CashSessionService::class)->open($issuer);
        $receipt = app(GatedCashReceiptWriter::class)->collect($issuer, (int) $subscriber->id, '0.30',
            '77777777-7777-4777-8777-777777777777', $this->nowAt(), (int) $session->id, (int) $invoice->id, (int) $meter->id)['payment'];
        $this->assertTrue(app(\App\Services\RegisteredDocumentProtection::class)->active());
        $this->assertSame('0.10', $invoice->fresh()->amount_paid);
        $tables = ['invoices', 'payments', 'transactions', 'payment_allocations', 'payment_allocation_revisions',
            'financial_posting_events', 'financial_posting_operations', 'audit_events', 'treasury_postings'];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        $attempts = [
            fn () => DB::table('invoices')->where('id', $invoice->id)->update(['total_amount' => '9.00']),
            fn () => DB::table('invoices')->where('id', $invoice->id)->update(['bill_number' => 'QA-FORGED']),
            fn () => DB::table('invoices')->where('id', $invoice->id)->update(['subscriber_id' => $other->id]),
            fn () => DB::table('payments')->where('id', $receipt->id)->update(['amount' => '9.00']),
            fn () => DB::table('payments')->where('id', $receipt->id)->update(['invoice_id' => null]),
            fn () => DB::table('payments')->where('id', $receipt->id)->update(['cash_session_id' => null]),
            fn () => DB::table('payments')->where('id', $receipt->id)->update(['sync_uuid' => null]),
            fn () => DB::table('payments')->where('id', $receipt->id)->update(['meter_id' => null, 'retired_meter_id' => $meter->id, 'retired_meter_code' => 'FAKE']),
            fn () => $invoice->fresh()->deleteQuietly(),
            fn () => $receipt->fresh()->deleteQuietly(),
            fn () => DB::table('invoices')->where('id', $invoice->id)->delete(),
            fn () => DB::table('payments')->where('id', $receipt->id)->delete(),
            fn () => DB::table('cash_sessions')->where('id', $session->id)->delete(),
            fn () => DB::table('meters')->where('id', $meter->id)->delete(),
        ];
        foreach ([['invoices', $invoice, ['bill_number']], ['payments', $receipt, ['receipt_number', 'sync_uuid']]] as [$table, $doc, $keys]) {
            $row = (array) DB::table($table)->where('id', $doc->id)->first();
            // Isolate each collision key, including the primary key.
            foreach (array_merge(['id'], $keys) as $key) {
                $copy = $row;
                if ($key !== 'id') unset($copy['id']);
                foreach ($keys as $natural) {
                    if ($key !== $natural) $copy[$natural] = $natural === 'sync_uuid' ? null : 'QA-OTHER-NUMBER';
                }
                $columns = implode(', ', array_keys($copy));
                $params = implode(', ', array_fill(0, count($copy), '?'));
                $verb = DB::getDriverName() === 'sqlite' ? 'INSERT OR REPLACE' : 'REPLACE';
                $attempts[] = fn () => DB::statement("{$verb} INTO {$table} ({$columns}) VALUES ({$params})", array_values($copy));
            }
        }
        foreach ($attempts as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('Unsafe registered document mutation succeeded.');
            } catch (\Illuminate\Database\QueryException $error) {
                $this->assertStringContainsString('Registered financial document is immutable', $error->getMessage());
            }
            foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        }
        $receipt->fresh()->update(['notes' => 'Nonfinancial note']);
        $this->assertSame('Nonfinancial note', $receipt->fresh()->notes);
        $replacement = app(\App\Services\SingleMeterService::class)->replace($meter,
            ['unique_code' => 'QA-DOC-GUARD-NEW', 'initial_reading' => '0.00'], '2026-11-25', 'Synthetic physical replacement', $actor->id)['current_meter'];
        $this->assertNotSame($meter->id, $replacement->id);
        foreach ([$invoice, $receipt] as $doc) {
            $this->assertNull($doc->fresh()->meter_id);
            $this->assertSame((int) $meter->id, (int) $doc->fresh()->retired_meter_id);
            $this->assertSame($meter->unique_code, $doc->fresh()->retired_meter_code);
        }
        $this->assertSame('0.10', $invoice->fresh()->amount_paid);
        $this->assertSame(20, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $account['id'])));
    }

    public function test_release_correction_db_protection_blocks_raw_and_quiet_mutations(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $other = Subscriber::create(['name' => 'Synthetic transfer destination', 'square_id' => $subscriber->square_id, 'status' => 'active']);
        $issuer = $this->actor();
        $reviewer = $this->actor();
        $collect = fn (string $uuid) => app(GatedCashReceiptWriter::class)->collect($issuer, (int) $subscriber->id, '10.00', $uuid, $this->nowAt())['payment'];
        $first = $collect('66666666-6666-4666-8666-666666666666');
        $second = $collect('77777777-7777-4777-8777-777777777777');
        $reason = 'Independent documented correction';
        $refund = app(\App\Services\GatedRefundWriter::class)->record($reviewer, (int) $first->id, (int) $account['id'], '4.00',
            'QA-CORRECTION-DB', 'treasury_cash', '2026-11-25', $reason, self::EVIDENCE,
            '88888888-8888-4888-8888-888888888888', $this->nowAt())['refund'];
        $transfer = app(\App\Services\GatedInvoiceTransferWriter::class)->transfer($reviewer, (int) $second->id, (int) $other->id,
            $reason, self::EVIDENCE, '99999999-9999-4999-8999-999999999999', $this->nowAt())['transfer'];
        $this->assertTrue(app(\App\Services\FinancialCorrectionDocumentProtection::class)->active());
        $tables = ['payments', 'payment_refunds', 'payment_account_transfers', 'transactions', 'treasury_postings',
            'payment_allocations', 'payment_allocation_revisions', 'financial_posting_operations', 'financial_posting_events', 'audit_events'];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        $before = [];
        foreach ($tables as $table) $before[$table] = $snapshot($table);
        foreach ([[$refund, 'payment_refunds'], [$transfer, 'payment_account_transfers']] as [$doc, $table]) {
            $row = (array) DB::table($table)->where('id', $doc->id)->first();
            $attempts = [
                fn () => DB::table($table)->where('id', $doc->id)->update(['amount' => '1.00']),
                fn () => DB::table($table)->where('id', $doc->id)->update(['reason' => 'Forged correction']),
                fn () => DB::table($table)->where('id', $doc->id)->delete(),
                fn () => $doc->fresh()->deleteQuietly(),
            ];
            foreach ([true, false] as $sameId) {
                $copy = $row;
                if (! $sameId) unset($copy['id']);
                $copy['amount'] = '1.00';
                $columns = implode(', ', array_keys($copy));
                $params = implode(', ', array_fill(0, count($copy), '?'));
                $verb = DB::getDriverName() === 'sqlite' ? 'INSERT OR REPLACE' : 'REPLACE';
                $attempts[] = fn () => DB::statement("{$verb} INTO {$table} ({$columns}) VALUES ({$params})", array_values($copy));
            }
            foreach ($attempts as $attempt) {
                try {
                    DB::transaction($attempt);
                    $this->fail('Unsafe correction document mutation succeeded.');
                } catch (\Illuminate\Database\QueryException $error) {
                    $this->assertStringContainsString('Financial correction document is immutable', $error->getMessage());
                }
                foreach ($before as $name => $rows) $this->assertSame($rows, $snapshot($name), $name);
            }
        }
        $ledger = app(\App\Services\TransactionService::class);
        $this->assertSame(600, $ledger->accountBalanceMinor($subscriber));
        $this->assertSame(1000, $ledger->accountBalanceMinor($other));
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

    public function test_release_void_request_db_fence_requires_exact_audit_evidence(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $issuer = $this->actor();
        $reviewer = $this->actor();
        $receipt = app(GatedCashReceiptWriter::class)->collect($issuer, (int) $subscriber->id, '1.00',
            '66666666-6666-4666-8666-666666666666', $this->nowAt())['payment'];
        $writer = app(\App\Services\GatedReceiptVoidWriter::class);
        $reason = 'Independent documented receipt cancellation';
        \App\Models\Setting::set('void_approval_threshold', '0.30');
        $this->assertTrue(app(\App\Services\ReceiptVoidRequestProtection::class)->active());
        $tables = ['payments', 'transactions', 'payment_allocation_revisions', 'payment_allocations',
            'financial_posting_operations', 'financial_posting_events', 'audit_events', 'treasury_postings'];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        $unchanged = function (callable $action, string $message = 'Registered receipt request requires audit evidence') use ($tables, $snapshot): void {
            $before = [];
            foreach ($tables as $table) $before[$table] = $snapshot($table);
            try {
                DB::transaction($action);
                $this->fail('Unproved request or audit mutation accepted.');
            } catch (\Illuminate\Database\QueryException $error) {
                $this->assertStringContainsString($message, $error->getMessage());
            }
            foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        };
        $update = fn (array $fields) => DB::table('payments')->where('id', $receipt->id)->update($fields);
        $requestFields = ['void_requested_at' => now(), 'void_requested_by' => $issuer->id, 'void_request_reason' => $reason];
        $unchanged(fn () => $update($requestFields));
        $request = fn () => $writer->request($issuer, (int) $receipt->id, $reason, $this->nowAt());
        $first = $request()['request_id'];
        $unchanged(fn () => $update(['void_request_reason' => 'Forged request reason']));
        $unchanged(fn () => $update(['void_requested_by' => $reviewer->id]));
        $unchanged(fn () => $update(['void_requested_at' => null, 'void_requested_by' => null, 'void_request_reason' => null]));
        $unchanged(fn () => $update(['void_decided_by' => $reviewer->id, 'void_decided_at' => now()]));
        $unchanged(fn () => DB::table('audit_events')->where('id', $first)->update(['actor_user_id' => $reviewer->id]), 'Receipt request audit is immutable');
        $unchanged(fn () => DB::table('audit_events')->where('id', $first)->delete(), 'Receipt request audit is immutable');
        $row = (array) DB::table('audit_events')->find($first);
        $columns = implode(', ', array_map(fn ($key) => chr(96).$key.chr(96), array_keys($row)));
        $params = implode(', ', array_fill(0, count($row), '?'));
        $verb = DB::getDriverName() === 'sqlite' ? 'INSERT OR REPLACE' : 'REPLACE';
        $unchanged(fn () => DB::statement("{$verb} INTO audit_events ({$columns}) VALUES ({$params})", array_values($row)), 'Receipt request audit is immutable');
        $reject = fn () => $writer->decide($reviewer, (int) $receipt->id, false, 'Independent rejection', $this->nowAt(), requestId: $first);
        $this->assertFalse($reject()['registry_created']);
        $this->assertFalse($reject()['registry_created']);
        $this->assertFalse($receipt->fresh()->hasPendingVoidRequest());
        $unchanged(fn () => $update($requestFields + ['void_decided_at' => null, 'void_decided_by' => null, 'void_decision_notes' => null]));
        $unchanged(fn () => $update(['void_decision_notes' => 'Forged rejection']));
        $second = $request()['request_id'];
        $this->assertNotSame($first, $second);
        $this->rejects('gated_void_replay_conflict', $reject);
        $approve = fn () => $writer->decide($reviewer, (int) $receipt->id, true, null, $this->nowAt(),
            '88888888-8888-4888-8888-888888888888', $second);
        $this->assertTrue($approve()['registry_created']);
        $this->assertFalse($approve()['registry_created']);
        $unchanged(fn () => $update(['void_decided_by' => $issuer->id]));
        $unchanged(fn () => $update(['void_decision_notes' => 'Forged approval']));
        $unchanged(fn () => $update(['void_requested_at' => null]));
        $this->assertTrue($receipt->fresh()->isVoided());
        $this->assertSame(0, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        $this->assertSame(25, DB::transaction(fn () => app(\App\Services\HeldCashReservation::class)->protectedForAccount((int) $account['id'])));
    }

    public function test_release_receipt_void_db_fence_requires_reversal_and_preserves_transfer(): void
    {
        [, , $account, , , $subscriber] = $this->held();
        $issuer = $this->actor();
        $reviewer = $this->actor();
        $other = Subscriber::create(['name' => 'Synthetic transfer destination', 'square_id' => $subscriber->square_id, 'status' => 'active']);
        $collect = fn (string $uuid) => app(GatedCashReceiptWriter::class)->collect($issuer, (int) $subscriber->id,
            '10.00', $uuid, $this->nowAt())['payment'];
        $first = $collect('66666666-6666-4666-8666-666666666666');
        $second = $collect('77777777-7777-4777-8777-777777777777');
        $writer = app(\App\Services\GatedReceiptVoidWriter::class);
        $reason = 'Independent documented receipt cancellation';
        $this->assertTrue(app(\App\Services\ReceiptVoidProtection::class)->active());
        $tables = ['payments', 'transactions', 'payment_account_transfers', 'payment_allocation_revisions',
            'payment_allocations', 'financial_posting_operations', 'financial_posting_events', 'audit_events', 'treasury_postings'];
        $snapshot = fn (string $table) => DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        $unchanged = function (callable $action) use ($tables, $snapshot): void {
            $before = [];
            foreach ($tables as $table) $before[$table] = $snapshot($table);
            try {
                DB::transaction($action);
                $this->fail('Unproved receipt void mutation accepted.');
            } catch (\Illuminate\Database\QueryException $error) {
                $this->assertStringContainsString('Registered receipt void requires reversal evidence', $error->getMessage());
            }
            foreach ($before as $table => $rows) $this->assertSame($rows, $snapshot($table), $table);
        };
        $update = fn (Payment $doc, array $fields) => DB::table('payments')->where('id', $doc->id)->update($fields);
        $fields = ['voided_at' => now(), 'void_reason' => $reason, 'voided_by' => $reviewer->id, 'voided_via' => 'gated'];
        $unchanged(fn () => $update($first, $fields));
        $unchanged(fn () => $update($first, ['void_reason' => $reason]));
        \App\Models\Setting::set('void_approval_threshold', '1.00');
        $request = $writer->request($issuer, (int) $first->id, $reason, $this->nowAt());
        $this->assertTrue($request['pending']);
        $unchanged(fn () => $update($first, array_replace($fields, ['voided_via' => 'approval'])));
        $approve = fn () => $writer->decide($reviewer, (int) $first->id, true, null, $this->nowAt(),
            '88888888-8888-4888-8888-888888888888', $request['request_id']);
        $this->assertTrue($approve()['registry_created']);
        $this->assertFalse($approve()['registry_created']);
        $unchanged(fn () => $update($first, ['voided_at' => null]));
        $unchanged(fn () => $update($first, ['void_reason' => 'Forged cancellation reason']));
        $unchanged(fn () => $update($first, ['voided_by' => $issuer->id]));
        $unchanged(fn () => $update($first, ['voided_via' => 'gated']));
        app(\App\Services\GatedInvoiceTransferWriter::class)->transfer($reviewer, (int) $second->id, (int) $other->id,
            'Independent documented account transfer', self::EVIDENCE, '99999999-9999-4999-8999-999999999999', $this->nowAt());
        $unchanged(fn () => $update($second, $fields));
        $this->assertFalse($second->fresh()->isVoided());
        $this->assertTrue($first->fresh()->isVoided());
        $ledger = app(\App\Services\TransactionService::class);
        $this->assertSame(0, $ledger->accountBalanceMinor($subscriber));
        $this->assertSame(1000, $ledger->accountBalanceMinor($other));
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
        // Preserve pre-fence corruption injection in this disposable database only.
        DB::unprepared('DROP TRIGGER reg_receipt_request_update');
        try {
            DB::table('payments')->where('id', $legacy->id)->update([
                'void_requested_at' => now(),
                'void_requested_by' => $issuer->id,
                'void_request_reason' => $reason,
            ]);
        } finally {
            app(\App\Services\ReceiptVoidRequestProtection::class)->install();
        }
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

    public function test_source_insert_fence_blocks_direct_documents_and_allows_later_open_period(): void
    {
        ['f' => $f, 'ids' => $ids, 'service' => $service] = $this->mixedAtomicControl();
        $actor = $f['approver'];
        \Laravel\Sanctum\Sanctum::actingAs($actor);
        $service->postMixedResolution($actor, $f['id'], $f['proposalIds'], $ids, self::EVIDENCE, $this->nowAt());
        $this->assertTrue(app(\App\Services\FinancialSourceInsertProtection::class)->active());
        $tables = ['invoices' => 'bill_date', 'payments' => 'payment_date', 'payment_refunds' => 'disbursed_on',
            'treasury_accounts' => 'opening_on', 'treasury_postings' => 'effective_on'];
        $rows = [];
        foreach ($tables as $table => $date) {
            $rows[$table] = array_diff_key((array) DB::table($table)->orderBy('id')->first(), ['id' => true]);
            $this->assertSame('2026-11-25', substr((string) $rows[$table][$date], 0, 10));
        }
        $rows['invoices']['bill_number'] = 'QA-FENCED-INVOICE';
        $rows['payments']['receipt_number'] = 'QA-FENCED-RECEIPT';
        $rows['payments']['sync_uuid'] = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
        $rows['payment_refunds']['disbursement_reference'] = 'QA-FENCED-REFUND';
        $rows['treasury_accounts']['code'] = 'QA-FENCED-ACCOUNT';
        $rows['treasury_accounts']['opening_reference'] = 'QA-FENCED-OPEN';
        $rows['treasury_postings']['evidence_reference'] = 'QA-FENCED-POST';
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toArray(), array_keys($tables));
        $before = $snapshot();
        $periodId = DB::table('financial_periods')->where('month', '2026-11')->value('id');
        DB::table('financial_periods')->where('id', $periodId)->update(['state' => 'FROZEN']);
        DB::table('financial_scopes')->where('scope_key', 'water')->update(['active_freeze_uuid' => self::OTHER]);
        $reject = function (string $table, array $row): void {
            try {
                DB::table($table)->insert($row);
                $this->fail('Source insert unexpectedly accepted: '.$table);
            } catch (\Illuminate\Database\QueryException $error) {
                $this->assertTrue(str_contains($error->getMessage(), 'Financial source period is fenced')
                    || str_contains($error->getMessage(), 'Financial source replacement is fenced'), $error->getMessage());
            }
        };
        foreach ($rows as $table => $row) {
            $reject($table, $row);
            $reject($table, array_replace($row, [$tables[$table] => '2026-12-01']));
        }
        $this->assertEquals($before, $snapshot());
        DB::table('financial_periods')->where('id', $periodId)->update(['state' => 'CLOSED']);
        DB::table('financial_scopes')->where('scope_key', 'water')->update(['active_freeze_uuid' => null]);
        foreach ($rows as $table => $row) $reject($table, $row);
        $this->assertEquals($before, $snapshot());
        DB::table('financial_periods')->insert([
            'scope_key' => 'water', 'month' => '2026-12', 'start_on' => '2026-12-01',
            'next_on' => '2027-01-01', 'state' => 'OPEN', 'version' => 0,
        ]);
        $later = new DateTimeImmutable('2026-12-02T08:30:00Z');
        $this->travelTo(\Carbon\Carbon::instance($later));
        $subscriber = Subscriber::query()->findOrFail($rows['payments']['subscriber_id']);
        $receipt = app(GatedCashReceiptWriter::class)->collect($actor, (int) $subscriber->id, '0.30',
            'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', $later)['payment'];
        $this->assertSame('2026-12-02', $receipt->payment_date->toDateString());
        $this->assertDatabaseHas('financial_posting_operations', ['operation_uuid' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
            'effective_on' => '2026-12-02']);
        $this->assertSame(1, DB::table('payment_refunds')->count());
    }

    public function test_source_mutation_fence_preserves_closed_documents_and_allows_later_collection(): void
    {
        ['f' => $f, 'ids' => $ids, 'service' => $service] = $this->mixedAtomicControl();
        $actor = $f['approver'];
        \Laravel\Sanctum\Sanctum::actingAs($actor);
        $service->postMixedResolution($actor, $f['id'], $f['proposalIds'], $ids, self::EVIDENCE, $this->nowAt());
        $protection = app(\App\Services\FinancialSourceInsertProtection::class);
        $this->assertTrue($protection->active());
        $tables = ['invoices' => 'bill_date', 'payments' => 'payment_date', 'payment_refunds' => 'disbursed_on',
            'treasury_accounts' => 'opening_on', 'treasury_postings' => 'effective_on'];
        $rows = [];
        foreach ($tables as $table => $date) {
            $rows[$table] = DB::table($table)->orderBy('id')->first();
            $this->assertSame('2026-11-25', substr((string) $rows[$table]->$date, 0, 10));
        }
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toArray(), array_keys($tables));
        $before = $snapshot();
        $reject = function (callable $mutation): void {
            try {
                $mutation();
                $this->fail('Fenced financial source mutation unexpectedly accepted');
            } catch (\Illuminate\Database\QueryException $error) {
                $this->assertTrue(str_contains($error->getMessage(), 'Financial source mutation is fenced')
                    || str_contains($error->getMessage(), 'Financial source parent deletion is fenced'), $error->getMessage());
            }
        };
        // Test only, on the freshly migrated disposable database: other
        // BEFORE triggers may reject first on MySQL (creation order), whereas
        // SQLite does not promise the same order. Isolate this fence without
        // weakening the integrated later-collection control.
        $competing = [];
        if (DB::getDriverName() === 'sqlite') {
            foreach (DB::table('sqlite_master')->where('type', 'trigger')->whereIn('tbl_name', array_keys($tables))->get() as $trigger) {
                if (str_starts_with($trigger->name, 'fin_source_') ||
                    ! preg_match('/\bBEFORE\s+(UPDATE|DELETE)\b/i', $trigger->sql)) continue;
                $competing[$trigger->name] = $trigger->sql;
            }
        } else {
            foreach (\App\Services\FinancialTriggerMetadata::query() as $trigger) {
                if (str_starts_with($trigger->TRIGGER_NAME, 'fin_source_') ||
                    ! isset($tables[$trigger->EVENT_OBJECT_TABLE]) || $trigger->ACTION_TIMING !== 'BEFORE' ||
                    ! in_array($trigger->EVENT_MANIPULATION, ['UPDATE', 'DELETE'], true)) continue;
                $competing[$trigger->TRIGGER_NAME] = "CREATE TRIGGER {$trigger->TRIGGER_NAME} BEFORE {$trigger->EVENT_MANIPULATION} ON {$trigger->EVENT_OBJECT_TABLE} FOR EACH ROW {$trigger->ACTION_STATEMENT}";
            }
        }
        $this->assertNotEmpty($competing);
        $isolated = function (callable $check) use ($competing, $protection): void {
            foreach ($competing as $name => $sql) {
                $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_]+$/', $name);
                DB::unprepared('DROP TRIGGER '.$name);
            }
            try {
                $this->assertTrue($protection->active());
                $check();
            } finally {
                foreach ($competing as $sql) DB::unprepared($sql);
            }
        };
        $isolated(function () use ($tables, $rows, $reject, $before, $snapshot): void {
            $periodId = DB::table('financial_periods')->where('month', '2026-11')->value('id');
            DB::table('financial_periods')->where('id', $periodId)->update(['state' => 'FROZEN']);
            DB::table('financial_scopes')->where('scope_key', 'water')->update(['active_freeze_uuid' => self::OTHER]);
            foreach ($tables as $table => $date) {
                $id = $rows[$table]->id;
                $reject(fn () => DB::table($table)->where('id', $id)->update([$date => '2026-12-02']));
                $reject(fn () => DB::table($table)->where('id', $id)->delete());
            }
            $this->assertEquals($before, $snapshot());
            DB::table('financial_periods')->where('id', $periodId)->update(['state' => 'CLOSED']);
            DB::table('financial_scopes')->where('scope_key', 'water')->update(['active_freeze_uuid' => null]);
            DB::table('financial_periods')->insert([
                'scope_key' => 'water', 'month' => '2026-12', 'start_on' => '2026-12-01',
                'next_on' => '2027-01-01', 'state' => 'OPEN', 'version' => 0,
            ]);
            foreach ($tables as $table => $date) {
                $id = $rows[$table]->id;
                $reject(fn () => DB::table($table)->where('id', $id)->update([$date => '2026-12-02']));
                $reject(fn () => DB::table($table)->where('id', $id)->delete());
            }
            $reject(fn () => DB::table('invoices')->where('id', $rows['invoices']->id)->update(['total_amount' => '999.00']));
            $reject(fn () => DB::table('payments')->where('id', $rows['payments']->id)->update(['amount' => '999.00']));
            foreach (['invoices', 'payments'] as $table) {
                $reject(fn () => DB::table($table)->where('id', $rows[$table]->id)->update(['deleted_at' => '2026-12-02 08:30:00']));
            }
            $this->assertEquals($before, $snapshot());
        });
        $later = new DateTimeImmutable('2026-12-02T08:30:00Z');
        $this->travelTo(\Carbon\Carbon::instance($later));
        $subscriber = Subscriber::query()->findOrFail($rows['payments']->subscriber_id);
        $receipt = app(GatedCashReceiptWriter::class)->collect($actor, (int) $subscriber->id, '0.30',
            'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', $later)['payment'];
        $this->assertSame('2026-12-02', $receipt->payment_date->toDateString());
        $after = $snapshot();
        $isolated(fn () => $reject(fn () => DB::table('payments')->where('id', $receipt->id)->update(['payment_date' => '2026-11-25'])));
        $this->assertEquals($after, $snapshot());
        DB::unprepared('DROP TRIGGER fin_source_payments_update');
        $this->assertFalse($protection->active());
        $protection->install();
        $this->assertTrue($protection->active());
    }

    public function test_source_replacement_fence_and_treasury_opening_evidence(): void
    {
        [, , $account] = $this->fixture();
        $table = DB::table('treasury_accounts');
        $id = $account['id'];
        $old = (array) DB::table('treasury_accounts')->where('id', $id)->sole();
        $periodId = DB::table('financial_periods')->where('month', '2026-11')->value('id');
        DB::table('financial_periods')->where('id', $periodId)->update(['state' => 'CLOSED']);
        DB::table('financial_periods')->insert([
            'scope_key' => 'water', 'month' => '2026-12', 'start_on' => '2026-12-01',
            'next_on' => '2027-01-01', 'state' => 'OPEN', 'version' => 0,
        ]);
        $reject = function (string $message, callable $write): void {
            try {
                $write();
                $this->fail('Fenced opening evidence mutation unexpectedly accepted.');
            } catch (\Illuminate\Database\QueryException $error) {
                $this->assertStringContainsString($message, $error->getMessage());
            }
        };
        foreach (['opening_minor' => 9000, 'channel' => 'bank', 'opening_evidence' => 'Altered opening evidence',
            'opened_by' => $this->actor()->id, 'created_at' => '2026-12-02 08:30:00'] as $field => $value) {
            $reject('Treasury opening evidence is fenced', fn () => DB::table('treasury_accounts')->where('id', $id)->update([$field => $value]));
        }
        $this->assertEquals($old, (array) DB::table('treasury_accounts')->where('id', $id)->sole());
        $open = array_replace(array_diff_key($old, ['id' => true]), [
            'code' => 'QA-LATER-ACCOUNT', 'opening_reference' => 'QA-LATER-OPEN', 'opening_on' => '2026-12-02',
        ]);
        $openId = DB::table('treasury_accounts')->insertGetId($open);
        $this->assertSame(1, DB::table('treasury_accounts')->where('id', $openId)->update(['name' => 'Later treasury display name']));
        if (DB::getDriverName() === 'sqlite') {
            $before = $table->orderBy('id')->get()->toArray();
            $replace = function (array $row): void {
                $columns = implode(', ', array_keys($row));
                $params = implode(', ', array_fill(0, count($row), '?'));
                DB::statement("INSERT OR REPLACE INTO treasury_accounts ({$columns}) VALUES ({$params})", array_values($row));
            };
            $this->assertSame(0, (int) DB::selectOne('PRAGMA recursive_triggers')->recursive_triggers);
            $reject('Financial source replacement is fenced', fn () => $replace(array_replace($open, ['id' => $id])));
            $reject('Financial source replacement is fenced', fn () => $replace(array_replace($open, ['code' => $old['code']])));
            $reject('Financial source replacement is fenced', fn () => $replace(array_replace($open, ['opening_reference' => $old['opening_reference']])));
            $reject('Financial source replacement is fenced', fn () => DB::statement('UPDATE OR REPLACE treasury_accounts SET code = ? WHERE id = ?', [$old['code'], $openId]));
            $reject('Financial source replacement is fenced', fn () => DB::statement('UPDATE OR REPLACE treasury_accounts SET opening_reference = ? WHERE id = ?', [$old['opening_reference'], $openId]));
            $this->assertEquals($before, DB::table('treasury_accounts')->orderBy('id')->get()->toArray());
        }
        $this->assertTrue(app(\App\Services\FinancialSourceInsertProtection::class)->active());
    }

    public function test_source_parent_fence_rejects_cascades_and_nullification(): void
    {
        ['f' => $f, 'ids' => $ids, 'service' => $service] = $this->mixedAtomicControl();
        $actor = $f['approver'];
        \Laravel\Sanctum\Sanctum::actingAs($actor);
        $service->postMixedResolution($actor, $f['id'], $f['proposalIds'], $ids, self::EVIDENCE, $this->nowAt());
        $invoice = DB::table('invoices')->orderBy('id')->first();
        $payment = (array) DB::table('payments')->orderBy('id')->first();
        $sessionId = DB::table('cash_sessions')->insertGetId([
            'user_id' => $actor->id, 'session_date' => '2026-11-25',
        ]);
        $copy = array_replace(array_diff_key($payment, ['id' => true]), [
            'subscriber_id' => $invoice->subscriber_id, 'meter_id' => $invoice->meter_id,
            'invoice_id' => $invoice->id, 'user_id' => $actor->id, 'cash_session_id' => $sessionId,
            'receipt_number' => 'QA-PARENT-LINK', 'sync_uuid' => 'abababab-abab-4bab-8bab-abababababab',
        ]);
        DB::table('payments')->insert($copy);
        $parents = ['subscribers' => $invoice->subscriber_id, 'meters' => $invoice->meter_id,
            'invoices' => $invoice->id, 'users' => $actor->id, 'cash_sessions' => $sessionId];
        $this->assertNotNull($parents['meters']);
        $tables = ['invoices', 'payments', 'payment_refunds', 'treasury_accounts', 'treasury_postings'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toArray(), $tables);
        $before = $snapshot();
        $periodId = DB::table('financial_periods')->where('month', '2026-11')->value('id');
        DB::table('financial_periods')->where('id', $periodId)->update(['state' => 'CLOSED']);
        $competing = [];
        if (DB::getDriverName() === 'sqlite') {
            foreach (DB::table('sqlite_master')->where('type', 'trigger')->whereIn('tbl_name', array_keys($parents))->get() as $trigger) {
                if (str_starts_with($trigger->name, 'fin_source_parent_') ||
                    ! preg_match('/\bBEFORE\s+DELETE\b/i', $trigger->sql)) continue;
                $competing[$trigger->name] = $trigger->sql;
            }
        } else {
            foreach (\App\Services\FinancialTriggerMetadata::query() as $trigger) {
                if (str_starts_with($trigger->TRIGGER_NAME, 'fin_source_parent_') ||
                    ! isset($parents[$trigger->EVENT_OBJECT_TABLE]) || $trigger->ACTION_TIMING !== 'BEFORE' ||
                    $trigger->EVENT_MANIPULATION !== 'DELETE') continue;
                $competing[$trigger->TRIGGER_NAME] = "CREATE TRIGGER {$trigger->TRIGGER_NAME} BEFORE DELETE ON {$trigger->EVENT_OBJECT_TABLE} FOR EACH ROW {$trigger->ACTION_STATEMENT}";
            }
        }
        // Isolate only on the disposable fixture, so an earlier parent guard
        // cannot mask the prospective fence. Never change product privileges.
        $this->assertNotEmpty($competing);
        foreach ($competing as $name => $sql) {
            $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_]+$/', $name);
            DB::unprepared('DROP TRIGGER '.$name);
        }
        try {
            foreach ($parents as $table => $id) {
                try {
                    DB::table($table)->where('id', $id)->delete();
                    $this->fail('Parent deletion unexpectedly accepted: '.$table);
                } catch (\Illuminate\Database\QueryException $error) {
                    $this->assertStringContainsString('Financial source parent deletion is fenced', $error->getMessage());
                }
                $this->assertDatabaseHas($table, ['id' => $id]);
            }
            $this->assertEquals($before, $snapshot());
        } finally {
            foreach ($competing as $sql) DB::unprepared($sql);
        }
        $this->assertTrue(app(\App\Services\FinancialSourceInsertProtection::class)->active());
    }

    public function test_held_period_payout_carry_preserves_prior_authorization_and_later_cash_discharge(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        $this->assignHeld($this->actor(), $lot, $subscriber, 20);
        $authorizer = $this->actor();
        $executor = $this->actor();
        $service = app(\App\Services\TreasuryHeldCashLiabilityService::class);
        $this->authorizeHeld($authorizer, $executor, 15);
        $firstId = (int) DB::table('financial_periods')->where('month', '2026-11')->sole()->id;
        $diagnostic = app(\App\Services\FinancialHeldLiabilityPeriodDiagnostic::class);
        $inspect = fn (int $id) => DB::transaction(fn () => $diagnostic->inspect($id));
        $first = $inspect($firstId);
        $this->assertSame(15, $first['totals']['authorized_minor']);
        $this->assertSame(15, $first['totals']['recorded_closing_pending_payout_minor']);
        $this->assertSame(20, $first['totals']['recorded_closing_identified_minor']);
        $this->assertSame(5, $first['totals']['recorded_closing_unidentified_minor']);
        $this->assertSame(0, $first['totals']['paid_minor']);
        $this->assertDatabaseCount('treasury_postings', 1);
        $nextId = DB::table('financial_periods')->insertGetId([
            'scope_key' => 'water', 'month' => '2026-12', 'start_on' => '2026-12-01',
            'next_on' => '2027-01-01', 'state' => 'OPEN', 'version' => 0,
        ]);
        $later = new DateTimeImmutable('2026-12-02T08:30:00Z');
        $this->travelTo(\Carbon\Carbon::instance($later));
        $this->assertTrue($service->cancelPayoutAuthorization($authorizer, self::OTHER, self::EVIDENCE,
            '2026-12-02', $later)['created']);
        $this->assertSame($first, $inspect($firstId));
        $cancelled = $inspect((int) $nextId);
        $this->assertSame(15, $cancelled['totals']['recorded_opening_pending_payout_minor']);
        $this->assertSame(15, $cancelled['totals']['cancelled_authorization_minor']);
        $this->assertSame(0, $cancelled['totals']['recorded_closing_pending_payout_minor']);
        $this->assertSame(20, $cancelled['totals']['recorded_closing_identified_minor']);
        $this->assertSame(0, $cancelled['totals']['paid_minor']);
        $this->assertDatabaseCount('treasury_postings', 1);
        $uuid = '88888888-8888-4888-8888-888888888888';
        $this->assertTrue($service->authorizePayout($authorizer, $executor, self::NEXT, 15,
            'QA-DEC-AUTH', self::EVIDENCE, $uuid, '2026-12-02', $later)['created']);
        $pay = fn () => $service->payout($executor, self::NEXT, 15, 'QA-DEC-PAYOUT', self::EVIDENCE,
            $uuid, '2026-12-02', $later);
        $this->assertTrue($pay()['created']);
        $this->assertFalse($pay()['created']);
        $this->assertSame($first, $inspect($firstId));
        $second = $inspect((int) $nextId);
        $this->assertSame(15, $second['totals']['recorded_opening_pending_payout_minor']);
        $this->assertSame(15, $second['totals']['authorized_minor']);
        $this->assertSame(15, $second['totals']['cancelled_authorization_minor']);
        $this->assertSame(15, $second['totals']['paid_minor']);
        $this->assertSame(0, $second['totals']['recorded_closing_pending_payout_minor']);
        $this->assertSame(5, $second['totals']['recorded_closing_identified_minor']);
        $this->assertSame(5, $second['totals']['recorded_closing_unidentified_minor']);
        $this->assertSame(15, $second['rows'][0]['owners'][0]['recorded_opening_pending_payout_minor']);
        $this->assertSame(0, $second['rows'][0]['owners'][0]['recorded_closing_pending_payout_minor']);
        $this->assertDatabaseCount('treasury_held_cash_payouts', 1);
        $this->assertDatabaseHas('treasury_postings', ['treasury_account_id' => $account['id'],
            'held_cash_payout_uuid' => $uuid, 'amount_minor' => -15, 'effective_on' => '2026-12-02']);
        $this->assertFalse($second['closure_authorized']);
    }

    public function test_close_evidence_rejects_orphan_batch_approval_even_without_any_request(): void
    {
        $actor = $this->actor();
        \Laravel\Sanctum\Sanctum::actingAs($actor);
        $service = app(\App\Services\CompletedCashRefundRequest::class);
        $this->gate(function () use ($actor, $service): void {
            $this->assertSame(0, $service->verifyBatchAuditCoverage()['groups']);
            app(\App\Services\AuditLogger::class)->recordRequired($service::BATCH_APPROVED, [
                'entity_type' => 'completed_cash_refund_request', 'entity_id' => '999999',
                'actor_user_id' => $actor->id, 'source' => 'internal',
                'after' => ['count_id' => 999999, 'incident_count' => 1,
                    'proposal_ids_json' => json_encode(['88888888-8888-4888-8888-888888888888' => 999999], JSON_THROW_ON_ERROR)],
            ]);
        });
        $periodId = (int) DB::table('financial_periods')->where('month', '2026-11')->sole()->id;
        $this->assertSame(0, AuditEvent::query()->where('event', $service::EVENT)->count());
        $this->rejects('completed_refund_batch_conflict', fn () => $this->gate(fn () => $service->verifyBatchAuditCoverage()));
        $this->rejects('completed_refund_batch_conflict', fn () =>
            app(\App\Services\FinancialCloseEvidenceDiagnostic::class)->inspect($actor, $periodId, $this->nowAt()));
        $this->assertSame(1, AuditEvent::query()->where('event', $service::BATCH_APPROVED)->count());
        $this->assertDatabaseCount('payment_refunds', 0);
        $this->assertDatabaseCount('financial_close_commands', 0);
    }

    public function test_held_period_rollforward_preserves_prior_cutoff_and_owner_carry(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 20);
        $firstId = (int) DB::table('financial_periods')->where('month', '2026-11')->sole()->id;
        $diagnostic = app(\App\Services\FinancialHeldLiabilityPeriodDiagnostic::class);
        $inspect = fn (int $id) => DB::transaction(fn () => $diagnostic->inspect($id));
        $first = $inspect($firstId);
        $row = $first['rows'][0];
        $this->assertSame($lot, $row['liability_id']);
        $this->assertSame((int) $account['id'], $row['account_id']);
        $this->assertSame(25, $row['recognized_minor']);
        $this->assertSame(25, $row['assigned_minor']);
        $this->assertSame(20, $row['credited_minor']);
        $this->assertSame(0, $row['paid_minor']);
        $this->assertSame(0, $row['recorded_opening_identified_minor']);
        $this->assertSame(0, $row['recorded_closing_unidentified_minor']);
        $this->assertSame(5, $row['recorded_closing_identified_minor']);
        $this->assertSame([['assignment_uuid' => self::NEXT, 'subscriber_id' => (int) $subscriber->id,
            'recorded_opening_minor' => 0, 'recorded_closing_minor' => 5,
            'recorded_opening_pending_payout_minor' => 0, 'recorded_closing_pending_payout_minor' => 0]], $row['owners']);
        $nextId = DB::table('financial_periods')->insertGetId([
            'scope_key' => 'water', 'month' => '2026-12', 'start_on' => '2026-12-01',
            'next_on' => '2027-01-01', 'state' => 'OPEN', 'version' => 0,
        ]);
        $later = new DateTimeImmutable('2026-12-02T08:30:00Z');
        $this->travelTo(\Carbon\Carbon::instance($later));
        app(\App\Services\TreasuryHeldCashLiabilityService::class)->credit($this->actor(), self::NEXT, 5,
            'QA-NEXT-MONTH-CREDIT', self::EVIDENCE, '88888888-8888-4888-8888-888888888888', '2026-12-02', $later);
        $tables = ['treasury_held_cash_liabilities', 'treasury_held_cash_assignments', 'treasury_held_cash_credits',
            'transactions', 'treasury_postings', 'financial_posting_events', 'audit_events'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->get()->toArray(), $tables);
        $before = $snapshot();
        $this->assertSame($first, $inspect($firstId));
        $second = $inspect((int) $nextId);
        $this->assertSame(5, $second['totals']['recorded_opening_identified_minor']);
        $this->assertSame(0, $second['totals']['recognized_minor']);
        $this->assertSame(0, $second['totals']['assigned_minor']);
        $this->assertSame(5, $second['totals']['credited_minor']);
        $this->assertSame(0, $second['totals']['recorded_closing_identified_minor']);
        $this->assertSame(5, $second['rows'][0]['owners'][0]['recorded_opening_minor']);
        $this->assertSame(0, $second['rows'][0]['owners'][0]['recorded_closing_minor']);
        $this->assertTrue($second['registered_held_subledger_rollforward_verified']);
        $this->assertFalse($second['independent_opening_verified']);
        $this->assertFalse($second['all_liabilities_verified']);
        $this->assertFalse($second['closure_authorized']);
        $this->assertEquals($before, $snapshot());
    }

    public function test_period_movements_preserve_owner_gross_effects_and_do_not_invent_balances(): void
    {
        [, , $account, , $lot, $subscriber] = $this->held();
        $this->assignHeld($this->actor(), $lot, $subscriber, 25);
        $this->creditHeld($this->actor(), 20);
        $issuer = $this->actor();
        $reviewer = $this->actor();
        $bank = app(GatedTreasuryOpeningFundingWriter::class)->open($issuer, [
            'code' => 'QA-MOVEMENT-BANK', 'name' => 'Synthetic bank',
            'channel' => 'bank', 'opening_on' => '2026-11-25', 'opening_amount' => '5.00',
            'opening_reference' => 'QA-MOVEMENT-BANK-OPEN', 'opening_evidence' => self::EVIDENCE,
        ], '55555555-5555-4555-8555-555555555555', $this->nowAt())['account'];
        $receipt = app(GatedCashReceiptWriter::class)->collect($issuer, (int) $subscriber->id, '0.30',
            '66666666-6666-4666-8666-666666666666', $this->nowAt())['payment'];
        $destination = Subscriber::create(['name' => 'Synthetic movement recipient',
            'square_id' => $subscriber->square_id, 'status' => 'active']);
        app(\App\Services\GatedInvoiceTransferWriter::class)->transfer($reviewer, (int) $receipt->id,
            (int) $destination->id, 'Independent documented transfer', self::EVIDENCE,
            '77777777-7777-4777-8777-777777777777', $this->nowAt());
        $tables = ['transactions', 'treasury_postings', 'financial_posting_operations', 'financial_posting_events',
            'audit_events', 'financial_period_closures'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->get()->toArray(), $tables);
        $before = $snapshot();
        $inspect = fn () => $this->gate(fn (array $context) =>
            app(\App\Services\FinancialPeriodMovementDiagnostic::class)->inspect($context['period_id']));
        $result = $inspect();
        $this->assertTrue($result['recorded_movements_verified']);
        $this->assertFalse($result['opening_verified']);
        $this->assertFalse($result['period_closing_verified']);
        $this->assertFalse($result['closure_authorized']);
        $this->assertFalse($result['liability_rollforward_verified']);
        $rows = collect($result['rows'])->keyBy(fn ($row) => $row['sector'].':'.$row['key']);
        $this->assertSame(50, $rows['subscriber:'.$subscriber->id]['incoming_minor']);
        $this->assertSame(30, $rows['subscriber:'.$subscriber->id]['outgoing_minor']);
        $this->assertSame(20, $rows['subscriber:'.$subscriber->id]['net_movement_minor']);
        $this->assertSame(30, $rows['subscriber:'.$destination->id]['net_movement_minor']);
        $this->assertSame(10000, $rows['cash:'.$account['id']]['recorded_opening_document_minor']);
        $this->assertSame(25, $rows['cash:'.$account['id']]['net_movement_minor']);
        $this->assertSame(500, $rows['bank:'.$bank['id']]['recorded_opening_document_minor']);
        $this->assertSame(0, $rows['bank:'.$bank['id']]['net_movement_minor']);
        $this->assertSame(-25, $rows['held_liability_effect:lot-'.$lot]['net_movement_minor']);
        $this->assertSame(5, $rows['held_liability_effect:assignment-'.self::NEXT]['net_movement_minor']);
        $this->assertSame(50, $result['sector_totals']['subscriber']['net_movement_minor']);
        $this->assertSame(-20, $result['sector_totals']['held_liability_effect']['net_movement_minor']);
        foreach ($result['rows'] as $row) {
            $this->assertNull($row['opening_minor']);
            $this->assertNull($row['closing_minor']);
        }
        $this->assertSame(DB::table('financial_posting_events')->count(), $result['registry_events']);
        $this->assertSame($result, $inspect());
        $this->assertEquals($before, $snapshot());
    }

    public function test_period_movements_with_missing_registry_source_publish_no_partial_totals(): void
    {
        [, , , , , $subscriber] = $this->held();
        $receipt = app(PaymentService::class)->collectPayment($subscriber, '0.30');
        $entry = DB::table('transactions')->where('reference_type', Payment::class)
            ->where('reference_id', $receipt->id)->sole();
        $result = $this->gate(fn (array $context) =>
            app(\App\Services\FinancialPeriodMovementDiagnostic::class)->inspect($context['period_id']));
        $this->assertFalse($result['recorded_movements_verified']);
        $this->assertSame([], $result['rows']);
        $this->assertSame([], $result['sector_totals']);
        $this->assertNull($result['movement_hash']);
        $this->assertContains(['code' => 'required_registry_leg_missing_or_duplicate', 'source' => 'ledger_transaction',
            'id' => $entry->id.':subscriber'], $result['source_issues']);
        $this->assertFalse($result['closure_authorized']);
        $this->assertDatabaseHas('payments', ['id' => $receipt->id]);
    }

    public function test_close_evidence_unresolved_cash_claims_remain_visible_until_signed_settlement(): void
    {
        ['f' => $f, 'ids' => $ids, 'keys' => $keys, 'service' => $service] = $this->mixedAtomicControl();
        $actor = $f['approver'];
        \Laravel\Sanctum\Sanctum::actingAs($actor);
        $periodId = (int) DB::table('financial_periods')->where('month', '2026-11')->sole()->id;
        $diagnostic = app(\App\Services\FinancialCloseEvidenceDiagnostic::class);
        $tables = ['financial_scopes', 'financial_periods', 'financial_posting_operations', 'financial_posting_events',
            'payment_refunds', 'transactions', 'treasury_postings', 'audit_events', 'financial_period_closures'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->get()->toArray(), $tables);
        $before = $snapshot();
        $result = $diagnostic->inspect($actor, $periodId, $this->nowAt());
        $this->assertSame(0, $result['refund_batch_coverage']['groups']);
        $this->assertSame(2, $result['refund_incidents']['count']);
        $this->assertSame(10, $result['refund_incidents']['claimed_minor']);
        $this->assertSame(0, $result['refund_incidents']['unposted_cash_refund_minor']);
        $this->assertContains('unresolved_refund_incidents', $result['blockers']);
        $this->assertContains('period_not_ended', $result['blockers']);
        $this->assertFalse($result['closure_authorized']);
        $this->assertEquals($before, $snapshot());
        $this->assertSame($result, $diagnostic->inspect($actor, $periodId, $this->nowAt()));
        $service->postMixedResolution($actor, $f['id'], $f['proposalIds'], $ids, self::EVIDENCE, $this->nowAt());
        $after = $snapshot();
        $resolved = $diagnostic->inspect($actor, $periodId, $this->nowAt());
        $this->assertSame(1, $resolved['refund_batch_coverage']['groups']);
        $this->assertSame(2, $resolved['refund_batch_coverage']['members']);
        $this->assertTrue($resolved['refund_batch_coverage']['batch_audits_verified']);
        $this->assertSame(0, $resolved['refund_incidents']['count']);
        $this->assertSame(0, $resolved['refund_incidents']['claimed_minor']);
        $this->assertNotContains('unresolved_refund_incidents', $resolved['blockers']);
        $this->assertFalse($resolved['source_coverage_verified']);
        $this->assertFalse($resolved['closure_authorized']);
        $this->assertEquals($after, $snapshot());
    }

    public function test_close_evidence_ended_period_never_substitutes_current_balance_or_authorizes_closure(): void
    {
        $actor = $this->actor();
        \Laravel\Sanctum\Sanctum::actingAs($actor);
        $periodId = (int) DB::table('financial_periods')->where('month', '2026-11')->sole()->id;
        $this->travelTo(\Carbon\Carbon::parse('2026-12-01T08:00:00+03:00'));
        $diagnostic = app(\App\Services\FinancialCloseEvidenceDiagnostic::class);
        $result = $diagnostic->inspect($actor, $periodId, now()->toDateTimeImmutable());
        $this->assertTrue($result['period_ended']);
        $this->assertSame('2026-11-30', $result['settlement_through']);
        $this->assertNull($result['current_subscriber_diagnostic']);
        $this->assertNull($result['snapshot_continuity']);
        $this->assertContains('independent_opening_source_unverified', $result['blockers']);
        $this->assertContains('period_end_balances_unverified', $result['blockers']);
        $this->assertContains('complete_writer_coverage_unverified', $result['blockers']);
        $this->assertFalse($result['period_end_balances_verified']);
        $this->assertFalse($result['closure_authorized']);
        $this->assertDatabaseCount('financial_close_commands', 0);
        $this->assertDatabaseCount('financial_period_closures', 0);
        $this->rejects('close_evidence_requires_outer_gate', fn () => DB::transaction(fn () =>
            $diagnostic->inspect($actor, $periodId, now()->toDateTimeImmutable())));
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
        // Create the deliberately legacy synthetic refund before registering its receipt.
        // Once registered, the ordinary refund path must reject; do not weaken that guard.
        $refund = app(\App\Services\PaymentRefundService::class)->record($receipt, $reviewer, '1.00',
            'QA-R140-REFUND', 'treasury_cash', '2026-11-25', 'Independent documented excess credit refund', self::EVIDENCE);
        $posting = app(TreasuryRegisterService::class)->post($reviewer, (int) $account['id'], 'payment_refund', (int) $refund->id);
        app(FinancialWriteGate::class)->withinOpenPeriod('water', '2026-11-24', $this->nowAt(), function (array $context) use ($entry): void {
            $leg = app(\App\Services\FinancialLedgerEvidence::class)->leg((int) $entry->id);
            $this->assertTrue(app(FinancialPostingRegistry::class)->record($context, '66666666-6666-4666-8666-666666666666', [$leg], $this->nowAt()));
        });
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
        // Synthetic fault injection only: setUp recreates the isolated QA database.
        // Keep the independent corruption diagnostic valid even with the new write guard.
        DB::unprepared('DROP TRIGGER reg_invoice_projection_update');
        try {
            DB::table('invoices')->where('id', $invoice->id)->update(['amount_paid' => '0.25']);
        } finally {
            app(\App\Services\InvoiceProjectionProtection::class)->install();
        }
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
