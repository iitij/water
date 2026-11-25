<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\FinancialPostingRegistry;
use App\Services\FinancialWriteGate;
use App\Models\User;
use App\Services\TreasuryRegisterService;
use App\Services\TreasuryCollectionEvidence;
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

final class TreasuryCollectionEvidenceTest extends TestCase
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

    public function test_legacy_bank_receipt_confirmation_registers_only_treasury_and_detects_corruption(): void
    {
        $issuer = $this->actor();
        $reviewer = $this->actor();
        $subscriber = Subscriber::create(['name' => 'Synthetic bank receipt',
            'square_id' => Square::create(['name' => 'Synthetic bank square'])->id, 'status' => 'active']);
        // Explicit pre-existing legacy receipt fixture, not a new noncash issuance.
        $payment = Payment::create(['subscriber_id' => $subscriber->id, 'user_id' => $issuer->id,
            'receipt_number' => 'QA-LEGACY-COLLECT', 'amount' => '125.75', 'payment_date' => '2026-11-25',
            'payment_method' => 'bank_transfer', 'reference_number' => 'QA-LEGACY-REF']);
        app(TransactionService::class)->recordTransaction($subscriber, 'payment', '125.75', 'Legacy receipt', $payment);
        app(PaymentService::class)->recalculateInvoices($subscriber);
        $bank = app(TreasuryRegisterService::class)->open($issuer, [
            'code' => 'QA-COLLECT-BANK', 'name' => 'Synthetic bank', 'channel' => 'bank',
            'opening_on' => '2026-11-25', 'opening_amount' => '100.50',
            'opening_reference' => 'QA-COLLECT-OPEN', 'opening_evidence' => 'Independent bank opening statement',
        ]);
        $service = app(BankReconciliationService::class);
        $statement = $service->import($issuer, $bank['id'], [
            'reference' => 'QA-COLLECT-STMT', 'from_date' => '2026-11-25', 'to_date' => '2026-11-25',
            'opening_amount' => '100.50', 'closing_amount' => '226.25', 'evidence' => 'Independent synthetic bank statement',
            'lines' => [['bank_reference' => 'QA-COLLECT-IN', 'booked_on' => '2026-11-25', 'amount' => '125.75']],
        ]);
        $line = DB::table('bank_statement_lines')->where('bank_reference', 'QA-COLLECT-IN')->sole();
        $result = $this->gate(function (array $context) use ($service, $reviewer, $statement, $line, $payment): array {
            $result = $service->confirmCollection($reviewer, $statement['statement']['id'], $line->id,
                $payment->id, 'QA-COLLECT-PROOF', 'Independent proof of actual bank credit');
            $leg = app(TreasuryCollectionEvidence::class)->leg((int) $result['posting']['id']);
            $this->assertSame(12575, $leg['amount_minor']);
            $this->assertTrue(app(FinancialPostingRegistry::class)->record($context, self::UUID, [$leg], $this->nowAt()));
            $verified = app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']);
            $this->assertSame(1, $verified['registered_treasury_legs_verified']);
            $this->assertFalse($verified['source_coverage_verified']);
            return $result;
        });
        $this->assertSame(12575, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('treasury_bank_collections', 1);
        DB::table('treasury_bank_collections')->where('id', $result['collection']['id'])->update(['amount_minor' => 12576]);
        $this->gate(function (array $context): void {
            try {
                app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']);
                $this->fail('Corrupted collection accepted.');
            } catch (DomainException $error) {
                $this->assertSame('collection_evidence_bank_mismatch', $error->getMessage());
            }
        });
    }

    public function test_late_separate_cash_receipt_registers_both_actual_legs_atomically(): void
    {
        $reviewer = $this->actor();
        $collector = $this->actor();
        $cash = $this->account($reviewer, 'QA-LATE-CASH', '100.00');
        $subscriber = Subscriber::create(['name' => 'Synthetic late receipt',
            'square_id' => Square::create(['name' => 'Synthetic late square'])->id, 'status' => 'active']);
        $sessions = app(CashSessionService::class);
        $session = $sessions->decide($sessions->close($sessions->open($collector), '0.00'), true, $reviewer);
        $service = app(LateReceiptReviewService::class);
        $claim = $service->submit($collector, [
            'sync_uuid' => '33333333-3333-4333-8333-333333333333',
            'cash_session_id' => $session->id, 'subscriber_id' => $subscriber->id,
            'amount' => '10.25', 'payment_date' => '2026-11-25', 'payment_method' => 'cash',
        ]);
        $post = function (array $context) use ($service, $claim, $reviewer, $cash): void {
            $result = $service->receiveCash($claim['id'], $reviewer, $cash['id'], '2026-11-25',
                'QA-LATE-PROOF', 'Independent proof of separate late cash receipt');
            $treasury = app(TreasuryCollectionEvidence::class)->leg((int) $result['posting']['id']);
            $entry = Transaction::where('reference_type', Payment::class)
                ->where('reference_id', $result['review']['payment_id'])->where('type', 'payment')->sole();
            $ledger = app(\App\Services\FinancialLedgerEvidence::class)->leg((int) $entry->id);
            $this->assertSame(1025, $treasury['amount_minor']);
            $this->assertSame(1025, $ledger['amount_minor']);
            $this->assertTrue(app(FinancialPostingRegistry::class)->record($context, self::UUID, [$ledger, $treasury], $this->nowAt()));
            $verified = app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']);
            $this->assertSame(1, $verified['registered_treasury_legs_verified']);
            $this->assertSame(1, $verified['registered_ledger_legs_verified']);
            $this->assertFalse($verified['source_coverage_verified']);
        };
        try {
            $this->gate(function (array $context) use ($post): void {
                $post($context);
                throw new \RuntimeException('synthetic late receipt rollback');
            });
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $error) {
            $this->assertSame('synthetic late receipt rollback', $error->getMessage());
        }
        foreach (['payments', 'transactions', 'treasury_late_cash_receipts', 'treasury_postings', 'financial_posting_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseHas('late_receipt_reviews', ['id' => $claim['id'], 'status' => 'pending']);
        $this->gate($post);
        $this->assertSame(1025, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertDatabaseCount('financial_posting_events', 2);
        $this->assertSame('0.00', $session->fresh()->counted_amount);
    }
}
