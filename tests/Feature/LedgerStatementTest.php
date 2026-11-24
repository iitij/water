<?php

namespace Tests\Feature;

use App\Models\Square;
use App\Models\Subscriber;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerStatementService;
use App\Services\LedgerStatementExportService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

final class LedgerStatementTest extends TestCase
{
    use RefreshDatabase;

    public function test_period_opening_reversal_closing_and_export_use_posting_date(): void
    {
        $subscriber = Subscriber::create(['name' => 'Synthetic statement', 'square_id' => Square::create(['name' => 'Statement'])->id, 'status' => 'active']);
        $payments = app(PaymentService::class);
        $this->travelTo(now()->setDate(2026, 2, 28)->setTime(23, 59, 59));
        $first = $payments->collectPayment($subscriber, 100.25, paymentDate: '2026-02-28');
        $this->travelTo(now()->setDate(2026, 3, 1)->startOfDay());
        $payments->collectPayment($subscriber, 50.75, paymentDate: '2026-02-28');
        $this->travelTo(now()->setDate(2026, 3, 31)->setTime(23, 59, 59));
        $payments->voidPayment($first, 'Synthetic reversal during next period');
        $this->travelTo(now()->setDate(2026, 4, 1)->startOfDay());
        $payments->collectPayment($subscriber, 25, paymentDate: '2026-04-01');
        $before = Transaction::orderBy('id')->get()->toArray();
        $service = app(LedgerStatementService::class);
        $data = $service->statement($subscriber, '2026-03-01', '2026-03-31');
        $this->assertSame(10025, $data['opening_balance_minor']);
        $this->assertSame(10025, $data['debit_minor']);
        $this->assertSame(5075, $data['credit_minor']);
        $this->assertSame(5075, $data['closing_balance_minor']);
        $this->assertSame([15100, 5075], array_column($data['entries'], 'running_balance_minor'));
        $this->assertSame(['payment', 'payment_reversal'], array_column($data['entries'], 'type'));
        $empty = $service->statement($subscriber, '2026-02-01', '2026-02-27');
        $this->assertSame(0, $empty['closing_balance_minor']);
        $empty = $service->statement($subscriber, '2026-03-02', '2026-03-30');
        $this->assertSame(15100, $empty['opening_balance_minor']);
        $this->assertSame(15100, $empty['closing_balance_minor']);
        $this->assertSame([], $empty['entries']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'sanctum')->getJson('/api/subscribers/'.$subscriber->id.'/ledger-statement?from_date=2026-03-01&to_date=2026-03-31')
            ->assertOk()->assertExactJson($data);
        $this->getJson('/api/subscribers/'.$subscriber->id.'/ledger-statement?from_date=2026-04-01&to_date=2026-03-01')->assertUnprocessable();
        $html = view('pdf.ledger_statement', ['statement' => $data])->render();
        $this->assertStringContainsString('100.25', $html);
        $this->assertStringContainsString('50.75', $html);
        $path = app(LedgerStatementExportService::class)->xlsx($data);
        try {
            $reader = new Reader();
            $reader->open($path);
            $rows = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) $rows[] = $row->toArray();
            }
            $reader->close();
            $this->assertEquals(100.25, $rows[3][1]);
            $this->assertEquals(50.75, $rows[6][1]);
            $this->assertEquals(50.75, $rows[9][7]);
        } finally {
            @unlink($path);
        }
        $this->assertSame($before, Transaction::orderBy('id')->get()->toArray());
    }

    public function test_undated_legacy_row_blocks_historical_statement_without_guessing(): void
    {
        $subscriber = Subscriber::create(['name' => 'Undated', 'square_id' => Square::create(['name' => 'Undated'])->id, 'status' => 'active']);
        DB::table('transactions')->insert(['subscriber_id' => $subscriber->id, 'type' => 'adjustment', 'amount' => 100]);
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum')
            ->getJson('/api/subscribers/'.$subscriber->id.'/ledger-statement?from_date=2026-01-01&to_date=2026-01-31')
            ->assertUnprocessable()->assertJsonValidationErrors('ledger');
        $this->assertDatabaseCount('transactions', 1);
    }
}
