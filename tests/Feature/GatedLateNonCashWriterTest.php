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
use App\Services\GatedLateNonCashWriter;
use App\Services\LateReceiptReviewService;
use App\Services\BankReconciliationService;
use App\Services\GatedTreasuryOpeningFundingWriter;
use App\Services\CashSessionService;
use App\Services\FinancialLedgerRegistryVerifier;
use DomainException;
use Spatie\Permission\Models\Role;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class GatedLateNonCashWriterTest extends TestCase
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

    private const EVIDENCE = 'Independent original proof of bank clearance';

    private function fixture(string $method = 'check', string $bankAmount = '10.25', bool $registered = true): array
    {
        $submitter = $this->actor();
        $importer = $this->actor();
        $reviewer = $this->actor();
        $subscriber = Subscriber::create(['name' => 'Synthetic claim account',
            'square_id' => Square::create(['name' => 'Synthetic claim square'])->id, 'status' => 'active']);
        $data = ['code' => 'QA-CLAIM', 'name' => 'Synthetic bank', 'channel' => 'bank',
            'opening_on' => '2026-11-24', 'opening_amount' => '0.00',
            'opening_reference' => 'QA-CLAIM-OPEN', 'opening_evidence' => 'Independent original opening evidence'];
        $account = $registered
            ? app(GatedTreasuryOpeningFundingWriter::class)->open($importer, $data, self::UUID, $this->nowAt())['account']
            : app(TreasuryRegisterService::class)->open($importer, $data);
        $this->travelTo(\Carbon\Carbon::parse('2026-11-24T08:00:00Z'));
        $sessions = app(CashSessionService::class);
        $session = $sessions->open($submitter);
        $session = $sessions->decide($sessions->close($session, '0.00'), true, $reviewer);
        $this->travelTo(\Carbon\Carbon::instance($this->nowAt()));
        $review = app(LateReceiptReviewService::class)->submit($submitter, [
            'sync_uuid' => self::OTHER, 'cash_session_id' => $session->id, 'subscriber_id' => $subscriber->id, 'payment_method' => $method,
            'amount' => '10.25', 'reference_number' => 'QA-INSTRUMENT', 'payment_date' => '2026-11-24',
        ]);
        $report = app(BankReconciliationService::class)->import($importer, (int) $account['id'], [
            'reference' => 'QA-CLAIM-STATEMENT', 'from_date' => '2026-11-25', 'to_date' => '2026-11-25',
            'opening_amount' => '0.00', 'closing_amount' => $bankAmount, 'evidence' => 'Original synthetic bank statement',
            'lines' => [['bank_reference' => 'QA-BANK-CREDIT', 'booked_on' => '2026-11-25', 'amount' => $bankAmount]],
        ]);
        $statement = $report['statement'];
        $line = DB::table('bank_statement_lines')->where('bank_statement_id', $statement['id'])->sole();
        return [$submitter, $importer, $reviewer, $subscriber, $review, $statement, $line, $session->fresh()];
    }

    private function post(User $actor, array $review, array $statement, object $line,
        string $uuid = self::OTHER, string $date = '2026-11-25', string $evidence = self::EVIDENCE,
        string $reviewReference = 'QA-LATE-REVIEW'): array
    {
        return app(GatedLateNonCashWriter::class)->clear($actor, (int) $review['id'], (int) $statement['id'],
            (int) $line->id, $date, $reviewReference, 'QA-CLEARANCE', $evidence, $uuid, $this->nowAt());
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
        foreach (['payments', 'transactions', 'treasury_bank_collections', 'treasury_postings', 'bank_statement_matches'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('financial_posting_events', $events);
    }

    public function test_late_check_clearance_posts_both_actual_effects_once(): void
    {
        [, , $reviewer, $subscriber, $review, $statement, $line, $session] = $this->fixture();
        $till = $session->getRawOriginal();
        $summary = app(CashSessionService::class)->summary($session);
        $this->assertPending($subscriber, $review);
        $result = $this->post($reviewer, $review, $statement, $line);
        $this->assertTrue($result['registry_created']);
        $payment = Payment::findOrFail($result['review']['payment_id']);
        $this->assertSame('10.25', $payment->amount);
        $this->assertSame('2026-11-25', $payment->payment_date->toDateString());
        $this->assertNull($payment->cash_session_id);
        $this->assertSame($till, $session->fresh()->getRawOriginal());
        $this->assertEquals($summary, app(CashSessionService::class)->summary($session->fresh()));
        $this->assertSame('verified_non_cash', $result['review']['settlement_method']);
        $this->assertSame('QA-LATE-REVIEW', $result['review']['evidence_reference']);
        $this->assertSame('QA-CLEARANCE', $result['review']['bank_clearance_reference']);
        $this->assertSame('2026-11-24', $result['review']['payment_date']);
        $this->assertSame(1025, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertSame(1025, (int) $result['bank']['posting']['amount_minor']);
        $this->assertTrue($result['bank']['reconciliation']['fully_matched']);
        $this->assertFalse($result['bank']['reconciliation']['certified_balance']);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => self::OTHER, 'source_type' => 'ledger_transaction',
            'source_id' => (string) $result['transaction_id'], 'amount_minor' => 1025]);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => self::OTHER, 'source_type' => 'treasury_posting',
            'source_id' => (string) $result['bank']['posting']['id'], 'amount_minor' => 1025]);
        $again = $this->post($reviewer, $review, $statement, $line);
        $this->assertFalse($again['registry_created']);
        $this->assertSame($result['review']['payment_id'], $again['review']['payment_id']);
        $this->assertSame($result['bank']['posting']['id'], $again['bank']['posting']['id']);
        foreach (['payments', 'transactions', 'treasury_bank_collections', 'treasury_postings', 'bank_statement_matches'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
        $this->assertDatabaseCount('financial_posting_operations', 2);
        $this->assertDatabaseCount('financial_posting_events', 3);
        $this->assertSame(1, AuditEvent::query()->where('event', 'late_receipt.approved')->count());
        $this->gate(function (array $context): void {
            $v = app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']);
            $this->assertSame(1, $v['registered_ledger_legs_verified']);
            $this->assertSame(1, $v['registered_treasury_legs_verified']);
            $this->assertSame(1, $v['registered_opening_legs_verified']);
            $this->assertFalse($v['source_coverage_verified']);
        });
    }

    public function test_bank_transfer_clearance_uses_same_paired_posting_contract(): void
    {
        [, , $reviewer, $subscriber, $review, $statement, $line] = $this->fixture('bank_transfer');
        $this->assertPending($subscriber, $review);
        $r = $this->post($reviewer, $review, $statement, $line);
        $this->assertTrue($r['registry_created']);
        $this->assertSame('bank_transfer', Payment::findOrFail($r['review']['payment_id'])->payment_method);
        $this->assertSame(1025, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('financial_posting_events', 3);
    }

    public function test_mismatched_amount_date_and_uuid_do_not_clear_claim(): void
    {
        [, , $reviewer, $subscriber, $review, $statement, $line] = $this->fixture(bankAmount: '10.24');
        $this->rejects('gated_late_bank_amount', fn () => $this->post($reviewer, $review, $statement, $line));
        $this->rejects('gated_late_bank_booking_date', fn () => $this->post($reviewer, $review, $statement, $line, date: '2026-11-24'));
        $this->rejects('gated_late_bank_identity', fn () => $this->post($reviewer, $review, $statement, $line, uuid: self::UUID));
        $this->assertPending($subscriber, $review);
    }

    public function test_submitter_and_importer_cannot_clear_claim(): void
    {
        [$submitter, $importer, , $subscriber, $review, $statement, $line] = $this->fixture();
        foreach ([$submitter, $importer] as $actor) {
            try {
                $this->post($actor, $review, $statement, $line);
                $this->fail('Self approval accepted.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        $this->assertPending($subscriber, $review);
    }

    public function test_unregistered_opening_and_tampered_submission_fail_closed(): void
    {
        [, , $reviewer, $subscriber, $review, $statement, $line] = $this->fixture(registered: false);
        $this->rejects('gated_late_bank_prerequisite_unregistered', fn () => $this->post($reviewer, $review, $statement, $line));
        DB::table('late_receipt_reviews')->where('id', $review['id'])->update(['payment_date' => '2026-11-23']);
        $this->rejects('gated_late_bank_submission_mismatch', fn () => $this->post($reviewer, $review, $statement, $line));
        $this->assertPending($subscriber, $review, 0);
    }

    public function test_closed_frozen_and_rejected_claim_cannot_post(): void
    {
        [, , $reviewer, $subscriber, $review, $statement, $line] = $this->fixture();
        $post = fn () => $this->post($reviewer, $review, $statement, $line);
        DB::table('financial_periods')->update(['state' => 'CLOSED']);
        $this->rejects('financial_period_not_open', $post);
        DB::table('financial_periods')->update(['state' => 'OPEN']);
        DB::table('financial_scopes')->update(['active_freeze_uuid' => self::OTHER]);
        $this->rejects('financial_scope_not_active', $post);
        DB::table('financial_scopes')->update(['active_freeze_uuid' => null]);
        $this->assertPending($subscriber, $review);
        app(LateReceiptReviewService::class)->decide((int) $review['id'], $reviewer,
            ['approve' => false, 'evidence' => 'Independent documented rejection']);
        $this->rejects('gated_late_bank_identity', $post);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
    }

    public function test_legacy_approved_review_is_not_adopted(): void
    {
        [, , $reviewer, , $review, $statement, $line] = $this->fixture();
        app(PaymentService::class)->clearLateNonCash($reviewer, (int) $review['id'], (int) $statement['id'],
            (int) $line->id, 'QA-LATE-REVIEW', 'QA-CLEARANCE', self::EVIDENCE);
        $this->rejects('gated_late_bank_operation_conflict', fn () => $this->post($reviewer, $review, $statement, $line));
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertDatabaseCount('financial_posting_events', 1);
    }

    public function test_independent_review_reference_and_approved_till_are_required(): void
    {
        [, , $reviewer, $subscriber, $review, $statement, $line, $session] = $this->fixture();
        try {
            $this->post($reviewer, $review, $statement, $line, reviewReference: 'QA-CLEARANCE');
            $this->fail('Identical references accepted.');
        } catch (\Illuminate\Validation\ValidationException $error) {
            $this->assertArrayHasKey('evidence_reference', $error->errors());
        }
        DB::table('cash_sessions')->where('id', $session->id)->update(['state' => 'closed']);
        $this->rejects('gated_late_bank_session_unverified', fn () => $this->post($reviewer, $review, $statement, $line));
        $this->assertPending($subscriber, $review);
    }

    public function test_second_registry_leg_failure_restores_pending_claim_and_both_ledgers(): void
    {
        [, , $reviewer, $subscriber, $review, $statement, $line, $session] = $this->fixture();
        $till = $session->getRawOriginal();
        $audit = AuditEvent::query()->count();
        $revisions = DB::table('payment_allocation_revisions')->count();
        $rows = DB::table('payment_allocations')->count();
        $before = (array) DB::table('late_receipt_reviews')->find($review['id']);
        $trigger = DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER qa_registry_failure BEFORE INSERT ON financial_posting_events WHEN NEW.source_type = 'treasury_posting' BEGIN SELECT RAISE(ABORT, 'synthetic_registry_failure'); END"
            : "CREATE TRIGGER qa_registry_failure BEFORE INSERT ON financial_posting_events FOR EACH ROW BEGIN IF NEW.source_type = 'treasury_posting' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic_registry_failure'; END IF; END";
        DB::unprepared($trigger);
        try {
            $this->post($reviewer, $review, $statement, $line);
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
        $this->assertTrue($this->post($reviewer, $review, $statement, $line)['registry_created']);
        $this->assertSame(1025, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('financial_posting_events', 3);
    }
}
