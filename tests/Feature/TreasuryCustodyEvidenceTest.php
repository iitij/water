<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\FinancialPostingRegistry;
use App\Services\FinancialWriteGate;
use App\Models\User;
use App\Services\TreasuryRegisterService;
use App\Services\TreasuryCustodyEvidence;
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

final class TreasuryCustodyEvidenceTest extends TestCase
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

    public function test_actual_handover_registry_evidence_is_stable_after_later_delivery(): void
    {
        $admin = $this->actor();
        $collector = User::factory()->create(['role' => 'admin']);
        $account = $this->account($admin, 'QA-CUSTODY', '100.00');
        $sessions = app(CashSessionService::class);
        $session = $sessions->openFunded($collector, $admin, $account['id'], '20.25',
            'QA-CUSTODY-FUND', 'Synthetic actual handover to collector');
        $session = $sessions->decide($sessions->close($session, '20.25'), true, $admin);
        $registry = app(FinancialPostingRegistry::class);
        $evidence = app(TreasuryCustodyEvidence::class);
        $first = $this->gate(function (array $context) use ($session, $admin, $account, $registry, $evidence): array {
            $handover = app(CashHandoverService::class)->record($session, $admin, '10.13',
                'QA-CUSTODY-H1', '2026-11-25', 'Verified independent physical receipt');
            $posting = app(TreasuryRegisterService::class)->post($admin, $account['id'], 'cash_handover', $handover->id);
            $leg = $evidence->leg((int) $posting['id']);
            $this->assertSame(1013, $leg['amount_minor']);
            $this->assertTrue($registry->record($context, self::UUID, [$leg], $this->nowAt()));
            return $leg;
        });
        $this->gate(function (array $context) use ($session, $admin, $account, $registry, $evidence, $first): void {
            $handover = app(CashHandoverService::class)->record($session, $admin, '10.12',
                'QA-CUSTODY-H2', '2026-11-25', 'Verified independent physical receipt');
            $posting = app(TreasuryRegisterService::class)->post($admin, $account['id'], 'cash_handover', $handover->id);
            $leg = $evidence->leg((int) $posting['id']);
            $this->assertSame(1012, $leg['amount_minor']);
            $this->assertSame($first, $evidence->leg((int) $first['source_id']));
            $this->assertTrue($registry->record($context, self::OTHER, [$leg], $this->nowAt()));
            $result = app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']);
            $this->assertSame(2, $result['registered_treasury_legs_verified']);
            $this->assertFalse($result['source_coverage_verified']);
        });
        $this->assertDatabaseCount('cash_handovers', 2);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_actual_refund_evidence_and_registry_rollback_then_exact_posting(): void
    {
        $admin = $this->actor();
        $account = $this->account($admin, 'QA-REFUND', '100.00');
        $subscriber = Square::create(['name' => 'Synthetic custody square'])->subscribers()
            ->create(['name' => 'Synthetic custody account', 'status' => 'active']);
        $payment = app(PaymentService::class)->collectPayment($subscriber, '10.25');
        $registry = app(FinancialPostingRegistry::class);
        $post = function (array $context) use ($payment, $admin, $account, $registry): void {
            $refund = app(PaymentRefundService::class)->record($payment, $admin, '2.13',
                'QA-CUSTODY-REF', 'treasury_cash', '2026-11-25',
                'Synthetic refund of excess credit', 'Verified actual cash disbursement');
            $posting = app(TreasuryRegisterService::class)->post($admin, $account['id'], 'payment_refund', $refund->id);
            $leg = app(TreasuryCustodyEvidence::class)->leg((int) $posting['id']);
            $this->assertSame(-213, $leg['amount_minor']);
            $this->assertTrue($registry->record($context, self::UUID, [$leg], $this->nowAt()));
            $result = app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']);
            $this->assertSame(1, $result['registered_treasury_legs_verified']);
            $this->assertFalse($result['source_coverage_verified']);
        };
        try {
            $this->gate(function (array $context) use ($post): void {
                $post($context);
                throw new \RuntimeException('synthetic custody rollback');
            });
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $error) {
            $this->assertSame('synthetic custody rollback', $error->getMessage());
        }
        $this->assertSame(1025, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('payment_refunds', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertDatabaseCount('financial_posting_events', 0);
        $this->assertDatabaseCount('transactions', 1);
        $this->gate($post);
        $this->assertSame(812, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('payment_refunds', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertDatabaseCount('transactions', 2);
    }
}
