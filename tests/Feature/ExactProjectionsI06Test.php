<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\Tariff;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\PaymentRefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ExactProjectionsI06Test extends TestCase
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
        return Subscriber::create(['name' => 'Synthetic I06 projection account',
            'square_id' => Square::create(['name' => 'I06 square'])->id, 'status' => 'active']);
    }

    private function actor(): User
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));
        return $actor;
    }

    public function test_own_and_cumulative_invoice_amounts_and_api_summary_are_exact(): void
    {
        $subscriber = $this->account();
        $actor = $this->actor();
        Tariff::create(['name' => 'I06 tariff', 'unit_price' => '2.00', 'is_active' => true,
            'start_date' => '2025-12-01']);
        $meter = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'I06-METER', 'initial_reading' => 0]);
        $service = app(InvoiceService::class);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-02-01',
            'period_start' => '2026-01-01', 'period_end' => '2026-02-01', 'previous_reading' => 0, 'current_reading' => 1]);
        $first = $service->generateInvoice($subscriber, $meter);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-03-01',
            'period_start' => '2026-02-01', 'period_end' => '2026-03-01', 'previous_reading' => 1, 'current_reading' => 3]);
        $second = $service->generateInvoice($subscriber, $meter);
        app(PaymentService::class)->collectPayment($subscriber, '0.01', $first->id);
        $first = $first->fresh();
        $second = $second->fresh();
        $this->assertSame(199, $first->getRemainingMinor());
        $this->assertSame(400, $second->getRemainingMinor());
        $this->assertSame(599, $second->getTrueRemainingMinor());
        $this->assertSame(199, $first->getCollectibleMinor());
        $this->assertSame(599, $second->getCollectibleMinor());
        $this->assertSame(-599, $subscriber->getAccountBalanceMinor());
        $base = '/api/subscribers/'.$subscriber->id;
        $this->actingAs($actor, 'sanctum')->getJson($base.'/summary')->assertOk()
            ->assertJsonPath('financial_summary.account_balance', -5.99)
            ->assertJsonPath('financial_summary.total_debit_balance', 5.99);
        $this->getJson($base.'/unpaid-invoices-summary')->assertOk()
            ->assertJsonPath('total_remaining', 5.99)->assertJsonPath('outstanding_balance', 5.99)
            ->assertJsonPath('open_invoices_count', 2);
        $this->getJson($base.'/transactions')->assertOk()->assertJsonPath('0.running_balance', -5.99);
        $this->getJson($base.'/statement')->assertOk()->assertJsonPath('financial_summary.account_balance', -5.99);
        $this->assertDatabaseCount('transactions', 3);
    }

    public function test_statement_and_detail_show_exact_refund_aggregate_without_changing_ledger(): void
    {
        $subscriber = $this->account();
        $actor = $this->actor();
        $payment = app(PaymentService::class)->collectPayment($subscriber, '1.00');
        $refunds = app(PaymentRefundService::class);
        foreach (['0.10', '0.20'] as $i => $amount) {
            $refunds->record($payment->fresh(), $actor, $amount, 'I06-REF-'.$i, 'treasury_cash',
                today()->toDateString(), 'Synthetic I06 refund of excess credit', 'Synthetic independently verified cash disbursement');
        }
        $base = '/api/subscribers/'.$subscriber->id;
        $before = DB::table('transactions')->orderBy('id')->get()->toArray();
        $this->actingAs($actor, 'sanctum')->getJson($base.'/statement')->assertOk()
            ->assertJsonPath('transactions.0.refunded_amount', 0.30)
            ->assertJsonPath('financial_summary.account_balance', 0.70);
        $detail = $this->getJson($base)->assertOk();
        $this->assertSame(0.30, (float) $detail->json('payments.0.refunded_amount'));
        $this->assertSame(70, $subscriber->getAccountBalanceMinor());
        $this->assertEquals($before, DB::table('transactions')->orderBy('id')->get()->toArray());
    }

    public function test_invalid_invoice_allocation_does_not_become_zero_remaining(): void
    {
        $subscriber = $this->account();
        // Synthetic in-memory raw model fixture, not a rewrite of an issued document.
        $invoice = new Invoice();
        $invoice->setRawAttributes(['subscriber_id' => $subscriber->id, 'total_amount' => '1.00',
            'amount_paid' => '1.01', 'status' => 'issued'], true);
        try {
            $invoice->getRemainingMinor();
            $this->fail('Overpaid invoice was silently clamped to zero.');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('allocation', $error->getMessage());
        }
        $this->assertDatabaseCount('invoices', 0);
    }
}
