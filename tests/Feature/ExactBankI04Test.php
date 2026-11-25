<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\BankReconciliationService;
use App\Services\TreasuryRegisterService;
use App\Services\TreasuryTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ExactBankI04Test extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as private refreshSyntheticDatabase;
    }

    public function refreshDatabase(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $driver = DB::getDriverName();
        $database = DB::connection()->getDatabaseName();
        $this->assertTrue(($driver === 'sqlite' && $database === ':memory:')
            || ($driver === 'mysql' && $database === 'water_test'));
        $this->refreshSyntheticDatabase();
    }

    private function actor(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        return $user;
    }

    private function account(User $actor, string $code, string $amount, string $channel = 'bank'): array
    {
        return app(TreasuryRegisterService::class)->open($actor, [
            'code' => $code, 'name' => 'Synthetic bank '.$code, 'channel' => $channel,
            'opening_on' => today()->toDateString(), 'opening_amount' => $amount,
            'opening_reference' => $code.'-OPEN', 'opening_evidence' => 'Synthetic independent opening evidence',
        ]);
    }

    private function statement(string $opening, string $closing, array $amounts): array
    {
        $date = today()->toDateString();
        $lines = [];
        foreach ($amounts as $key => $amount) {
            $lines[] = ['bank_reference' => $key, 'booked_on' => $date, 'amount' => $amount];
        }
        return ['reference' => 'I04-STATEMENT', 'from_date' => $date, 'to_date' => $date,
            'opening_amount' => $opening, 'closing_amount' => $closing,
            'evidence' => 'Synthetic independent bank statement evidence', 'lines' => $lines];
    }

    public function test_signed_statement_at_document_limit_and_actual_fee_conserve_exactly(): void
    {
        $importer = $this->actor();
        $reviewer = $this->actor();
        $account = $this->account($importer, 'I04-BANK', '999999999999.99');
        $service = app(BankReconciliationService::class);
        $data = $this->statement('999999999999.99', '0.01', ['DEBIT' => '-999999999999.98']);
        try {
            $service->import($importer, $account['id'], array_replace($data, ['closing_amount' => '0.02']));
            $this->fail('One cent mismatch accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('closing_amount', $e->errors());
        }
        $this->assertDatabaseCount('bank_statements', 0);
        $report = $service->import($importer, $account['id'], $data);
        $id = $report['statement']['id'];
        $this->assertSame(-99999999999998, $report['bank_unmatched_minor']);
        $this->assertSame(-99999999999998, $report['closing_difference_minor']);
        $this->assertTrue($report['statement_integrity_matches']);
        $this->assertSame($id, $service->import($importer, $account['id'], $data)['statement']['id']);
        $line = DB::table('bank_statement_lines')->where('bank_statement_id', $id)->first();
        $args = [$reviewer, $id, $line->id, 'I04-FEE', 'Synthetic documented bank charge', 'Synthetic independent fee evidence'];
        $fee = $service->recordFee(...$args);
        $this->assertSame(-99999999999998, (int) $fee['posting']['amount_minor']);
        $this->assertTrue($fee['reconciliation']['fully_matched']);
        $this->assertSame(1, $fee['reconciliation']['recorded_closing_minor']);
        $this->assertSame(0, $fee['reconciliation']['closing_difference_minor']);
        $this->assertSame($fee['posting']['id'], $service->recordFee(...$args)['posting']['id']);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_offsetting_signed_lines_retain_original_canonical_hash(): void
    {
        $actor = $this->actor();
        $account = $this->account($actor, 'I04-HASH', '0');
        $service = app(BankReconciliationService::class);
        $data = $this->statement('-0.01', '-0.01', ['Z' => '-999999999999.99', 'A' => '999999999999.99']);
        $report = $service->import($actor, $account['id'], $data);
        $s = $report['statement'];
        $canonical = ['treasury_account_id' => (int) $account['id'], 'reference' => $data['reference'],
            'from_date' => $data['from_date'], 'to_date' => $data['to_date'], 'opening_minor' => -1,
            'closing_minor' => -1, 'evidence' => $data['evidence'], 'imported_by' => (int) $actor->id];
        $lines = [];
        foreach (['A' => 99999999999999, 'Z' => -99999999999999] as $key => $minor) {
            $lines[] = ['treasury_account_id' => (int) $account['id'], 'bank_reference' => $key,
                'booked_on' => $data['from_date'], 'amount_minor' => $minor, 'description' => null];
        }
        $this->assertSame(hash('sha256', json_encode([$canonical, $lines], JSON_THROW_ON_ERROR)), $s['content_hash']);
        $this->assertSame(0, $report['bank_unmatched_minor']);
        $this->assertCount(2, $report['unmatched_bank_lines']);
        $this->assertFalse($report['fully_matched']);
        DB::table('bank_statement_lines')->where('bank_reference', 'A')->update(['amount_minor' => 99999999999998]);
        DB::table('bank_statement_lines')->where('bank_reference', 'Z')->update(['amount_minor' => -99999999999998]);
        $after = $service->inspect($actor, $s['id']);
        $this->assertFalse($after['statement_integrity_matches']);
        $this->assertSame(0, $after['bank_unmatched_minor']);
        $this->assertFalse($after['fully_matched']);
        $this->assertSame('statement_content_mismatch', $after['issues'][0]['code']);
    }

    public function test_cancelled_bank_audit_rolls_back_fee_and_match(): void
    {
        $importer = $this->actor();
        $reviewer = $this->actor();
        $account = $this->account($importer, 'I04-AUDIT', '1.00');
        $service = app(BankReconciliationService::class);
        $report = $service->import($importer, $account['id'], $this->statement('1.00', '0.99', ['FEE' => '-0.01']));
        $id = $report['statement']['id'];
        $line = DB::table('bank_statement_lines')->where('bank_statement_id', $id)->first();
        AuditEvent::creating(static fn (AuditEvent $event) => $event->event === 'treasury.bank_fee_recorded' ? false : null);
        try {
            $service->recordFee($reviewer, $id, $line->id, 'I04-ROLLBACK', 'Synthetic bank fee proof', 'Synthetic independent verification');
            $this->fail('Fee accepted without persisted audit.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('audit', strtolower($e->getMessage()));
        }
        $this->assertDatabaseCount('treasury_bank_fees', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertDatabaseCount('bank_statement_matches', 0);
        $this->assertSame(-1, $service->inspect($importer, $id)['closing_difference_minor']);
    }

    public function test_bank_report_rejects_intermediate_ledger_overflow_without_writes(): void
    {
        $actor = $this->actor();
        $other = $this->actor();
        $bank = $this->account($actor, 'I04-OVERFLOW', '0.01');
        $cash = $this->account($actor, 'I04-CASH', '1.00', 'cash');
        $transfer = app(TreasuryTransferService::class);
        $date = today()->toDateString();
        $sent = $transfer->dispatch($actor, ['from_account_id' => $cash['id'], 'to_account_id' => $bank['id'],
            'amount' => '1.00', 'reference' => 'I04-OUT', 'dispatched_on' => $date, 'evidence' => 'Synthetic verified dispatch']);
        $transfer->receive($other, $sent['id'], ['amount' => '1.00', 'reference' => 'I04-IN',
            'received_on' => $date, 'evidence' => 'Synthetic verified receipt']);
        $service = app(BankReconciliationService::class);
        $report = $service->import($actor, $bank['id'], $this->statement('0.01', '0.01', []));
        // Synthetic corrupt BIGINT source: never permit a float or truncated aggregate.
        DB::table('treasury_postings')->where('treasury_account_id', $bank['id'])->update(['amount_minor' => PHP_INT_MAX]);
        $before = DB::table('treasury_postings')->orderBy('id')->get()->toArray();
        try {
            $service->inspect($actor, $report['statement']['id']);
            $this->fail('Overflowing recorded balance was returned.');
        } catch (\OverflowException $e) {
            $this->addToAssertionCount(1);
        }
        $this->assertEquals($before, DB::table('treasury_postings')->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('bank_statement_matches', 0);
    }
}
