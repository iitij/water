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
use App\Models\PaymentAccountTransfer;
use App\Models\PaymentRefund;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\PaymentAccountTransferService;
use App\Services\PaymentRefundService;
use Spatie\Permission\Models\Role;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class FinancialLedgerCorrectionEvidenceTest extends TestCase
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

    private function actor(): User
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));

        return $actor;
    }

    private function leg(string $type, string $class, int $id): array
    {
        $entry = Transaction::where('type', $type)->where('reference_type', $class)
            ->where('reference_id', $id)->sole();
        return app(FinancialLedgerEvidence::class)->leg((int) $entry->id);
    }

    private function original(Subscriber $account): array
    {
        return $this->gate(function (array $context) use ($account): array {
            $payment = app(PaymentService::class)->collectPayment($account, '10.25');
            $leg = $this->leg('payment', Payment::class, $payment->id);
            $this->assertSame(1025, $leg['amount_minor']);
            $this->assertTrue(app(FinancialPostingRegistry::class)->record($context, self::UUID, [$leg], $this->nowAt()));
            return [$payment, $leg];
        });
    }

    private function verify(int $events): void
    {
        $this->gate(function (array $context) use ($events): void {
            $result = app(FinancialPostingRegistry::class)->verifyPeriod('water', $context['period_id']);
            $this->assertSame(2, $result['operations']);
            $this->assertSame($events, $result['events']);
            $this->assertFalse($result['source_coverage_verified']);
        });
    }

    public function test_refund_actual_document_evidence_is_exact_and_original_is_stable(): void
    {
        $account = $this->account();
        $actor = $this->actor();
        [$payment, $original] = $this->original($account);
        $this->gate(function (array $context) use ($payment, $actor, $original): void {
            $refund = app(PaymentRefundService::class)->record($payment, $actor, '2.13',
                'QA-EVIDENCE-REFUND', 'treasury_cash', now()->toDateString(),
                'Synthetic verified excess credit', 'Synthetic verified independent evidence');
            $leg = $this->leg('payment_refund', PaymentRefund::class, $refund->id);
            $this->assertSame(-213, $leg['amount_minor']);
            $this->assertSame($original, $this->leg('payment', Payment::class, $payment->id));
            $this->assertTrue(app(FinancialPostingRegistry::class)->record($context, self::OTHER, [$leg], $this->nowAt()));
        });
        $this->assertSame(812, $account->fresh()->getAccountBalanceMinor());
        $this->verify(2);
    }

    public function test_transfer_two_real_legs_conserve_and_registry_rollback_is_atomic(): void
    {
        $from = $this->account();
        $to = $this->account();
        $actor = $this->actor();
        [$payment, $original] = $this->original($from);
        $post = function (array $context) use ($payment, $to, $actor, $original): void {
            $transfer = app(PaymentAccountTransferService::class)->transfer($payment, $to->id, $actor,
                'Synthetic incorrect subscriber allocation', 'Synthetic verified payer identity evidence');
            $out = $this->leg('payment_reversal', Payment::class, $payment->id);
            $in = $this->leg('payment_transfer_in', PaymentAccountTransfer::class, $transfer->id);
            $this->assertSame(-1025, $out['amount_minor']);
            $this->assertSame(1025, $in['amount_minor']);
            $this->assertNotSame($out['source_id'], $in['source_id']);
            $this->assertSame($original, $this->leg('payment', Payment::class, $payment->id));
            $this->assertTrue(app(FinancialPostingRegistry::class)->record($context, self::OTHER, [$out, $in], $this->nowAt()));
        };
        try {
            $this->gate(function (array $context) use ($post): void {
                $post($context);
                throw new \RuntimeException('synthetic correction rollback');
            });
            $this->fail('Expected outer rollback.');
        } catch (\RuntimeException $e) {
            $this->assertSame('synthetic correction rollback', $e->getMessage());
        }
        $this->assertSame(1025, $from->fresh()->getAccountBalanceMinor());
        $this->assertSame(0, $to->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('payment_account_transfers', 0);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('financial_posting_operations', 1);
        $this->assertDatabaseCount('financial_posting_events', 1);
        $this->gate($post);
        $this->assertSame(0, $from->fresh()->getAccountBalanceMinor());
        $this->assertSame(1025, $to->fresh()->getAccountBalanceMinor());
        $this->verify(3);
    }

    public function test_void_actual_reversal_evidence_preserves_original_and_exact_balance(): void
    {
        $account = $this->account();
        $actor = $this->actor();
        Setting::set('void_approval_threshold', '0.00');
        [$payment, $original] = $this->original($account);
        $this->gate(function (array $context) use ($payment, $actor, $original): void {
            $result = app(PaymentService::class)->requestVoid($payment,
                'Synthetic documented cancellation request', $actor->id);
            $this->assertFalse($result['pending']);
            $this->assertTrue($payment->fresh()->isVoided());
            $leg = $this->leg('payment_reversal', Payment::class, $payment->id);
            $this->assertSame(-1025, $leg['amount_minor']);
            $this->assertSame($original, $this->leg('payment', Payment::class, $payment->id));
            $this->assertTrue(app(FinancialPostingRegistry::class)->record($context, self::OTHER, [$leg], $this->nowAt()));
        });
        $this->assertSame(0, $account->fresh()->getAccountBalanceMinor());
        $this->verify(2);
    }
}
