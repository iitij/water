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
use App\Models\CashSession;
use App\Services\GatedCountedCashWriter;
use App\Services\LateReceiptReviewService;
use App\Services\CashSessionService;
use App\Services\FinancialLedgerRegistryVerifier;
use DomainException;
use Spatie\Permission\Models\Role;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class GatedCountedCashWriterTest extends TestCase
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

    private const SECOND = '33333333-3333-4333-8333-333333333333';
    private const THIRD = '44444444-4444-4444-8444-444444444444';
    private const EVIDENCE = 'Independent documented attribution of already counted cash';

    private function subscriber(string $name): Subscriber
    {
        return Subscriber::create(['name' => 'Synthetic '.$name,
            'square_id' => Square::create(['name' => 'Synthetic square '.$name])->id, 'status' => 'active']);
    }

    private function fixture(string $counted = '15.25', bool $approved = true, bool $registered = true): array
    {
        $collector = $this->actor();
        $reviewer = $this->actor();
        $original = $this->subscriber('original');
        $sessions = app(CashSessionService::class);
        $session = $sessions->open($collector);
        $receipt = $registered
            ? app(GatedCashReceiptWriter::class)->collect($collector, (int) $original->id,
                '5.00', self::UUID, $this->nowAt(), (int) $session->id)['payment']
            : app(PaymentService::class)->collectPayment($original, '5.00', userId: $collector->id,
                paymentDate: '2026-11-25', syncUuid: self::UUID, requestedCashSessionId: (int) $session->id);
        $session = $sessions->close($session->fresh(), $counted);
        if ($approved) $session = $sessions->decide($session, true, $reviewer);
        return [$collector, $reviewer, $session->fresh(), $original, $receipt->fresh()];
    }

    private function claim(User $collector, CashSession $session, Subscriber $subscriber, string $uuid, string $amount): array
    {
        return app(LateReceiptReviewService::class)->submit($collector, [
            'sync_uuid' => $uuid, 'cash_session_id' => $session->id, 'subscriber_id' => $subscriber->id,
            'payment_method' => 'cash', 'amount' => $amount, 'payment_date' => '2026-11-25',
        ]);
    }

    private function post(User $actor, array $review, string $reference = 'QA-COUNTED-01',
        string $date = '2026-11-25', string $evidence = self::EVIDENCE): array
    {
        return app(GatedCountedCashWriter::class)->allocate($actor, (int) $review['id'], $date,
            $reference, $evidence, $review['sync_uuid'], $this->nowAt());
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

    public function test_exact_capacity_across_subscribers_and_replay_after_later_allocation(): void
    {
        [$collector, $reviewer, $session, $original, $receipt] = $this->fixture();
        $a = $this->subscriber('A');
        $b = $this->subscriber('B');
        $c = $this->subscriber('C');
        // Submission order differs from decision order.
        $second = $this->claim($collector, $session, $b, self::SECOND, '6.13');
        $first = $this->claim($collector, $session, $a, self::OTHER, '4.12');
        $excess = $this->claim($collector, $session, $c, self::THIRD, '0.01');
        $sessionBytes = $session->getRawOriginal();
        $receiptBytes = $receipt->getRawOriginal();
        $one = $this->post($reviewer, $first);
        $this->assertTrue($one['registry_created']);
        $this->assertSame(1025, $one['allocation']['capacity_minor']);
        $this->assertSame(412, $one['allocation']['allocated_minor']);
        $this->assertSame(613, $one['allocation']['available_minor']);
        $two = $this->post($reviewer, $second, 'QA-COUNTED-02');
        $this->assertTrue($two['registry_created']);
        $this->assertSame(1025, $two['allocation']['allocated_minor']);
        $this->assertSame(0, $two['allocation']['available_minor']);
        $again = $this->post($reviewer, $first);
        $this->assertFalse($again['registry_created']);
        $this->assertSame($one['transaction_id'], $again['transaction_id']);
        $this->rejects('gated_counted_capacity_exceeded', fn () => $this->post($reviewer, $excess, 'QA-COUNTED-03'));
        $this->assertSame(412, $a->fresh()->getAccountBalanceMinor());
        $this->assertSame(613, $b->fresh()->getAccountBalanceMinor());
        $this->assertSame(0, $c->fresh()->getAccountBalanceMinor());
        $this->assertSame(500, $original->fresh()->getAccountBalanceMinor());
        $this->assertSame($sessionBytes, $session->fresh()->getRawOriginal());
        $this->assertSame($receiptBytes, $receipt->fresh()->getRawOriginal());
        $this->assertDatabaseHas('late_receipt_reviews', ['id' => $excess['id'], 'status' => 'pending', 'payment_id' => null]);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => self::OTHER, 'source_type' => 'ledger_transaction', 'amount_minor' => 412]);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => self::SECOND, 'source_type' => 'ledger_transaction', 'amount_minor' => 613]);
        foreach ([$one, $two] as $result) $this->assertNull(Payment::findOrFail($result['review']['payment_id'])->cash_session_id);
        $this->assertDatabaseCount('payments', 3);
        $this->assertDatabaseCount('transactions', 3);
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertDatabaseCount('treasury_late_cash_receipts', 0);
        $this->assertDatabaseCount('financial_posting_events', 3);
        $this->assertSame(2, AuditEvent::query()->where('event', 'financial.counted_cash_allocated')->count());
        $this->gate(function (array $context): void {
            $v = app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']);
            $this->assertSame(3, $v['registered_ledger_legs_verified']);
            $this->assertFalse($v['source_coverage_verified']);
        });
    }

    public function test_prior_legacy_allocation_cannot_be_adopted(): void
    {
        [$collector, $reviewer, $session] = $this->fixture();
        $first = $this->claim($collector, $session, $this->subscriber('A'), self::OTHER, '4.12');
        $second = $this->claim($collector, $session, $this->subscriber('B'), self::SECOND, '6.13');
        app(LateReceiptReviewService::class)->decide((int) $first['id'], $reviewer, [
            'approve' => true, 'settlement_method' => 'already_counted', 'evidence_reference' => 'QA-LEGACY',
            'evidence' => self::EVIDENCE,
        ]);
        $this->rejects('gated_counted_prerequisite_unregistered', fn () => $this->post($reviewer, $second));
        $this->assertDatabaseCount('financial_posting_events', 1);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseHas('late_receipt_reviews', ['id' => $second['id'], 'status' => 'pending']);
    }

    public function test_tampered_prior_allocation_proof_blocks_next_allocation(): void
    {
        [$collector, $reviewer, $session] = $this->fixture();
        $first = $this->claim($collector, $session, $this->subscriber('A'), self::OTHER, '4.12');
        $second = $this->claim($collector, $session, $this->subscriber('B'), self::SECOND, '6.13');
        $this->post($reviewer, $first);
        $proof = AuditEvent::query()->where('event', 'financial.counted_cash_allocated')->sole();
        $data = $proof->after;
        $data['allocated_after_minor'] = 411;
        DB::table('audit_events')->where('id', $proof->id)->update(['after' => json_encode($data, JSON_THROW_ON_ERROR)]);
        $this->rejects('gated_counted_proof_mismatch', fn () => $this->post($reviewer, $second, 'QA-COUNTED-02'));
        $this->assertDatabaseCount('financial_posting_events', 2);
        $this->assertDatabaseHas('late_receipt_reviews', ['id' => $second['id'], 'status' => 'pending']);
    }

    public function test_unregistered_original_till_receipt_blocks_capacity_proof(): void
    {
        [$collector, $reviewer, $session] = $this->fixture(registered: false);
        $claim = $this->claim($collector, $session, $this->subscriber('A'), self::OTHER, '4.12');
        $this->rejects('gated_counted_prerequisite_unregistered', fn () => $this->post($reviewer, $claim));
        $this->assertDatabaseCount('financial_posting_events', 0);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_no_surplus_or_wrong_effective_date_cannot_post(): void
    {
        [$collector, $reviewer, $session] = $this->fixture(counted: '5.00');
        $claim = $this->claim($collector, $session, $this->subscriber('A'), self::OTHER, '0.01');
        $this->rejects('gated_counted_effective_date', fn () => $this->post($reviewer, $claim, date: '2026-11-24'));
        $this->rejects('gated_counted_no_surplus', fn () => $this->post($reviewer, $claim));
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('financial_posting_events', 1);
    }

    public function test_authority_unapproved_till_and_closed_frozen_period(): void
    {
        [$collector, $reviewer, $session] = $this->fixture(approved: false);
        $claim = $this->claim($collector, $session, $this->subscriber('A'), self::OTHER, '4.12');
        try {
            $this->post($collector, $claim);
            $this->fail('Self approval accepted.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->rejects('gated_counted_session_unverified', fn () => $this->post($reviewer, $claim));
        app(CashSessionService::class)->decide($session, true, $reviewer);
        DB::table('financial_periods')->update(['state' => 'CLOSED']);
        $this->rejects('financial_period_not_open', fn () => $this->post($reviewer, $claim));
        DB::table('financial_periods')->update(['state' => 'OPEN']);
        DB::table('financial_scopes')->update(['active_freeze_uuid' => self::OTHER]);
        $this->rejects('financial_scope_not_active', fn () => $this->post($reviewer, $claim));
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertDatabaseHas('late_receipt_reviews', ['id' => $claim['id'], 'status' => 'pending']);
    }

    public function test_registry_failure_restores_capacity_claim_ledger_and_audit(): void
    {
        [$collector, $reviewer, $session] = $this->fixture();
        $subscriber = $this->subscriber('A');
        $claim = $this->claim($collector, $session, $subscriber, self::OTHER, '10.25');
        $snapshot = app(LateReceiptReviewService::class)->countedCashAllocation($session);
        $audit = AuditEvent::query()->count();
        $before = (array) DB::table('late_receipt_reviews')->find($claim['id']);
        $revisions = DB::table('payment_allocation_revisions')->count();
        $rows = DB::table('payment_allocations')->count();
        $trigger = DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER qa_registry_failure BEFORE INSERT ON financial_posting_events BEGIN SELECT RAISE(ABORT, 'synthetic_registry_failure'); END"
            : "CREATE TRIGGER qa_registry_failure BEFORE INSERT ON financial_posting_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic_registry_failure'";
        DB::unprepared($trigger);
        try {
            $this->post($reviewer, $claim);
            $this->fail('Registry insert should fail.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('synthetic_registry_failure', $error->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS qa_registry_failure');
        }
        $this->assertSame($snapshot, app(LateReceiptReviewService::class)->countedCashAllocation($session->fresh()));
        $this->assertSame($before, (array) DB::table('late_receipt_reviews')->find($claim['id']));
        $this->assertSame(0, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertSame($audit, AuditEvent::query()->count());
        $this->assertDatabaseCount('payment_allocation_revisions', $revisions);
        $this->assertDatabaseCount('payment_allocations', $rows);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('financial_posting_events', 1);
        $result = $this->post($reviewer, $claim);
        $this->assertTrue($result['registry_created']);
        $this->assertSame(0, $result['allocation']['available_minor']);
        $this->assertSame(1025, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertDatabaseCount('financial_posting_events', 2);
    }
}
