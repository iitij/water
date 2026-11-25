<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\FinancialPostingRegistry;
use App\Services\FinancialWriteGate;
use App\Models\User;
use App\Services\TreasuryRegisterService;
use App\Services\TreasuryTransferService;
use App\Services\TreasuryTransferEvidence;
use DomainException;
use Spatie\Permission\Models\Role;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TreasuryTransferEvidenceTest extends TestCase
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

    public function test_actual_dispatch_and_receipt_exact_evidence_and_atomic_rollback(): void
    {
        $sender = $this->actor();
        $receiver = $this->actor();
        $from = $this->account($sender, 'QA-EV-FROM', '100.00');
        $to = $this->account($sender, 'QA-EV-TO', '0.00');
        $service = app(TreasuryTransferService::class);
        $evidence = app(TreasuryTransferEvidence::class);
        $registry = app(FinancialPostingRegistry::class);

        [$id, $out] = $this->gate(function (array $context) use ($sender, $from, $to, $service, $evidence, $registry): array {
            $sent = $service->dispatch($sender, [
                'from_account_id' => $from['id'], 'to_account_id' => $to['id'],
                'amount' => '10.25', 'reference' => 'QA-EV-DISPATCH', 'dispatched_on' => '2026-11-25',
                'evidence' => 'Synthetic independently witnessed dispatch',
            ]);
            $this->assertSame(1025, $sent['in_transit_minor']);
            $posting = DB::table('treasury_postings')->where('treasury_transfer_id', $sent['id'])->sole();
            $leg = $evidence->leg((int) $posting->id);
            $this->assertSame(-1025, $leg['amount_minor']);
            $this->assertSame('treasury_posting', $leg['source_type']);
            $this->assertSame('treasury', $leg['leg']);
            $this->assertTrue($registry->record($context, self::UUID, [$leg], $this->nowAt()));
            return [$sent['id'], $leg];
        });

        $receive = function (array $context) use ($receiver, $id, $out, $service, $evidence, $registry): void {
            $received = $service->receive($receiver, $id, [
                'amount' => '10.25', 'reference' => 'QA-EV-RECEIPT', 'received_on' => '2026-11-25',
                'evidence' => 'Synthetic independently witnessed receipt',
            ]);
            $this->assertSame('received', $received['status']);
            $this->assertSame(0, $received['in_transit_minor']);
            $posting = DB::table('treasury_postings')->where('treasury_transfer_receipt_id', $received['receipt']['id'])->sole();
            $in = $evidence->leg((int) $posting->id);
            $this->assertSame(1025, $in['amount_minor']);
            $this->assertSame(0, $out['amount_minor'] + $in['amount_minor']);
            $this->assertNotSame($out['source_id'], $in['source_id']);
            $this->assertSame($out, $evidence->leg((int) $out['source_id']));
            $this->assertTrue($registry->record($context, self::OTHER, [$in], $this->nowAt()));
        };
        try {
            $this->gate(function (array $context) use ($receive): void {
                $receive($context);
                throw new \RuntimeException('synthetic treasury receipt rollback');
            });
            $this->fail('Expected outer rollback.');
        } catch (\RuntimeException $error) {
            $this->assertSame('synthetic treasury receipt rollback', $error->getMessage());
        }
        $this->assertDatabaseCount('treasury_transfer_receipts', 0);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertDatabaseCount('financial_posting_operations', 1);
        $this->assertDatabaseCount('financial_posting_events', 1);

        $this->gate($receive);
        $this->assertDatabaseCount('treasury_transfer_receipts', 1);
        $this->assertDatabaseCount('treasury_postings', 2);
        $this->assertDatabaseCount('payments', 0);
        $this->gate(function (array $context) use ($registry): void {
            $verified = $registry->verifyPeriod('water', $context['period_id']);
            $this->assertSame(2, $verified['operations']);
            $this->assertSame(2, $verified['events']);
            $this->assertFalse($verified['source_coverage_verified']);
        });
    }

    public function test_missing_source_and_absent_outer_transaction_are_rejected(): void
    {
        $evidence = app(TreasuryTransferEvidence::class);
        try {
            $evidence->leg(1);
            $this->fail('Expected rejection without an outer transaction.');
        } catch (DomainException $error) {
            $this->assertSame('treasury_evidence_requires_gate', $error->getMessage());
        }
        $this->gate(function () use ($evidence): void {
            try {
                $evidence->leg(99999);
                $this->fail('Expected missing source rejection.');
            } catch (DomainException $error) {
                $this->assertSame('treasury_evidence_missing', $error->getMessage());
            }
        });
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertDatabaseCount('financial_posting_events', 0);
    }
}
