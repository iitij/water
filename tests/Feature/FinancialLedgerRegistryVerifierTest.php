<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Square;
use App\Models\Transaction;
use App\Services\FinancialLedgerEvidence;
use App\Services\FinancialPostingRegistry;
use App\Services\FinancialWriteGate;
use App\Services\PaymentService;
use App\Models\Subscriber;
use App\Services\FinancialLedgerRegistryVerifier;
use DomainException;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class FinancialLedgerRegistryVerifierTest extends TestCase
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

    private function account(): Subscriber
    {
        return Subscriber::create(['name' => 'Synthetic correction account',
            'square_id' => Square::create(['name' => 'Synthetic evidence'])->id, 'status' => 'active']);
    }

    private function leg(string $type, string $class, int $id): array
    {
        $entry = Transaction::where('type', $type)->where('reference_type', $class)
            ->where('reference_id', $id)->sole();
        return app(FinancialLedgerEvidence::class)->leg((int) $entry->id);
    }

    public function test_registry_consistency_is_distinct_from_source_verification_and_coverage(): void
    {
        $account = $this->account();
        $registry = app(FinancialPostingRegistry::class);
        $this->gate(function (array $context) use ($account, $registry): void {
            $payment = app(PaymentService::class)->collectPayment($account, '10.25');
            $leg = $this->leg('payment', Payment::class, $payment->id);
            $this->assertTrue($registry->record($context, self::UUID, [$leg], $this->nowAt()));
            // Explicit synthetic other-family leg, not a verified treasury source.
            $this->assertTrue($registry->record($context, self::OTHER, [[
                'source_type' => 'treasury_posting', 'source_id' => '1', 'leg' => 'treasury',
                'amount_minor' => 5, 'currency' => 'YER', 'source_hash' => str_repeat('a', 64),
            ]], $this->nowAt()));
            $before = $registry->verifyPeriod('water', $context['period_id']);
            $result = app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']);
            $this->assertSame($before['registry_hash'], $result['registry_hash']);
            $this->assertSame(1, $result['registered_ledger_legs_verified']);
            $this->assertSame(1, $result['other_registered_legs_unverified']);
            $this->assertFalse($result['source_coverage_verified']);
        });
        $this->assertSame(1025, $account->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('financial_posting_events', 2);
    }

    public function test_internally_consistent_but_incorrect_source_hash_is_rejected(): void
    {
        $account = $this->account();
        $this->gate(function (array $context) use ($account): void {
            $payment = app(PaymentService::class)->collectPayment($account, '10.25');
            $leg = $this->leg('payment', Payment::class, $payment->id);
            // Ordinary synthetic fixture at the untrusted registry interface.
            $leg['source_hash'] = str_repeat('0', 64);
            $registry = app(FinancialPostingRegistry::class);
            $this->assertTrue($registry->record($context, self::UUID, [$leg], $this->nowAt()));
            $this->assertSame(1, $registry->verifyPeriod('water', $context['period_id'])['events']);
            try {
                app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']);
                $this->fail('False source proof was accepted.');
            } catch (DomainException $e) {
                $this->assertSame('ledger_registry_source_mismatch', $e->getMessage());
            }
            $this->assertSame(1, DB::table('financial_posting_events')->count());
        });
    }

    public function test_noncanonical_ledger_identity_cannot_alias_real_transaction(): void
    {
        $account = $this->account();
        $this->gate(function (array $context) use ($account): void {
            $payment = app(PaymentService::class)->collectPayment($account, '10.25');
            $leg = $this->leg('payment', Payment::class, $payment->id);
            $leg['source_id'] = '0'.$leg['source_id'];
            $registry = app(FinancialPostingRegistry::class);
            $this->assertTrue($registry->record($context, self::UUID, [$leg], $this->nowAt()));
            $this->assertSame(1, $registry->verifyPeriod('water', $context['period_id'])['events']);
            try {
                app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']);
                $this->fail('Noncanonical source identity accepted.');
            } catch (DomainException $e) {
                $this->assertSame('ledger_registry_source_identity', $e->getMessage());
            }
        });
    }
}
