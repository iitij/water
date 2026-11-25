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
use App\Services\GatedClosedCashDuplicateWriter;
use App\Services\AllocationSnapshotIntegrity;
use App\Services\CashSessionService;
use App\Services\FinancialLedgerRegistryVerifier;
use DomainException;
use Spatie\Permission\Models\Role;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class GatedClosedCashDuplicateWriterTest extends TestCase
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

    private function account(User $actor, string $code, string $amount): array
    {
        return app(TreasuryRegisterService::class)->open($actor, [
            'code' => $code, 'name' => 'Synthetic treasury '.$code, 'channel' => 'cash',
            'opening_on' => '2026-11-25', 'opening_amount' => $amount,
            'opening_reference' => $code.'-OPEN',
            'opening_evidence' => 'Independently counted synthetic opening cash',
        ]);
    }

    private const CORRECTION = '33333333-3333-4333-8333-333333333333';
    private const NEXT = '44444444-4444-4444-8444-444444444444';
    private const REASON = 'Synthetic duplicate bookkeeping receipt';
    private const EVIDENCE = 'Independent receipt inspection: only one cash receipt occurred';

    private function fixture(bool $registered = true, bool $approved = true, string $duplicateAmount = '10.25'): array
    {
        $collector = $this->actor();
        $reviewer = $this->actor();
        $subscriber = Subscriber::create(['name' => 'Synthetic duplicate account',
            'square_id' => Square::create(['name' => 'Synthetic duplicate square'])->id, 'status' => 'active']);
        $sessions = app(CashSessionService::class);
        $session = $sessions->open($collector);
        $writer = app(GatedCashReceiptWriter::class);
        $original = $writer->collect($collector, $subscriber->id, '10.25', self::UUID,
            $this->nowAt(), (int) $session->id)['payment'];
        $duplicate = $registered
            ? $writer->collect($collector, $subscriber->id, $duplicateAmount, self::OTHER,
                $this->nowAt(), (int) $session->id)['payment']
            : app(PaymentService::class)->collectPayment($subscriber, $duplicateAmount, userId: $collector->id,
                paymentDate: '2026-11-25', syncUuid: self::OTHER, requestedCashSessionId: (int) $session->id);
        if ($approved) {
            $session = $sessions->decide($sessions->close($session->fresh(), '10.25'), true, $reviewer);
        }
        return [$collector, $reviewer, $subscriber, $original->fresh(), $duplicate->fresh(), $session->fresh()];
    }

    private function post(User $actor, Payment $original, Payment $duplicate, string $uuid = self::CORRECTION,
        string $reason = self::REASON, string $evidence = self::EVIDENCE): array
    {
        return app(GatedClosedCashDuplicateWriter::class)->correct($actor, (int) $duplicate->id,
            (int) $original->id, $reason, $evidence, $uuid, $this->nowAt());
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

    private function allocation(Subscriber $subscriber): array
    {
        $revision = DB::table('payment_allocation_revisions')->where('subscriber_id', $subscriber->id)
            ->orderByDesc('revision')->first();
        $this->assertNotNull($revision);
        $this->assertTrue(app(AllocationSnapshotIntegrity::class)->valid($revision));
        return ['revision' => (array) $revision, 'rows' => DB::table('payment_allocations')
            ->where('revision_id', $revision->id)->orderBy('position')->get()->map(fn ($r) => (array) $r)->all()];
    }

    public function test_actual_duplicate_correction_preserves_approved_count_and_original_receipt(): void
    {
        [, $reviewer, $subscriber, $original, $duplicate, $session] = $this->fixture();
        $originalBytes = $original->getRawOriginal();
        $sessionBytes = $session->getRawOriginal();
        $initial = DB::table('financial_posting_events')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $this->assertSame(2050, $subscriber->fresh()->getAccountBalanceMinor());
        $result = $this->post($reviewer, $original, $duplicate);
        $this->assertTrue($result['registry_created']);
        $this->assertSame(1025, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertSame($originalBytes, $original->fresh()->getRawOriginal());
        $this->assertSame($sessionBytes, $session->fresh()->getRawOriginal());
        $this->assertFalse($original->fresh()->isVoided());
        $this->assertTrue($duplicate->fresh()->isVoided());
        $this->assertSame('closed_correction', $duplicate->fresh()->voided_via);
        $this->assertSame($duplicate->receipt_number, $duplicate->fresh()->receipt_number);
        $summary = app(CashSessionService::class)->summary($session->fresh());
        $this->assertSame('20.50', $summary['expected_amount']);
        $this->assertSame('10.25', $summary['counted_amount']);
        $this->assertSame('-10.25', $summary['variance_amount']);
        $this->assertSame('20.50', $summary['cash_collections']);
        $this->assertSame('10.25', $summary['post_close_correction_total']);
        $this->assertSame('10.25', $summary['treasury_handover']['target_amount']);
        $this->assertSame(2, $summary['receipts']);
        $this->assertSame($initial, DB::table('financial_posting_events')->whereIn('operation_uuid', [self::UUID, self::OTHER])
            ->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => self::CORRECTION,
            'source_id' => (string) $result['reversal_id'], 'amount_minor' => -1025]);
        $allocation = $this->allocation($subscriber);
        $this->assertSame(1025, (int) $allocation['revision']['unallocated_minor']);
        $this->assertCount(1, $allocation['rows']);
        $this->assertSame((int) $original->id, (int) $allocation['rows'][0]['payment_id']);
        $again = $this->post($reviewer, $original, $duplicate);
        $this->assertFalse($again['registry_created']);
        $this->assertSame($result['reversal_id'], $again['reversal_id']);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('transactions', 3);
        $this->assertDatabaseCount('closed_cash_corrections', 1);
        $this->assertDatabaseCount('payment_refunds', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertDatabaseCount('financial_posting_events', 3);
        $this->assertSame(1, AuditEvent::query()->where('event', 'cash_session.duplicate_corrected')->count());
        $this->gate(function (array $context): void {
            $verified = app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']);
            $this->assertSame(3, $verified['registered_ledger_legs_verified']);
            $this->assertFalse($verified['source_coverage_verified']);
        });
    }

    public function test_identity_authority_evidence_and_exact_replay_conflicts(): void
    {
        [$collector, $reviewer, $subscriber, $original, $duplicate] = $this->fixture();
        $this->rejects('gated_duplicate_identity', fn () => $this->post($reviewer, $duplicate, $original));
        $this->rejects('gated_duplicate_evidence_required',
            fn () => $this->post($reviewer, $original, $duplicate, evidence: ''));
        try {
            $this->post($collector, $original, $duplicate);
            $this->fail('Self approval accepted.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertSame(2050, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertTrue($this->post($reviewer, $original, $duplicate)['registry_created']);
        $this->rejects('gated_duplicate_operation_conflict',
            fn () => $this->post($reviewer, $original, $duplicate, self::NEXT));
        $this->rejects('gated_duplicate_operation_conflict',
            fn () => $this->post($reviewer, $original, $duplicate, evidence: 'Changed independent inspection evidence'));
        $this->assertSame(1025, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('closed_cash_corrections', 1);
        $this->assertDatabaseCount('transactions', 3);
    }

    public function test_unregistered_duplicate_is_not_adopted(): void
    {
        [, $reviewer, $subscriber, $original, $duplicate] = $this->fixture(registered: false);
        $this->rejects('gated_duplicate_prerequisite_unregistered',
            fn () => $this->post($reviewer, $original, $duplicate));
        $this->assertSame(2050, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertFalse($duplicate->fresh()->isVoided());
        $this->assertDatabaseCount('closed_cash_corrections', 0);
        $this->assertDatabaseCount('financial_posting_events', 1);
    }

    public function test_different_receipt_amounts_are_not_corrected(): void
    {
        [, $reviewer, $subscriber, $original, $duplicate] = $this->fixture(duplicateAmount: '10.26');
        $this->rejects('gated_duplicate_receipt_unsupported', fn () => $this->post($reviewer, $original, $duplicate));
        $this->assertSame(2051, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertFalse($duplicate->fresh()->isVoided());
        $this->assertDatabaseCount('closed_cash_corrections', 0);
        $this->assertDatabaseCount('financial_posting_events', 2);
    }

    public function test_unapproved_till_closed_period_and_frozen_scope_are_rejected(): void
    {
        [, $reviewer, $subscriber, $original, $duplicate, $session] = $this->fixture(approved: false);
        $post = fn () => $this->post($reviewer, $original, $duplicate);
        $this->rejects('gated_duplicate_till_unverified', $post);
        $sessions = app(CashSessionService::class);
        $closed = $sessions->close($session, '10.25');
        $this->rejects('gated_duplicate_till_unverified', $post);
        $sessions->decide($closed, true, $reviewer);
        DB::table('financial_periods')->update(['state' => 'CLOSED']);
        $this->rejects('financial_period_not_open', $post);
        DB::table('financial_periods')->update(['state' => 'OPEN']);
        DB::table('financial_scopes')->update(['active_freeze_uuid' => self::CORRECTION]);
        $this->rejects('financial_scope_not_active', $post);
        $this->assertSame(2050, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertFalse($duplicate->fresh()->isVoided());
        $this->assertDatabaseCount('closed_cash_corrections', 0);
        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseCount('financial_posting_events', 2);
    }

    public function test_registry_failure_restores_duplicate_allocation_and_audit_then_retries(): void
    {
        [, $reviewer, $subscriber, $original, $duplicate, $session] = $this->fixture();
        $before = $this->allocation($subscriber);
        $originalBytes = $original->getRawOriginal();
        $duplicateBytes = $duplicate->getRawOriginal();
        $sessionBytes = $session->getRawOriginal();
        $auditCount = AuditEvent::query()->count();
        $revisions = DB::table('payment_allocation_revisions')->count();
        $rows = DB::table('payment_allocations')->count();
        $trigger = DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER qa_registry_failure BEFORE INSERT ON financial_posting_events BEGIN SELECT RAISE(ABORT, 'synthetic_registry_failure'); END"
            : "CREATE TRIGGER qa_registry_failure BEFORE INSERT ON financial_posting_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic_registry_failure'";
        DB::unprepared($trigger);
        try {
            $this->post($reviewer, $original, $duplicate);
            $this->fail('Registry insert should fail.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('synthetic_registry_failure', $error->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS qa_registry_failure');
        }
        $this->assertSame(2050, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertSame($before, $this->allocation($subscriber));
        $this->assertSame($originalBytes, $original->fresh()->getRawOriginal());
        $this->assertSame($duplicateBytes, $duplicate->fresh()->getRawOriginal());
        $this->assertSame($sessionBytes, $session->fresh()->getRawOriginal());
        $this->assertSame($auditCount, AuditEvent::query()->count());
        $this->assertDatabaseCount('payment_allocation_revisions', $revisions);
        $this->assertDatabaseCount('payment_allocations', $rows);
        $this->assertDatabaseCount('closed_cash_corrections', 0);
        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseCount('financial_posting_operations', 2);
        $this->assertDatabaseCount('financial_posting_events', 2);
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertTrue($this->post($reviewer, $original, $duplicate)['registry_created']);
        $this->assertSame(1025, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertSame($sessionBytes, $session->fresh()->getRawOriginal());
        $this->assertDatabaseCount('closed_cash_corrections', 1);
        $this->assertDatabaseCount('financial_posting_events', 3);
    }
}
