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
use App\Services\GatedLateCashWriter;
use App\Services\LateReceiptReviewService;
use App\Services\GatedTreasuryOpeningFundingWriter;
use App\Services\CashSessionService;
use App\Services\FinancialLedgerRegistryVerifier;
use DomainException;
use Spatie\Permission\Models\Role;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class GatedLateCashWriterTest extends TestCase
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

    private const EVIDENCE = 'Independent verified separate physical cash receipt';

    private function fixture(bool $registered = true, string $day = '2026-11-25',
        string $counted = '0.00', bool $approved = true): array
    {
        $collector = $this->actor();
        $reviewer = $this->actor();
        $subscriber = Subscriber::create(['name' => 'Synthetic late cash account',
            'square_id' => Square::create(['name' => 'Synthetic late cash square'])->id, 'status' => 'active']);
        $data = ['code' => 'QA-LATE-CASH', 'name' => 'Synthetic cash account', 'channel' => 'cash',
            'opening_on' => '2026-11-24', 'opening_amount' => '0.00',
            'opening_reference' => 'QA-LATE-OPEN', 'opening_evidence' => 'Independent original opening evidence'];
        $account = $registered
            ? app(GatedTreasuryOpeningFundingWriter::class)->open($reviewer, $data, self::UUID, $this->nowAt())['account']
            : app(TreasuryRegisterService::class)->open($reviewer, $data);
        $this->travelTo(\Carbon\Carbon::parse($day.'T06:00:00Z'));
        $sessions = app(CashSessionService::class);
        $session = $sessions->close($sessions->open($collector), $counted);
        if ($approved) $session = $sessions->decide($session, true, $reviewer);
        $this->travelTo(\Carbon\Carbon::instance($this->nowAt()));
        $review = app(LateReceiptReviewService::class)->submit($collector, [
            'sync_uuid' => self::OTHER, 'cash_session_id' => $session->id, 'subscriber_id' => $subscriber->id,
            'payment_method' => 'cash', 'amount' => '10.25', 'payment_date' => $day,
        ]);
        return [$collector, $reviewer, $subscriber, $account, $session->fresh(), $review];
    }

    private function post(User $actor, array $review, array $account, string $date = '2026-11-25',
        string $uuid = self::OTHER, string $evidence = self::EVIDENCE): array
    {
        return app(GatedLateCashWriter::class)->receive($actor, (int) $review['id'], (int) $account['id'],
            $date, 'QA-LATE-RECEIPT', $evidence, $uuid, $this->nowAt());
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

    private function assertPending(Subscriber $subscriber, array $review, int $events = 1): void
    {
        $this->assertDatabaseHas('late_receipt_reviews', ['id' => $review['id'], 'status' => 'pending', 'payment_id' => null]);
        $this->assertSame(0, $subscriber->fresh()->getAccountBalanceMinor());
        foreach (['payments', 'transactions', 'treasury_late_cash_receipts', 'treasury_postings'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('financial_posting_events', $events);
    }

    public function test_same_day_separate_receipt_posts_pair_and_preserves_approved_till(): void
    {
        [, $reviewer, $subscriber, $account, $session, $review] = $this->fixture();
        $till = $session->getRawOriginal();
        $summary = app(CashSessionService::class)->summary($session);
        $this->assertPending($subscriber, $review);
        $result = $this->post($reviewer, $review, $account);
        $this->assertTrue($result['registry_created']);
        $payment = Payment::findOrFail($result['review']['payment_id']);
        $this->assertSame('10.25', $payment->amount);
        $this->assertNull($payment->cash_session_id);
        $this->assertSame('2026-11-25', $payment->payment_date->toDateString());
        $this->assertSame('separate_handover', $result['review']['settlement_method']);
        $this->assertSame('2026-11-25', $result['posting']['effective_on']);
        $this->assertSame(1025, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertSame(1025, (int) $result['posting']['amount_minor']);
        $this->assertSame($till, $session->fresh()->getRawOriginal());
        $this->assertEquals($summary, app(CashSessionService::class)->summary($session->fresh()));
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => self::OTHER,
            'source_type' => 'ledger_transaction', 'source_id' => (string) $result['transaction_id'], 'amount_minor' => 1025]);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => self::OTHER,
            'source_type' => 'treasury_posting', 'source_id' => (string) $result['posting']['id'], 'amount_minor' => 1025]);
        $again = $this->post($reviewer, $review, $account);
        $this->assertFalse($again['registry_created']);
        $this->assertSame($result['transaction_id'], $again['transaction_id']);
        $this->assertSame($result['posting']['id'], $again['posting']['id']);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('treasury_late_cash_receipts', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertDatabaseCount('financial_posting_events', 3);
        $this->gate(function (array $context): void {
            $v = app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']);
            $this->assertSame(1, $v['registered_ledger_legs_verified']);
            $this->assertSame(1, $v['registered_treasury_legs_verified']);
            $this->assertFalse($v['source_coverage_verified']);
        });
    }

    public function test_distinct_effective_dates_are_held_without_backdating(): void
    {
        [, $reviewer, $subscriber, $account, , $review] = $this->fixture(day: '2026-11-24');
        $this->rejects('gated_late_cash_distinct_dates_unsupported', fn () => $this->post($reviewer, $review, $account));
        $this->assertPending($subscriber, $review);
    }

    public function test_positive_counted_surplus_requires_separate_allocation_review(): void
    {
        [, $reviewer, $subscriber, $account, , $review] = $this->fixture(counted: '10.25');
        $this->rejects('gated_late_cash_counted_surplus_requires_review', fn () => $this->post($reviewer, $review, $account));
        $this->assertPending($subscriber, $review);
    }

    public function test_self_approval_uuid_and_tampered_submission_rejected(): void
    {
        [$collector, $reviewer, $subscriber, $account, , $review] = $this->fixture();
        try {
            $this->post($collector, $review, $account);
            $this->fail('Self approval accepted.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->rejects('gated_late_cash_identity', fn () => $this->post($reviewer, $review, $account, uuid: self::UUID));
        DB::table('late_receipt_reviews')->where('id', $review['id'])->update(['amount' => '10.26']);
        $this->rejects('gated_late_cash_submission_mismatch', fn () => $this->post($reviewer, $review, $account));
        $this->assertPending($subscriber, $review);
    }

    public function test_unregistered_opening_is_not_adopted(): void
    {
        [, $reviewer, $subscriber, $account, , $review] = $this->fixture(registered: false);
        $this->rejects('gated_late_cash_prerequisite_unregistered', fn () => $this->post($reviewer, $review, $account));
        $this->assertPending($subscriber, $review, 0);
    }

    public function test_unapproved_till_closed_and_frozen_periods_are_protected(): void
    {
        [, $reviewer, $subscriber, $account, $session, $review] = $this->fixture(approved: false);
        $post = fn () => $this->post($reviewer, $review, $account);
        $this->rejects('gated_late_cash_session_unverified', $post);
        app(CashSessionService::class)->decide($session, true, $reviewer);
        DB::table('financial_periods')->update(['state' => 'CLOSED']);
        $this->rejects('financial_period_not_open', $post);
        DB::table('financial_periods')->update(['state' => 'OPEN']);
        DB::table('financial_scopes')->update(['active_freeze_uuid' => self::OTHER]);
        $this->rejects('financial_scope_not_active', $post);
        $this->assertPending($subscriber, $review);
    }

    public function test_legacy_separate_receipt_cannot_be_adopted(): void
    {
        [, $reviewer, , $account, , $review] = $this->fixture();
        app(LateReceiptReviewService::class)->receiveCash((int) $review['id'], $reviewer, (int) $account['id'],
            '2026-11-25', 'QA-LATE-RECEIPT', self::EVIDENCE);
        $this->rejects('gated_late_cash_operation_conflict', fn () => $this->post($reviewer, $review, $account));
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertDatabaseCount('financial_posting_events', 1);
    }

    public function test_second_registry_leg_failure_rolls_back_both_effects_and_allocation(): void
    {
        [, $reviewer, $subscriber, $account, $session, $review] = $this->fixture();
        $till = $session->getRawOriginal();
        $before = (array) DB::table('late_receipt_reviews')->find($review['id']);
        $audit = AuditEvent::query()->count();
        $revisions = DB::table('payment_allocation_revisions')->count();
        $rows = DB::table('payment_allocations')->count();
        $trigger = DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER qa_registry_failure BEFORE INSERT ON financial_posting_events WHEN NEW.source_type = 'treasury_posting' BEGIN SELECT RAISE(ABORT, 'synthetic_registry_failure'); END"
            : "CREATE TRIGGER qa_registry_failure BEFORE INSERT ON financial_posting_events FOR EACH ROW BEGIN IF NEW.source_type = 'treasury_posting' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic_registry_failure'; END IF; END";
        DB::unprepared($trigger);
        try {
            $this->post($reviewer, $review, $account);
            $this->fail('Registry insert should fail.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('synthetic_registry_failure', $error->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS qa_registry_failure');
        }
        $this->assertPending($subscriber, $review);
        $this->assertSame($before, (array) DB::table('late_receipt_reviews')->find($review['id']));
        $this->assertSame($till, $session->fresh()->getRawOriginal());
        $this->assertSame($audit, AuditEvent::query()->count());
        $this->assertDatabaseCount('payment_allocation_revisions', $revisions);
        $this->assertDatabaseCount('payment_allocations', $rows);
        $this->assertDatabaseCount('financial_posting_operations', 1);
        $this->assertTrue($this->post($reviewer, $review, $account)['registry_created']);
        $this->assertSame(1025, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('financial_posting_events', 3);
    }
}
