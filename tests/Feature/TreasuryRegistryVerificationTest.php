<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\FinancialPostingRegistry;
use App\Services\FinancialWriteGate;
use App\Models\User;
use App\Models\Subscriber;
use App\Models\Square;
use App\Models\Payment;
use App\Models\Transaction;
use App\Services\PaymentService;
use App\Services\FinancialLedgerEvidence;
use App\Services\FinancialLedgerRegistryVerifier;
use App\Services\TreasuryRegisterService;
use App\Services\TreasuryTransferService;
use App\Services\TreasuryTransferEvidence;
use DomainException;
use Spatie\Permission\Models\Role;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TreasuryRegistryVerificationTest extends TestCase
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

    private function dispatch(): array
    {
        $sender = $this->actor();
        $from = $this->account($sender, 'QA-REG-FROM', '100.00');
        $to = $this->account($sender, 'QA-REG-TO', '0.00');
        $sent = app(TreasuryTransferService::class)->dispatch($sender, [
            'from_account_id' => $from['id'], 'to_account_id' => $to['id'],
            'amount' => '10.25', 'reference' => 'QA-REG-DISPATCH', 'dispatched_on' => '2026-11-25',
            'evidence' => 'Synthetic independently witnessed dispatch',
        ]);
        $posting = DB::table('treasury_postings')->where('treasury_transfer_id', $sent['id'])->sole();

        return app(TreasuryTransferEvidence::class)->leg((int) $posting->id);
    }

    private function rejects(string $reason, callable $action): void
    {
        try {
            $action();
            $this->fail('Invalid source accepted.');
        } catch (DomainException $error) {
            $this->assertSame($reason, $error->getMessage());
        }
    }

    public function test_mixed_actual_sources_remain_incomplete(): void
    {
        $account = Square::create(['name' => 'Synthetic registry square'])->subscribers()
            ->create(['name' => 'Synthetic registry account', 'status' => 'active']);
        $this->gate(function (array $context) use ($account): void {
            $out = $this->dispatch();
            $payment = app(PaymentService::class)->collectPayment($account, '0.31');
            $entry = Transaction::where('reference_type', Payment::class)->where('reference_id', $payment->id)
                ->where('type', 'payment')->sole();
            $ledger = app(FinancialLedgerEvidence::class)->leg((int) $entry->id);
            $registry = app(FinancialPostingRegistry::class);
            $this->assertTrue($registry->record($context, self::UUID, [$out, $ledger], $this->nowAt()));
            $result = app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']);
            $this->assertSame(1, $result['registered_treasury_legs_verified']);
            $this->assertSame(1, $result['registered_ledger_legs_verified']);
            $this->assertSame(0, $result['other_registered_legs_unverified']);
            $this->assertFalse($result['source_coverage_verified']);
            $this->assertSame(2, $result['events']);
            $this->assertSame(-1025, $out['amount_minor']);
            $this->assertSame(31, $ledger['amount_minor']);
        });
    }

    public function test_consistent_registry_with_wrong_treasury_date_is_rejected(): void
    {
        $this->gate(function (array $context): void {
            $out = $this->dispatch();
            $registry = app(FinancialPostingRegistry::class);
            $wrong = array_replace($context, ['effective_on' => '2026-11-24']);
            $this->assertTrue($registry->record($wrong, self::UUID, [$out], $this->nowAt()));
            $this->assertSame(1, $registry->verifyPeriod('water', $context['period_id'])['events']);
            $this->rejects('treasury_registry_effective_date_mismatch',
                fn () => app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']));
        });
    }

    public function test_consistent_registry_with_wrong_treasury_hash_is_rejected(): void
    {
        $this->gate(function (array $context): void {
            $out = $this->dispatch();
            $out['source_hash'] = str_repeat('0', 64);
            $registry = app(FinancialPostingRegistry::class);
            $this->assertTrue($registry->record($context, self::UUID, [$out], $this->nowAt()));
            $this->assertSame(1, $registry->verifyPeriod('water', $context['period_id'])['events']);
            $this->rejects('ledger_registry_source_mismatch',
                fn () => app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']));
        });
    }

    public function test_nonexistent_treasury_source_is_not_treated_as_unsupported(): void
    {
        $this->gate(function (array $context): void {
            $registry = app(FinancialPostingRegistry::class);
            $this->assertTrue($registry->record($context, self::UUID, [[
                'source_type' => 'treasury_posting', 'source_id' => '99999', 'leg' => 'treasury',
                'amount_minor' => 1, 'currency' => 'YER', 'source_hash' => str_repeat('a', 64),
            ]], $this->nowAt()));
            $this->rejects('treasury_registry_source_missing',
                fn () => app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']));
        });
    }
}
