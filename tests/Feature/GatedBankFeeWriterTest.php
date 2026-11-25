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
use App\Services\GatedBankFeeWriter;
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

final class GatedBankFeeWriterTest extends TestCase
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

    private const FEE = '33333333-3333-4333-8333-333333333333';
    private const NEXT = '44444444-4444-4444-8444-444444444444';
    private const DATE = '2026-11-24';
    private const REASON = 'Verified actual bank service charge';
    private const EVIDENCE = 'Independent original bank fee statement evidence';

    private function fixture(bool $registered = true, bool $credit = false): array
    {
        $importer = $this->actor();
        $reviewer = $this->actor();
        $data = ['code' => 'QA-BANK-FEE', 'name' => 'Synthetic bank account', 'channel' => 'bank',
            'opening_on' => self::DATE, 'opening_amount' => '100.00',
            'opening_reference' => 'QA-BANK-OPEN', 'opening_evidence' => 'Independent opening bank statement'];
        $account = $registered
            ? app(GatedTreasuryOpeningFundingWriter::class)->open($importer, $data, self::UUID, $this->nowAt())['account']
            : app(TreasuryRegisterService::class)->open($importer, $data);
        $lines = [['bank_reference' => 'BANK-FEE-01', 'booked_on' => self::DATE, 'amount' => '-0.25']];
        if ($credit) {
            $lines[] = ['bank_reference' => 'BANK-CREDIT-01', 'booked_on' => self::DATE, 'amount' => '1.00'];
        }
        $report = app(BankReconciliationService::class)->import($importer, (int) $account['id'], [
            'reference' => 'QA-STATEMENT', 'from_date' => self::DATE, 'to_date' => self::DATE,
            'opening_amount' => '100.00', 'closing_amount' => $credit ? '100.75' : '99.75',
            'evidence' => 'Original synthetic bank statement', 'lines' => $lines,
        ]);
        $statement = $report['statement'];
        $line = DB::table('bank_statement_lines')->where('bank_statement_id', $statement['id'])
            ->where('bank_reference', 'BANK-FEE-01')->sole();
        return [$importer, $reviewer, $account, $statement, $line];
    }

    private function post(User $actor, array $statement, object $line, string $uuid = self::FEE,
        string $date = self::DATE, string $reference = 'QA-FEE-REF', string $reason = self::REASON,
        string $evidence = self::EVIDENCE): array
    {
        return app(GatedBankFeeWriter::class)->record($actor, (int) $statement['id'], (int) $line->id,
            $date, $reference, $reason, $evidence, $uuid, $this->nowAt());
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

    public function test_actual_fee_uses_bank_date_and_exact_replay_without_subscriber_posting(): void
    {
        [, $reviewer, , $statement, $line] = $this->fixture();
        $original = (array) DB::table('bank_statements')->find($statement['id']);
        $result = $this->post($reviewer, $statement, $line);
        $this->assertTrue($result['registry_created']);
        $this->assertSame(-25, (int) $result['posting']['amount_minor']);
        $this->assertSame(25, (int) $result['fee']['amount_minor']);
        $this->assertSame(self::DATE, $result['posting']['effective_on']);
        $this->assertSame(self::DATE, $result['fee']['charged_on']);
        $this->assertSame(9975, $result['reconciliation']['recorded_closing_minor']);
        $this->assertSame(0, $result['reconciliation']['closing_difference_minor']);
        $this->assertTrue($result['reconciliation']['fully_matched']);
        $this->assertFalse($result['reconciliation']['certified_balance']);
        $this->assertSame($original, (array) DB::table('bank_statements')->find($statement['id']));
        $this->assertSame((array) $line, (array) DB::table('bank_statement_lines')->find($line->id));
        $again = $this->post($reviewer, $statement, $line, reference: ' qa-fee-ref ');
        $this->assertFalse($again['registry_created']);
        $this->assertSame($result['posting']['id'], $again['posting']['id']);
        $this->assertDatabaseHas('financial_posting_operations', ['operation_uuid' => self::FEE, 'effective_on' => self::DATE]);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => self::FEE,
            'source_type' => 'treasury_posting', 'source_id' => (string) $result['posting']['id'], 'amount_minor' => -25]);
        foreach (['treasury_bank_fees', 'treasury_postings', 'bank_statement_matches'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('financial_posting_events', 2);
        $this->assertSame(1, AuditEvent::query()->where('event', 'treasury.bank_fee_recorded')->count());
        $this->gate(function (array $context): void {
            $v = app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']);
            $this->assertSame(1, $v['registered_treasury_legs_verified']);
            $this->assertSame(1, $v['registered_opening_legs_verified']);
            $this->assertFalse($v['source_coverage_verified']);
        });
    }

    public function test_credit_line_stays_unmatched_and_wrong_booking_date_is_rejected(): void
    {
        [, $reviewer, , $statement, $line] = $this->fixture(credit: true);
        $credit = DB::table('bank_statement_lines')->where('bank_statement_id', $statement['id'])
            ->where('bank_reference', 'BANK-CREDIT-01')->sole();
        $this->rejects('gated_bank_fee_line_date', fn () => $this->post($reviewer, $statement, $line, date: '2026-11-25'));
        $this->rejects('gated_bank_fee_debit_required', fn () => $this->post($reviewer, $statement, $credit));
        $result = $this->post($reviewer, $statement, $line);
        $this->assertTrue($result['registry_created']);
        $this->assertFalse($result['reconciliation']['fully_matched']);
        $this->assertSame(100, $result['reconciliation']['bank_unmatched_minor']);
        $this->assertSame(1, $result['reconciliation']['matched_count']);
        $this->assertDatabaseCount('bank_statement_matches', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_importer_cannot_approve_and_replay_cannot_change_identity(): void
    {
        [$importer, $reviewer, , $statement, $line] = $this->fixture();
        try {
            $this->post($importer, $statement, $line);
            $this->fail('Self approval accepted.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->post($reviewer, $statement, $line);
        $this->rejects('gated_bank_fee_operation_conflict', fn () => $this->post($reviewer, $statement, $line, self::NEXT));
        try {
            $this->post($reviewer, $statement, $line, reason: 'Changed classification reason');
            $this->fail('Changed decision accepted.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertDatabaseCount('treasury_bank_fees', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
    }

    public function test_unregistered_opening_and_legacy_fee_cannot_be_adopted(): void
    {
        [, $reviewer, , $statement, $line] = $this->fixture(registered: false);
        $this->rejects('gated_bank_fee_prerequisite_unregistered', fn () => $this->post($reviewer, $statement, $line));
        $this->assertDatabaseCount('treasury_bank_fees', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
    }

    public function test_existing_unregistered_fee_is_not_adopted(): void
    {
        [, $reviewer, , $statement, $line] = $this->fixture();
        app(BankReconciliationService::class)->recordFee($reviewer, (int) $statement['id'], (int) $line->id,
            'QA-FEE-REF', self::REASON, self::EVIDENCE);
        $this->rejects('gated_bank_fee_operation_conflict', fn () => $this->post($reviewer, $statement, $line));
        $this->assertDatabaseCount('treasury_bank_fees', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertDatabaseCount('financial_posting_events', 1);
    }

    public function test_tampered_statement_and_closed_or_frozen_periods_are_rejected(): void
    {
        [, $reviewer, , $statement, $line] = $this->fixture();
        $post = fn () => $this->post($reviewer, $statement, $line);
        DB::table('financial_periods')->update(['state' => 'CLOSED']);
        $this->rejects('financial_period_not_open', $post);
        DB::table('financial_periods')->update(['state' => 'OPEN']);
        DB::table('financial_scopes')->update(['active_freeze_uuid' => self::FEE]);
        $this->rejects('financial_scope_not_active', $post);
        DB::table('financial_scopes')->update(['active_freeze_uuid' => null]);
        DB::table('bank_statement_lines')->where('id', $line->id)->update(['description' => 'Tampered bank evidence']);
        try {
            $post();
            $this->fail('Tampered statement accepted.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertDatabaseCount('treasury_bank_fees', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertDatabaseCount('bank_statement_matches', 0);
        $this->assertDatabaseCount('financial_posting_events', 1);
    }

    public function test_registry_failure_restores_fee_posting_match_and_audit_then_retries(): void
    {
        [, $reviewer, , $statement, $line] = $this->fixture();
        $audit = AuditEvent::query()->count();
        $before = app(BankReconciliationService::class)->inspect($reviewer, (int) $statement['id']);
        $trigger = DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER qa_registry_failure BEFORE INSERT ON financial_posting_events BEGIN SELECT RAISE(ABORT, 'synthetic_registry_failure'); END"
            : "CREATE TRIGGER qa_registry_failure BEFORE INSERT ON financial_posting_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic_registry_failure'";
        DB::unprepared($trigger);
        try {
            $this->post($reviewer, $statement, $line);
            $this->fail('Registry insert should fail.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('synthetic_registry_failure', $error->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS qa_registry_failure');
        }
        $this->assertEquals($before, app(BankReconciliationService::class)->inspect($reviewer, (int) $statement['id']));
        $this->assertSame($audit, AuditEvent::query()->count());
        foreach (['treasury_bank_fees', 'treasury_postings', 'bank_statement_matches', 'payments', 'transactions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('financial_posting_operations', 1);
        $this->assertDatabaseCount('financial_posting_events', 1);
        $this->assertTrue($this->post($reviewer, $statement, $line)['registry_created']);
        $this->assertDatabaseCount('treasury_bank_fees', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertDatabaseCount('bank_statement_matches', 1);
        $this->assertDatabaseCount('financial_posting_events', 2);
    }
}
