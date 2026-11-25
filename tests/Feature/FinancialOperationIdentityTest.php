<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\FinancialPostingRegistry;
use App\Services\FinancialWriteGate;
use App\Models\User;
use App\Models\AuditEvent;
use App\Services\TreasuryRegisterService;
use App\Services\TreasuryTransferService;
use App\Services\TreasuryTransferEvidence;
use App\Services\GatedCashReceiptWriter;
use App\Services\PaymentService;
use App\Models\Subscriber;
use App\Models\Square;
use App\Models\Payment;
use App\Models\Transaction;
use App\Services\FinancialLedgerEvidence;
use App\Services\TreasuryCollectionEvidence;
use App\Services\GatedTreasuryOpeningFundingWriter;
use App\Services\NonCashClaimService;
use App\Services\LateReceiptReviewService;
use App\Services\BankReconciliationService;
use App\Services\CashSessionService;
use App\Services\FinancialLedgerRegistryVerifier;
use DomainException;
use Spatie\Permission\Models\Role;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class FinancialOperationIdentityTest extends TestCase
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
        $this->travelTo(\Carbon\Carbon::instance($this->nowAt()));
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

    private const THIRD = '33333333-3333-4333-8333-333333333333';
    private const EVIDENCE = 'Independent original clearance evidence';

    private function subscriber(): Subscriber
    {
        return Subscriber::create(['name' => 'Synthetic identity account',
            'square_id' => Square::create(['name' => 'Synthetic identity square'])->id, 'status' => 'active']);
    }

    private function opening(User $actor, string $uuid, string $channel = 'cash'): array
    {
        return app(GatedTreasuryOpeningFundingWriter::class)->open($actor, [
            'code' => 'QA-IDENTITY', 'name' => 'Synthetic treasury', 'channel' => $channel,
            'opening_on' => '2026-11-25', 'opening_amount' => '0.00',
            'opening_reference' => 'QA-IDENTITY-OPEN', 'opening_evidence' => self::EVIDENCE,
        ], $uuid, $this->nowAt());
    }

    private function claimData(Subscriber $subscriber, string $uuid): array
    {
        return ['sync_uuid' => $uuid, 'subscriber_id' => $subscriber->id, 'payment_method' => 'check',
            'amount' => '10.25', 'instrument_reference' => 'QA-IDENTITY-CHECK', 'instrument_on' => '2026-11-25'];
    }

    private function rejects(string $reason, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected rejection.');
        } catch (DomainException $error) {
            $this->assertSame($reason, $error->getMessage());
        }
    }

    private function conflict(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected conflict.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
    }

    public function test_pending_and_rejected_claim_uuid_cannot_be_consumed_by_opening(): void
    {
        $submitter = $this->actor();
        $reviewer = $this->actor();
        $subscriber = $this->subscriber();
        $service = app(NonCashClaimService::class);
        $claim = $service->submit($submitter, $this->claimData($subscriber, self::UUID));
        $this->rejects('financial_uuid_reserved', fn () => $this->opening($reviewer, self::UUID));
        $this->assertDatabaseCount('treasury_accounts', 0);
        $service->reject($reviewer, (int) $claim['id'], 'Independent rejected claim evidence');
        $this->rejects('financial_uuid_reserved', fn () => $this->opening($reviewer, self::UUID));
        $this->assertDatabaseCount('financial_posting_operations', 0);
        $this->assertDatabaseCount('treasury_accounts', 0);
        $this->assertSame('rejected', $service->submit($submitter, $this->claimData($subscriber, self::UUID))['status']);
        $this->assertTrue($this->opening($reviewer, self::OTHER)['registry_created']);
    }

    public function test_registered_opening_uuid_cannot_be_used_by_any_new_receipt_or_claim(): void
    {
        $actor = $this->actor();
        $reviewer = $this->actor();
        $subscriber = $this->subscriber();
        $this->opening($reviewer, self::UUID);
        $this->conflict(fn () => app(NonCashClaimService::class)->submit($actor, $this->claimData($subscriber, self::UUID)));
        $this->conflict(fn () => app(PaymentService::class)->collectPayment($subscriber, '10.25',
            userId: $actor->id, paymentDate: '2026-11-25', syncUuid: self::UUID));
        $sessions = app(CashSessionService::class);
        $session = $sessions->decide($sessions->close($sessions->open($actor), '0.00'), true, $reviewer);
        $this->conflict(fn () => app(LateReceiptReviewService::class)->submit($actor, [
            'sync_uuid' => self::UUID, 'cash_session_id' => $session->id, 'subscriber_id' => $subscriber->id,
            'payment_method' => 'cash', 'amount' => '10.25', 'payment_date' => '2026-11-25',
        ]));
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('noncash_collection_claims', 0);
        $this->assertDatabaseCount('late_receipt_reviews', 0);
        $this->assertDatabaseCount('financial_posting_operations', 1);
        $this->assertDatabaseCount('financial_posting_events', 1);
    }

    public function test_ordinary_cash_receipt_and_exact_replay_keep_same_owner(): void
    {
        $actor = $this->actor();
        $subscriber = $this->subscriber();
        $writer = app(GatedCashReceiptWriter::class);
        $first = $writer->collect($actor, $subscriber->id, '10.25', self::UUID, $this->nowAt());
        $again = $writer->collect($actor, $subscriber->id, '10.25', self::UUID, $this->nowAt());
        $this->assertTrue($first['registry_created']);
        $this->assertFalse($again['registry_created']);
        $this->assertSame($first['transaction_id'], $again['transaction_id']);
        $this->assertSame(1025, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('financial_posting_events', 1);
    }

    public function test_pending_late_review_reserves_uuid_against_unrelated_registry_operation(): void
    {
        $actor = $this->actor();
        $reviewer = $this->actor();
        $subscriber = $this->subscriber();
        $sessions = app(CashSessionService::class);
        $session = $sessions->decide($sessions->close($sessions->open($actor), '0.00'), true, $reviewer);
        $review = app(LateReceiptReviewService::class)->submit($actor, [
            'sync_uuid' => self::UUID, 'cash_session_id' => $session->id, 'subscriber_id' => $subscriber->id,
            'payment_method' => 'cash', 'amount' => '10.25', 'payment_date' => '2026-11-25',
        ]);
        $this->rejects('financial_uuid_reserved', fn () => $this->opening($reviewer, self::UUID));
        $this->assertDatabaseHas('late_receipt_reviews', ['id' => $review['id'], 'status' => 'pending', 'payment_id' => null]);
        $this->assertDatabaseCount('treasury_accounts', 0);
        $this->assertDatabaseCount('financial_posting_operations', 0);
    }

    public function test_cleared_claim_requires_both_exact_legs_and_can_replay_as_one_owner(): void
    {
        $submitter = $this->actor();
        $importer = $this->actor();
        $reviewer = $this->actor();
        $subscriber = $this->subscriber();
        $account = $this->opening($importer, self::OTHER, 'bank')['account'];
        $claim = app(NonCashClaimService::class)->submit($submitter, $this->claimData($subscriber, self::UUID));
        $statement = app(BankReconciliationService::class)->import($importer, (int) $account['id'], [
            'reference' => 'QA-IDENTITY-STATEMENT', 'from_date' => '2026-11-25', 'to_date' => '2026-11-25',
            'opening_amount' => '0.00', 'closing_amount' => '10.25', 'evidence' => self::EVIDENCE,
            'lines' => [['bank_reference' => 'QA-IDENTITY-CREDIT', 'booked_on' => '2026-11-25', 'amount' => '10.25']],
        ])['statement'];
        $line = DB::table('bank_statement_lines')->where('bank_statement_id', $statement['id'])->sole();
        $post = function (bool $both) use ($reviewer, $claim, $statement, $line): bool {
            return $this->gate(function (array $context) use ($reviewer, $claim, $statement, $line, $both): bool {
                $r = app(PaymentService::class)->clearNonCashClaim($reviewer, (int) $claim['id'],
                    (int) $statement['id'], (int) $line->id, 'QA-IDENTITY-CLEAR', self::EVIDENCE);
                $entry = Transaction::query()->where('reference_type', Payment::class)
                    ->where('reference_id', $r['payment_id'])->where('type', 'payment')->sole();
                $legs = [app(FinancialLedgerEvidence::class)->leg((int) $entry->id)];
                if ($both) $legs[] = app(TreasuryCollectionEvidence::class)->leg((int) $r['bank']['posting']['id']);
                return app(FinancialPostingRegistry::class)->record($context, self::UUID, $legs, $this->nowAt());
            });
        };
        $this->rejects('financial_uuid_effect_mismatch', fn () => $post(false));
        $this->assertDatabaseHas('noncash_collection_claims', ['id' => $claim['id'], 'status' => 'pending', 'payment_id' => null]);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertDatabaseCount('financial_posting_events', 1);
        $this->assertTrue($post(true));
        $this->assertFalse($post(true));
        $this->assertSame(1025, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('financial_posting_operations', 2);
        $this->assertDatabaseCount('financial_posting_events', 3);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
    }
}
