<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\FinancialPostingRegistry;
use App\Services\FinancialWriteGate;
use App\Models\User;
use App\Services\TreasuryRegisterService;
use App\Services\TreasuryCollectionEvidence;
use App\Services\NonCashClaimService;
use App\Services\FinancialLedgerEvidence;
use App\Services\LateReceiptReviewService;
use App\Services\TransactionService;
use App\Models\Payment;
use App\Models\Transaction;
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

final class TreasuryCollectionClaimProofTest extends TestCase
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

    private function subscriber(): Subscriber
    {
        return Subscriber::create(['name' => 'Synthetic collection claim',
            'square_id' => Square::create(['name' => 'Synthetic claim square'])->id, 'status' => 'active']);
    }

    private function bankLine(User $issuer): array
    {
        $bank = app(TreasuryRegisterService::class)->open($issuer, [
            'code' => 'QA-CLAIM-BANK', 'name' => 'Synthetic bank', 'channel' => 'bank',
            'opening_on' => '2026-11-25', 'opening_amount' => '0.00',
            'opening_reference' => 'QA-CLAIM-OPEN', 'opening_evidence' => 'Independent bank opening statement',
        ]);
        $statement = app(BankReconciliationService::class)->import($issuer, $bank['id'], [
            'reference' => 'QA-CLAIM-STMT', 'from_date' => '2026-11-25', 'to_date' => '2026-11-25',
            'opening_amount' => '0.00', 'closing_amount' => '10.25', 'evidence' => 'Independent synthetic bank statement',
            'lines' => [['bank_reference' => 'QA-CLAIM-IN', 'booked_on' => '2026-11-25', 'amount' => '10.25']],
        ]);
        return [(int) $statement['statement']['id'], (int) DB::table('bank_statement_lines')->where('bank_reference', 'QA-CLAIM-IN')->sole()->id];
    }

    private function registerBoth(array $context, int $paymentId, int $postingId): array
    {
        $entry = Transaction::where('reference_type', Payment::class)->where('reference_id', $paymentId)
            ->where('type', 'payment')->sole();
        $ledger = app(FinancialLedgerEvidence::class)->leg((int) $entry->id);
        $treasury = app(TreasuryCollectionEvidence::class)->leg($postingId);
        $this->assertSame(1025, $ledger['amount_minor']);
        $this->assertSame(1025, $treasury['amount_minor']);
        $this->assertTrue(app(FinancialPostingRegistry::class)->record($context, self::UUID, [$ledger, $treasury], $this->nowAt()));
        $result = app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']);
        $this->assertSame(1, $result['registered_ledger_legs_verified']);
        $this->assertSame(1, $result['registered_treasury_legs_verified']);
        $this->assertFalse($result['source_coverage_verified']);
        return $treasury;
    }

    public function test_actual_claim_clearance_has_two_legs_and_binds_original_claim_date(): void
    {
        $issuer = $this->actor();
        $reviewer = $this->actor();
        $subscriber = $this->subscriber();
        [$statement, $line] = $this->bankLine($issuer);
        $claim = app(NonCashClaimService::class)->submit($issuer, [
            'subscriber_id' => $subscriber->id, 'sync_uuid' => '33333333-3333-4333-8333-333333333333',
            'amount' => '10.25', 'payment_method' => 'check', 'instrument_reference' => 'QA-CLAIM-CHECK',
            'instrument_on' => '2026-11-24',
        ]);
        $post = function (array $context) use ($reviewer, $claim, $statement, $line): array {
            $result = app(PaymentService::class)->clearNonCashClaim($reviewer, $claim['id'], $statement, $line,
                'QA-CLAIM-PROOF', 'Independent proof of cleared bank funds');
            $this->assertSame('cleared', $result['claim']['status']);
            $this->assertSame('2026-11-25', Payment::findOrFail($result['payment_id'])->payment_date->toDateString());
            return $this->registerBoth($context, (int) $result['payment_id'], (int) $result['bank']['posting']['id']);
        };
        try {
            $this->gate(function (array $context) use ($post): void {
                $post($context);
                throw new \RuntimeException('synthetic claim rollback');
            });
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $error) {
            $this->assertSame('synthetic claim rollback', $error->getMessage());
        }
        $this->assertDatabaseHas('noncash_collection_claims', ['id' => $claim['id'], 'status' => 'pending']);
        foreach (['payments', 'transactions', 'treasury_bank_collections', 'treasury_postings', 'bank_statement_matches', 'financial_posting_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->gate($post);
        $this->assertSame(1025, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('payments', 1);
        // Still eligible on the bank validator's date bound, but no longer the recorded source.
        DB::table('noncash_collection_claims')->where('id', $claim['id'])->update(['instrument_on' => '2026-11-23']);
        $this->gate(function (array $context): void {
            try {
                app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']);
                $this->fail('Altered original claim date accepted.');
            } catch (DomainException $error) {
                $this->assertSame('ledger_registry_source_mismatch', $error->getMessage());
            }
        });
    }

    public function test_late_noncash_clearance_preserves_closed_till_and_decision_identity(): void
    {
        $issuer = $this->actor();
        $reviewer = $this->actor();
        $subscriber = $this->subscriber();
        [$statement, $line] = $this->bankLine($issuer);
        $sessions = app(CashSessionService::class);
        $session = $sessions->decide($sessions->close($sessions->open($issuer), '0.00'), true, $reviewer);
        $claim = app(LateReceiptReviewService::class)->submit($issuer, [
            'sync_uuid' => '44444444-4444-4444-8444-444444444444', 'cash_session_id' => $session->id,
            'subscriber_id' => $subscriber->id, 'amount' => '10.25', 'payment_date' => '2026-11-25',
            'payment_method' => 'bank_transfer', 'reference_number' => 'QA-LATE-BANK-REF',
        ]);
        $this->gate(function (array $context) use ($reviewer, $claim, $statement, $line): void {
            $result = app(PaymentService::class)->clearLateNonCash($reviewer, $claim['id'], $statement, $line,
                'QA-LATE-DECISION', 'QA-LATE-CLEAR', 'Independent proof of cleared late transfer');
            $this->assertSame('verified_non_cash', $result['review']['settlement_method']);
            $this->registerBoth($context, (int) $result['review']['payment_id'], (int) $result['bank']['posting']['id']);
        });
        $this->assertSame('0.00', $session->fresh()->counted_amount);
        $this->assertSame(1025, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertDatabaseCount('treasury_late_cash_receipts', 0);
    }

    public function test_already_counted_late_cash_registers_subscriber_only(): void
    {
        $collector = $this->actor();
        $reviewer = $this->actor();
        $subscriber = $this->subscriber();
        $sessions = app(CashSessionService::class);
        $session = $sessions->decide($sessions->close($sessions->open($collector), '10.25'), true, $reviewer);
        $service = app(LateReceiptReviewService::class);
        $claim = $service->submit($collector, [
            'sync_uuid' => '55555555-5555-4555-8555-555555555555', 'cash_session_id' => $session->id,
            'subscriber_id' => $subscriber->id, 'amount' => '10.25', 'payment_date' => '2026-11-25',
            'payment_method' => 'cash',
        ]);
        $this->gate(function (array $context) use ($reviewer, $service, $claim, $session): void {
            $result = $service->decide($claim['id'], $reviewer, [
                'approve' => true, 'settlement_method' => 'already_counted',
                'evidence_reference' => 'QA-COUNTED-REF', 'evidence' => 'Independent proof of previously counted physical cash',
            ]);
            $entry = Transaction::where('reference_type', Payment::class)->where('reference_id', $result['payment_id'])
                ->where('type', 'payment')->sole();
            $leg = app(FinancialLedgerEvidence::class)->leg((int) $entry->id);
            $this->assertSame(1025, $leg['amount_minor']);
            $this->assertTrue(app(FinancialPostingRegistry::class)->record($context, self::UUID, [$leg], $this->nowAt()));
            $verified = app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']);
            $this->assertSame(1, $verified['registered_ledger_legs_verified']);
            $this->assertSame(0, $verified['registered_treasury_legs_verified']);
            $this->assertFalse($verified['source_coverage_verified']);
            $this->assertSame(1025, $service->countedCashAllocation($session->fresh())['allocated_minor']);
            $this->assertSame(0, $service->countedCashAllocation($session->fresh())['available_minor']);
        });
        $this->assertSame('10.25', $session->fresh()->counted_amount);
        $this->assertSame(1025, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertDatabaseCount('treasury_late_cash_receipts', 0);
    }
}
