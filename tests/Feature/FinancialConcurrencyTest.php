<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\Reading;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashSessionService;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\TransactionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Real independent MySQL connections and concurrent processes.
 * No RefreshDatabase transaction: children must see committed fixtures.
 * Never run this destructive fixture setup outside the dedicated CI database.
 */
class FinancialConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing') || DB::connection()->getDriverName() !== 'mysql'
            || DB::connection()->getDatabaseName() !== 'water_test') {
            $this->markTestSkipped('Requires isolated MySQL water_test database.');
        }
        $this->assertTrue(function_exists('pcntl_fork'), 'MySQL concurrency CI requires pcntl.');
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }

    private function fixture(): Meter
    {
        $subscriber = Subscriber::create([
            'name' => 'Synthetic concurrency',
            'square_id' => Square::create(['name' => 'Concurrency'])->id,
            'status' => 'active',
        ]);
        $meter = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'CONC-'.$subscriber->id]);
        Reading::create([
            'meter_id' => $meter->id, 'reading_date' => '2026-02-01',
            'period_start' => '2026-01-01', 'period_end' => '2026-02-01',
            'previous_reading' => 0, 'current_reading' => 10,
        ]);
        return $meter;
    }

    private function race(callable $first, callable $second): array
    {
        $dir = sys_get_temp_dir().'/water-race-'.Str::uuid();
        mkdir($dir, 0700);
        DB::disconnect();
        $pids = [];
        try {
            foreach ([$first, $second] as $index => $callback) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('fork failed');
                }
                if ($pid === 0) {
                    DB::purge();
                    file_put_contents("$dir/ready-$index", '1');
                    $deadline = microtime(true) + 15;
                    while (! is_file("$dir/start")) {
                        if (microtime(true) > $deadline) exit(2);
                        usleep(1000);
                    }
                    try {
                        $result = ['ok' => true, 'value' => $callback()];
                    } catch (\Throwable $e) {
                        $result = ['ok' => false, 'error' => get_class($e), 'message' => $e->getMessage()];
                    }
                    file_put_contents("$dir/result-$index", json_encode($result, JSON_THROW_ON_ERROR));
                    DB::disconnect();
                    exit(0);
                }
                $pids[] = $pid;
            }
            $deadline = microtime(true) + 15;
            while (! is_file("$dir/ready-0") || ! is_file("$dir/ready-1")) {
                if (microtime(true) > $deadline) throw new \RuntimeException('workers not ready');
                usleep(1000);
            }
            touch("$dir/start");
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
            $pids = [];
            DB::purge();
            return [
                json_decode(file_get_contents("$dir/result-0"), true, 512, JSON_THROW_ON_ERROR),
                json_decode(file_get_contents("$dir/result-1"), true, 512, JSON_THROW_ON_ERROR),
            ];
        } finally {
            foreach ($pids as $pid) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
            foreach (glob("$dir/*") as $file) unlink($file);
            rmdir($dir);
        }
    }

    public function test_two_concurrent_invoice_requests_bill_the_reading_once(): void
    {
        $meter = $this->fixture();
        $generate = fn () => app(InvoiceService::class)->generateInvoice(
            Subscriber::findOrFail($meter->subscriber_id), Meter::findOrFail($meter->id))->id;
        $results = $this->race($generate, $generate);
        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        $failure = collect($results)->firstWhere('ok', false);
        $this->assertStringContainsString('لا توجد قراءات جديدة', $failure['message']);
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, Transaction::count());
        $this->assertSame(Invoice::first()->id, Reading::first()->invoice_id);
        $this->assertSame(6000.0, $meter->subscriber->getAccountDebt());
    }

    public function test_same_sync_uuid_concurrent_replay_creates_one_receipt_and_one_ledger_entry(): void
    {
        $meter = $this->fixture();
        $uuid = (string) Str::uuid();
        $collect = fn () => app(PaymentService::class)->collectPayment(
            Subscriber::findOrFail($meter->subscriber_id), 1500.25, syncUuid: $uuid)->id;
        $results = $this->race($collect, $collect);
        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
        $this->assertSame($results[0]['value'], $results[1]['value']);
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, Transaction::count());
        $this->assertSame(1500.25, $meter->subscriber->getAccountCredit());
    }

    public function test_invoice_and_collection_in_parallel_conserve_the_balance(): void
    {
        $meter = $this->fixture();
        $results = $this->race(
            fn () => app(InvoiceService::class)->generateInvoice(
                Subscriber::findOrFail($meter->subscriber_id), Meter::findOrFail($meter->id))->id,
            fn () => app(PaymentService::class)->collectPayment(
                Subscriber::findOrFail($meter->subscriber_id), 2500.25)->id,
        );
        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
        $this->assertSame('2500.25', Invoice::first()->amount_paid);
        $this->assertSame(3499.75, $meter->subscriber->getAccountDebt());
        $this->assertSame(2, Transaction::count());

        $payment = Payment::first();
        app(TransactionService::class)->recordTransaction(
            $meter->subscriber, 'payment', 2500.25, reference: $payment);
        $this->assertSame(2, Transaction::count());
    }

    public function test_parallel_cash_open_and_stale_close_are_rejected(): void
    {
        $user = User::factory()->create();
        $open = fn () => app(CashSessionService::class)->open(User::findOrFail($user->id))->id;
        $results = $this->race($open, $open);
        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        $this->assertSame(ValidationException::class, collect($results)->firstWhere('ok', false)['error']);
        $session = app(CashSessionService::class)->openSessionFor($user);
        $close = fn () => app(CashSessionService::class)->close($session, 0)->id;
        $results = $this->race($close, $close);
        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        $this->assertTrue($session->fresh()->isClosed());
    }
}
