<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\FinancialPostingRegistry;
use App\Services\FinancialWriteGate;
use App\Models\User;
use App\Services\TreasuryRegisterService;
use App\Services\TreasuryAdjustmentEvidence;
use App\Services\BankReconciliationService;
use App\Services\TreasuryCashCountService;
use App\Services\FinancialLedgerRegistryVerifier;
use App\Services\CashSessionService;
use App\Services\CashHandoverService;
use App\Services\PaymentService;
use App\Services\PaymentRefundService;
use App\Models\Square;
use App\Models\Subscriber;
use DomainException;
use Spatie\Permission\Models\Role;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TreasuryAdjustmentEvidenceTest extends TestCase
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

    public function test_actual_bank_fee_source_and_atomic_rollback(): void
    {
        $importer = $this->actor();
        $reviewer = $this->actor();
        $bank = app(TreasuryRegisterService::class)->open($importer, [
            'code' => 'QA-FEE-BANK', 'name' => 'Synthetic bank', 'channel' => 'bank',
            'opening_on' => '2026-11-25', 'opening_amount' => '100.50',
            'opening_reference' => 'QA-FEE-OPEN', 'opening_evidence' => 'Independent bank opening statement',
        ]);
        $service = app(BankReconciliationService::class);
        $statement = $service->import($importer, $bank['id'], [
            'reference' => 'QA-FEE-STMT', 'from_date' => '2026-11-25', 'to_date' => '2026-11-25',
            'opening_amount' => '100.50', 'closing_amount' => '105.25',
            'evidence' => 'Independent synthetic bank statement',
            'lines' => [
                ['bank_reference' => 'QA-FEE-DEBIT', 'booked_on' => '2026-11-25', 'amount' => '-0.25'],
                ['bank_reference' => 'QA-FEE-CREDIT', 'booked_on' => '2026-11-25', 'amount' => '5.00'],
            ],
        ]);
        $line = DB::table('bank_statement_lines')->where('bank_reference', 'QA-FEE-DEBIT')->sole();
        $post = function (array $context) use ($service, $statement, $line, $reviewer): array {
            $result = $service->recordFee($reviewer, $statement['statement']['id'], $line->id, 'QA-FEE-REF',
                'Independent bank service fee', 'Verified independent bank charge advice');
            $leg = app(TreasuryAdjustmentEvidence::class)->leg((int) $result['posting']['id']);
            $this->assertSame(-25, $leg['amount_minor']);
            $this->assertTrue(app(FinancialPostingRegistry::class)->record($context, self::UUID, [$leg], $this->nowAt()));
            $verified = app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']);
            $this->assertSame(1, $verified['registered_treasury_legs_verified']);
            $this->assertFalse($verified['source_coverage_verified']);
            return $result;
        };
        try {
            $this->gate(function (array $context) use ($post): void {
                $post($context);
                throw new \RuntimeException('synthetic fee rollback');
            });
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $error) {
            $this->assertSame('synthetic fee rollback', $error->getMessage());
        }
        foreach (['treasury_bank_fees', 'treasury_postings', 'bank_statement_matches', 'financial_posting_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $result = $this->gate($post);
        $this->assertDatabaseCount('treasury_bank_fees', 1);
        $this->assertDatabaseCount('bank_statement_matches', 1);
        $this->assertFalse($result['reconciliation']['fully_matched']);
        $this->assertDatabaseCount('transactions', 0);
        DB::table('treasury_bank_fees')->where('id', $result['fee']['id'])->update(['amount_minor' => 26]);
        $this->gate(function (array $context): void {
            try {
                app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']);
                $this->fail('Corrupted fee accepted.');
            } catch (DomainException $error) {
                $this->assertSame('adjustment_evidence_fee_mismatch', $error->getMessage());
            }
        });
    }

    public function test_actual_cash_loss_and_held_surplus_have_distinct_signed_evidence(): void
    {
        $counter = $this->actor();
        $reviewer = $this->actor();
        $counts = app(TreasuryCashCountService::class);
        $loss = $this->account($counter, 'QA-LOSS', '500.25');
        $surplus = $this->account($counter, 'QA-SURPLUS', '500.25');
        $legs = [];
        foreach ([[$loss, '490.00', 'confirmed_cash_loss', -1025, 'LOSS', self::UUID],
            [$surplus, '510.50', 'unattributed_cash_held', 1025, 'SURPLUS', self::OTHER]] as
            [$account, $amount, $classification, $expected, $suffix, $uuid]) {
            $count = $counts->record($counter, $account['id'], $amount,
                'QA-COUNT-'.$suffix, 'Independent physical count evidence');
            $counts->review($reviewer, $count['count']['id'], true, 'Independent documented review');
            $legs[] = $this->gate(function (array $context) use ($counts, $reviewer, $count, $classification, $expected, $suffix, $uuid): array {
                $result = $counts->adjust($reviewer, $count['count']['id'], 'QA-ADJ-'.$suffix, $classification,
                    'Independently investigated specific cash discrepancy', 'Independent evidence of the investigated incident');
                $leg = app(TreasuryAdjustmentEvidence::class)->leg((int) $result['posting']['id']);
                $this->assertSame($expected, $leg['amount_minor']);
                $this->assertTrue(app(FinancialPostingRegistry::class)->record($context, $uuid, [$leg], $this->nowAt()));
                return $leg;
            });
        }
        $this->assertDatabaseCount('treasury_cash_adjustments', 2);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->gate(function (array $context) use ($legs): void {
            foreach ($legs as $leg) {
                $this->assertSame($leg, app(TreasuryAdjustmentEvidence::class)->leg((int) $leg['source_id']));
            }
            $verified = app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']);
            $this->assertSame(2, $verified['registered_treasury_legs_verified']);
            $this->assertFalse($verified['source_coverage_verified']);
        });
    }
}
