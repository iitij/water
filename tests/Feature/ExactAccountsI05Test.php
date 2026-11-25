<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\Transaction;
use App\Services\AllocationSnapshotIntegrity;
use App\Services\FinancialReconciliationService;
use App\Services\PaymentAllocationReadService;
use App\Services\PaymentService;
use App\Support\AccountMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ExactAccountsI05Test extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as private refreshSyntheticDatabase;
    }

    public function refreshDatabase(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $driver = DB::getDriverName();
        $database = DB::connection()->getDatabaseName();
        $this->assertTrue(($driver === 'sqlite' && $database === ':memory:')
            || ($driver === 'mysql' && $database === 'water_test'));
        $this->refreshSyntheticDatabase();
    }

    private function account(): Subscriber
    {
        return Subscriber::create(['name' => 'Synthetic exact account',
            'square_id' => Square::create(['name' => 'I05 synthetic square'])->id, 'status' => 'active']);
    }

    private function revision(Subscriber $subscriber): object
    {
        return DB::table('payment_allocation_revisions')->where('subscriber_id', $subscriber->id)
            ->orderByDesc('revision')->first();
    }

    public function test_account_ledger_and_immutable_allocations_conserve_at_decimal12_limit(): void
    {
        $subscriber = $this->account();
        $service = app(PaymentService::class);
        $first = $service->collectPayment($subscriber, '9999999999.98');
        $last = $service->collectPayment($subscriber, '0.01');
        $this->assertSame(999999999999, $subscriber->getAccountBalanceMinor());
        $this->assertSame('9999999999.99', $subscriber->fresh()->balance);
        $revision = $this->revision($subscriber);
        $this->assertTrue(app(AllocationSnapshotIntegrity::class)->valid($revision));
        $this->assertSame(999999999999, (int) $revision->available_minor);
        $projection = app(PaymentAllocationReadService::class);
        $this->assertSame(999999999998, $projection->forPayment($first)['unallocated_minor']);
        $this->assertSame(1, $projection->forPayment($last)['unallocated_minor']);
        $audit = app(FinancialReconciliationService::class)->inspect($subscriber);
        $this->assertTrue($audit['matches']);
        $this->assertSame(999999999999, $audit['ledger_minor']);
        $this->assertSame(999999999999, $audit['document_balance_minor']);
        $this->assertTrue($projection->reconcile($subscriber)['matches']);
        $service->recalculateInvoices($subscriber);
        $this->assertSame($revision->id, $this->revision($subscriber)->id);
        $service->voidPayment($last, 'Synthetic I05 one-cent reversal');
        $this->assertSame(999999999998, $subscriber->getAccountBalanceMinor());
        $this->assertTrue($projection->reconcile($subscriber)['matches']);
        $this->assertTrue(app(AllocationSnapshotIntegrity::class)->valid($revision));
        $this->assertSame(999999999999, (int) DB::table('payment_allocation_revisions')->where('id', $revision->id)->value('available_minor'));
    }

    public function test_cancelled_ledger_creation_rolls_back_entire_receipt_and_allocation(): void
    {
        $subscriber = $this->account();
        Transaction::creating(static fn () => false);
        try {
            app(PaymentService::class)->collectPayment($subscriber, '0.01');
            $this->fail('Receipt survived cancelled ledger insertion.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Ledger posting was not persisted', $e->getMessage());
        }
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('payment_allocation_revisions', 0);
        $this->assertSame(0, $subscriber->getAccountBalanceMinor());
        $this->assertSame('0.00', $subscriber->fresh()->balance);
    }

    public function test_raw_decimal_boundary_rejects_fractional_minor_units_before_eloquent_cast(): void
    {
        // Explicit raw in-memory driver-value fixture; MySQL DECIMAL would round
        // before reading such a value, so do not claim to recover lost precision.
        $payment = new Payment();
        $payment->setRawAttributes(['amount' => '1.001'], true);
        try {
            AccountMoney::stored($payment);
            $this->fail('A malformed stored value was rounded into a valid receipt.');
        } catch (\InvalidArgumentException|\OverflowException $e) {
            $this->addToAssertionCount(1);
        }
        $payment->setRawAttributes(['amount' => '0.01'], true);
        $this->assertSame(1, AccountMoney::stored($payment));
    }

    public function test_snapshot_verifier_rejects_noncanonical_or_overflowing_revision_totals(): void
    {
        $subscriber = $this->account();
        app(PaymentService::class)->collectPayment($subscriber, '1.00');
        $revision = $this->revision($subscriber);
        $integrity = app(AllocationSnapshotIntegrity::class);
        $this->assertTrue($integrity->valid($revision));
        foreach (['9223372036854775808', '100.0', -1, 100.0, null] as $raw) {
            $invalid = clone $revision;
            $invalid->available_minor = $raw;
            $this->assertFalse($integrity->valid($invalid));
        }
        $this->assertTrue($integrity->valid($this->revision($subscriber)));
        $this->assertSame($revision->id, $this->revision($subscriber)->id);
        $this->assertSame(100, $subscriber->getAccountBalanceMinor());
    }
}
