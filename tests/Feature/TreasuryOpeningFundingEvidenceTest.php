<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\FinancialPostingRegistry;
use App\Services\FinancialWriteGate;
use App\Models\User;
use App\Services\TreasuryRegisterService;
use App\Services\TreasuryOpeningFundingEvidence;
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

final class TreasuryOpeningFundingEvidenceTest extends TestCase
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

    public function test_zero_and_positive_opening_are_distinct_from_cash_movement(): void
    {
        $actor = $this->actor();
        $zero = $this->account($actor, 'QA-OPEN-ZERO', '0.00');
        $positive = $this->account($actor, 'QA-OPEN-POS', '999999999999.99');
        $this->gate(function (array $context) use ($zero, $positive): void {
            $source = app(TreasuryOpeningFundingEvidence::class);
            $registry = app(FinancialPostingRegistry::class);
            $a = $source->opening((int) $zero['id'], $context['effective_on']);
            $b = $source->opening((int) $positive['id'], $context['effective_on']);
            $this->assertSame(0, $a['amount_minor']);
            $this->assertSame(99999999999999, $b['amount_minor']);
            $this->assertSame('treasury_opening', $a['source_type']);
            $this->assertTrue($registry->record($context, self::UUID, [$a, $b], $this->nowAt()));
            $result = app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']);
            $this->assertSame(2, $result['registered_opening_legs_verified']);
            $this->assertSame(0, $result['registered_treasury_legs_verified']);
            $this->assertFalse($result['source_coverage_verified']);
            try {
                $registry->record($context, self::OTHER, [array_replace($a, ['source_type' => 'treasury_posting', 'leg' => 'treasury'])], $this->nowAt());
                $this->fail('Zero cash movement accepted.');
            } catch (DomainException $error) {
                $this->assertSame('posting_minor', $error->getMessage());
            }
        });
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_funding_evidence_is_stable_after_session_approval_and_rolls_back_atomically(): void
    {
        $issuer = $this->actor();
        $collector = User::factory()->create(['role' => 'admin']);
        $account = $this->account($issuer, 'QA-OPEN-FUND', '100.00');
        $sessions = app(CashSessionService::class);
        $source = app(TreasuryOpeningFundingEvidence::class);
        $registry = app(FinancialPostingRegistry::class);
        $post = function (array $context) use ($issuer, $collector, $account, $sessions, $source, $registry): array {
            $session = $sessions->openFunded($collector, $issuer, $account['id'], '10.25',
                'QA-FUND-REF', 'Synthetic independent physical funding');
            $funding = DB::table('cash_session_fundings')->where('cash_session_id', $session->id)->sole();
            $posting = DB::table('treasury_postings')->where('cash_session_funding_id', $funding->id)->sole();
            $leg = $source->funding((int) $posting->id, $context['effective_on']);
            $this->assertSame(-1025, $leg['amount_minor']);
            $this->assertTrue($registry->record($context, self::UUID, [$leg], $this->nowAt()));
            return [$session, $leg];
        };
        try {
            $this->gate(function (array $context) use ($post): void {
                $post($context);
                throw new \RuntimeException('synthetic funding rollback');
            });
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $error) {
            $this->assertSame('synthetic funding rollback', $error->getMessage());
        }
        $this->assertDatabaseCount('cash_sessions', 0);
        $this->assertDatabaseCount('cash_session_fundings', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertDatabaseCount('financial_posting_events', 0);
        [$session, $leg] = $this->gate($post);
        $sessions->decide($sessions->close($session, '10.25'), true, $issuer);
        $this->gate(function (array $context) use ($source, $leg): void {
            $this->assertSame($leg, $source->funding((int) $leg['source_id'], $context['effective_on']));
            $result = app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']);
            $this->assertSame(1, $result['registered_treasury_legs_verified']);
            $this->assertSame(0, $result['registered_opening_legs_verified']);
            $this->assertFalse($result['source_coverage_verified']);
        });
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_opening_date_and_false_hash_are_rejected(): void
    {
        $actor = $this->actor();
        $account = $this->account($actor, 'QA-OPEN-BAD', '1.00');
        $this->gate(function (array $context) use ($account): void {
            $source = app(TreasuryOpeningFundingEvidence::class);
            try {
                $source->opening((int) $account['id'], '2026-11-24');
                $this->fail('Wrong opening date accepted.');
            } catch (DomainException $error) {
                $this->assertSame('treasury_opening_source_mismatch', $error->getMessage());
            }
            $leg = $source->opening((int) $account['id'], $context['effective_on']);
            $leg['source_hash'] = str_repeat('0', 64);
            $registry = app(FinancialPostingRegistry::class);
            $this->assertTrue($registry->record($context, self::UUID, [$leg], $this->nowAt()));
            $this->assertSame(1, $registry->verifyPeriod('water', $context['period_id'])['events']);
            try {
                app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']);
                $this->fail('False opening proof accepted.');
            } catch (DomainException $error) {
                $this->assertSame('ledger_registry_source_mismatch', $error->getMessage());
            }
        });
    }
}
