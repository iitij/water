<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Square;
use App\Models\Transaction;
use App\Services\FinancialLedgerEvidence;
use App\Services\FinancialPostingRegistry;
use App\Services\FinancialWriteGate;
use App\Services\PaymentService;
use App\Services\TransactionService;
use DateTimeImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class FinancialLedgerEvidenceTest extends TestCase
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

    public function test_real_receipt_effect_is_recorded_with_same_transaction_and_rolls_back_with_payment(): void
    {
        $account = Square::create(['name' => 'Synthetic evidence'])->subscribers()
            ->create(['name' => 'Synthetic subscriber', 'status' => 'active']);
        $registry = app(FinancialPostingRegistry::class);
        $source = app(FinancialLedgerEvidence::class);
        $post = function (array $context) use ($account, $registry, $source): array {
            $receipt = app(PaymentService::class)->collectPayment($account, '10.25');
            $entry = Transaction::where('reference_type', Payment::class)
                ->where('reference_id', $receipt->id)->where('type', 'payment')->firstOrFail();
            $leg = $source->leg((int) $entry->id);
            $this->assertSame(1025, $leg['amount_minor']);
            $this->assertSame((string) $entry->id, $leg['source_id']);
            $this->assertTrue($registry->record($context, self::UUID, [$leg], $this->nowAt()));
            return $leg;
        };
        try {
            $this->gate(function (array $context) use ($post): void {
                $post($context);
                throw new \RuntimeException('synthetic outer failure');
            });
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $e) {
            $this->assertSame('synthetic outer failure', $e->getMessage());
        }
        foreach (['payments', 'transactions', 'financial_posting_operations', 'financial_posting_events'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
        $leg = $this->gate($post);
        $this->assertSame(1025, $account->fresh()->getAccountBalanceMinor());
        $this->assertSame($leg, $this->gate(fn () => $source->leg((int) $leg['source_id'])));
        $this->assertFalse($this->gate(fn (array $context) => $registry->record($context, self::UUID, [$leg], $this->nowAt())));
    }

    public function test_zero_invoice_has_identity_evidence_without_nonzero_movement(): void
    {
        $account = Square::create(['name' => 'Synthetic zero'])->subscribers()
            ->create(['name' => 'Synthetic zero subscriber', 'status' => 'active']);
        $registry = app(FinancialPostingRegistry::class);
        $source = app(FinancialLedgerEvidence::class);
        $this->gate(function (array $context) use ($account, $registry, $source): void {
            // Direct synthetic document fixture: tests ledger evidence, not water pricing.
            $invoice = Invoice::create([
                'subscriber_id' => $account->id, 'invoice_type' => 'meter',
                'bill_number' => 'INV-QA-ZERO-01', 'bill_date' => '2026-11-25',
                'billing_period_start' => '2026-11-01', 'billing_period_end' => '2026-11-25',
                'consumption_fee' => '0.00', 'previous_balance' => '0.00',
                'credit_applied' => '0.00', 'total_amount' => '0.00',
                'amount_paid' => '0.00', 'status' => 'issued',
            ]);
            $entry = app(TransactionService::class)->recordTransaction($account, 'invoice', '0.00', reference: $invoice);
            $leg = $source->leg((int) $entry->id);
            $this->assertSame(0, $leg['amount_minor']);
            $this->assertTrue($registry->record($context, self::UUID, [$leg], $this->nowAt()));
            $result = $registry->verifyPeriod('water', $context['period_id']);
            $this->assertSame(1, $result['events']);
            $this->assertFalse($result['source_coverage_verified']);
            try {
                $registry->record($context, self::OTHER, [array_replace($leg, ['source_type' => 'payment'])], $this->nowAt());
                $this->fail('Zero monetary payment leg accepted.');
            } catch (DomainException $e) {
                $this->assertSame('posting_minor', $e->getMessage());
            }
        });
        $this->assertSame(0, $account->fresh()->getAccountBalanceMinor());
        $this->assertSame('0', (string) DB::table('financial_posting_events')->value('amount_minor'));
    }

    public function test_missing_and_unsupported_source_are_not_proof(): void
    {
        $account = Square::create(['name' => 'Synthetic mismatched'])->subscribers()
            ->create(['name' => 'Synthetic account', 'status' => 'active']);
        $source = app(FinancialLedgerEvidence::class);
        try {
            $source->leg(1);
            $this->fail('Ungated evidence accepted.');
        } catch (DomainException $e) {
            $this->assertSame('ledger_evidence_requires_gate', $e->getMessage());
        }
        $this->gate(function () use ($account, $source): void {
            $entry = Transaction::create([
                'subscriber_id' => $account->id, 'type' => 'manual_adjustment', 'amount' => '1.00',
            ]);
            foreach ([(int) $entry->id => 'ledger_source_type_unsupported', 999999 => 'ledger_source_missing_or_deleted'] as $id => $expected) {
                try {
                    $source->leg($id);
                    $this->fail('Unsupported evidence accepted.');
                } catch (DomainException $e) {
                    $this->assertSame($expected, $e->getMessage());
                }
            }
        });
        $this->assertSame(0, DB::table('financial_posting_events')->count());
    }
}
