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

    private function post(User $actor, array $count, string $classification = 'confirmed_cash_loss',
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
        $result = $this->post($reviewer, $count);
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
        $again = $this->post($reviewer, $count);
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
        $this->rejects('gated_cash_count_classification', fn () => $this->post($reviewer, $count));
        $result = $this->post($reviewer, $count, 'unattributed_cash_held');
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
            $this->post($counter, $count);
            $this->fail('Self approval accepted.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->rejects('gated_cash_count_classification', fn () => $this->post($reviewer, $count));
        $this->rejects('gated_cash_count_classification', fn () => $this->post($reviewer, $count, 'unattributed_cash_held'));
        $this->assertDatabaseCount('treasury_cash_adjustments', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
    }

    public function test_pending_and_rejected_observations_do_not_post(): void
    {
        [, $reviewer, , $count] = $this->fixture(accepted: null);
        $this->rejects('gated_cash_count_review_required', fn () => $this->post($reviewer, $count));
        app(TreasuryCashCountService::class)->review($reviewer, (int) $count['id'], false,
            'Independent review rejected the observation');
        $this->rejects('gated_cash_count_review_required', fn () => $this->post($reviewer, $count));
        $this->assertDatabaseCount('treasury_cash_adjustments', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
    }

    public function test_unregistered_opening_is_not_adopted(): void
    {
        [, $reviewer, , $count] = $this->fixture(registered: false);
        $this->rejects('gated_cash_count_prerequisite_unregistered', fn () => $this->post($reviewer, $count));
        $this->assertDatabaseCount('treasury_cash_adjustments', 0);
        $this->assertDatabaseCount('financial_posting_events', 0);
    }

    public function test_unregistered_preceding_funding_cannot_be_treated_as_a_loss(): void
    {
        [, $reviewer, , $count] = $this->fixture('94.75', funded: true, registeredFunding: false);
        $this->rejects('gated_cash_count_prior_unregistered', fn () => $this->post($reviewer, $count));
        $this->assertDatabaseCount('treasury_cash_adjustments', 0);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertDatabaseCount('financial_posting_events', 1);
    }

    public function test_changed_snapshot_after_review_blocks_adjustment(): void
    {
        [$counter, $reviewer, $account, $count] = $this->fixture();
        app(GatedTreasuryOpeningFundingWriter::class)->fund($this->actor(), $counter, (int) $account['id'],
            '5.00', 'QA-COUNT-LATER', 'Independent physical funding after count', self::OTHER, $this->nowAt());
        $this->rejects('gated_cash_count_snapshot_mismatch', fn () => $this->post($reviewer, $count));
        $this->assertDatabaseCount('treasury_cash_adjustments', 0);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertDatabaseCount('financial_posting_events', 2);
    }

    public function test_closed_frozen_and_tampered_observation_are_rejected(): void
    {
        [, $reviewer, , $count] = $this->fixture();
        $post = fn () => $this->post($reviewer, $count);
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
        $this->rejects('gated_cash_count_operation_conflict', fn () => $this->post($reviewer, $count));
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
            $this->post($reviewer, $count);
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
        $this->assertTrue($this->post($reviewer, $count)['registry_created']);
        $this->rejects('gated_cash_count_operation_conflict', fn () => $this->post($reviewer, $count, uuid: self::NEXT));
        $this->assertDatabaseCount('treasury_cash_adjustments', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertDatabaseCount('financial_posting_events', 2);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 0);
    }
    private function held(): array
    {
        [$counter, $reviewer, $account, $count] = $this->fixture('100.25');
        $result = $this->post($reviewer, $count, 'unattributed_cash_held');
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
        $this->assertSame($lot, $this->post($reviewer, $count, 'unattributed_cash_held')['held_liability_id']);
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
        DB::table('treasury_held_cash_assignments')->where('operation_uuid', self::NEXT)->update(['amount_minor' => 19]);
        $this->rejects('held_cash_registry_mismatch', $verify);
        DB::table('treasury_held_cash_assignments')->where('operation_uuid', self::NEXT)->delete();
        $this->rejects('held_cash_coverage_orphan_event', $verify);
    }

    public function test_held_cash_recognition_failure_rolls_back_cash_and_registry(): void
    {
        [, $reviewer, , $count] = $this->fixture('100.25');
        $trigger = DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER qa_held_failure BEFORE INSERT ON treasury_held_cash_liabilities BEGIN SELECT RAISE(ABORT, 'synthetic_held_failure'); END"
            : "CREATE TRIGGER qa_held_failure BEFORE INSERT ON treasury_held_cash_liabilities FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic_held_failure'";
        DB::unprepared($trigger);
        try {
            $this->post($reviewer, $count, 'unattributed_cash_held');
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
        $this->assertTrue($this->post($reviewer, $count, 'unattributed_cash_held')['registry_created']);
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

}
