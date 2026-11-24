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
        \App\Models\Tariff::create(['name' => 'Synthetic baseline tariff', 'unit_price' => 600, 'is_active' => true]);
    }

    private function fixture(): Meter
    {
        $subscriber = Subscriber::create([
            'name' => 'Synthetic concurrency',
            'square_id' => Square::create(['name' => 'Concurrency'])->id,
            'status' => 'active',
        ]);
        $meter = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'CONC-'.$subscriber->id]);
        $this->createMeasuredReading([
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

    public function test_parallel_observations_on_same_date_commit_only_once(): void
    {
        $meter = $this->fixture();
        $record = fn () => Reading::create([
            'meter_id' => $meter->id,
            'reading_date' => '2026-03-01',
            'current_reading' => '15.25',
        ])->id;
        $results = $this->race($record, $record);
        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        $this->assertSame(ValidationException::class, collect($results)->firstWhere('ok', false)['error']);
        $this->assertSame(2, Reading::count());
        $next = Reading::latest('id')->firstOrFail();
        $this->assertSame('2026-02-01', $next->period_start);
        $this->assertSame('2026-03-01', $next->period_end);
        $this->assertSame('5.25', $next->consumption_units);
    }

    public function test_concurrent_identical_mobile_reading_retries_return_one_observation(): void
    {
        $meter = $this->fixture();
        $user = User::factory()->create(['role' => 'admin']);
        \App\Models\Setting::set('auto_issue_invoice_on_reading', '0');
        $payload = [
            'meter_id' => $meter->id,
            'reading_date' => '2026-03-01',
            'current_reading' => '15.25',
            'sync_uuid' => (string) Str::uuid(),
        ];
        $submit = function () use ($user, $payload): array {
            $response = $this->actingAs(User::findOrFail($user->id), 'sanctum')
                ->postJson('/api/readings', $payload);

            return ['status' => $response->status(), 'id' => $response->json('reading.id'),
                'duplicate' => $response->json('duplicate')];
        };
        $results = $this->race($submit, $submit);
        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
        $statuses = array_column(array_column($results, 'value'), 'status');
        sort($statuses);
        $this->assertSame([200, 201], $statuses, json_encode($results));
        $this->assertSame($results[0]['value']['id'], $results[1]['value']['id']);
        $this->assertNotSame($results[0]['value']['duplicate'], $results[1]['value']['duplicate']);
        $this->assertSame(1, Reading::where('sync_uuid', $payload['sync_uuid'])->count());
        $this->assertSame(2, Reading::count());
        $this->assertSame('5.25', Reading::where('sync_uuid', $payload['sync_uuid'])->firstOrFail()->consumption_units);
        $this->assertSame(0, Invoice::count());
    }

    public function test_concurrent_mobile_readings_with_same_uuid_and_different_values_conflict(): void
    {
        $meter = $this->fixture();
        $user = User::factory()->create(['role' => 'admin']);
        \App\Models\Setting::set('auto_issue_invoice_on_reading', '0');
        $payload = [
            'meter_id' => $meter->id,
            'reading_date' => '2026-03-01',
            'sync_uuid' => (string) Str::uuid(),
        ];
        $submit = fn (string $value) => $this->actingAs(User::findOrFail($user->id), 'sanctum')
            ->postJson('/api/readings', $payload + ['current_reading' => $value])->status();
        $results = $this->race(fn () => $submit('15.25'), fn () => $submit('16.25'));
        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
        $statuses = array_column($results, 'value');
        sort($statuses);
        $this->assertSame([201, 409], $statuses, json_encode($results));
        $this->assertSame(1, Reading::where('sync_uuid', $payload['sync_uuid'])->count());
        $this->assertSame(2, Reading::count());
        $this->assertSame(0, Invoice::count());
    }

    public function test_parallel_mobile_readings_on_different_meters_cannot_share_uuid(): void
    {
        $first = $this->fixture();
        $second = $this->fixture();
        $user = User::factory()->create(['role' => 'admin']);
        \App\Models\Setting::set('auto_issue_invoice_on_reading', '0');
        $uuid = (string) Str::uuid();
        $submit = function (Meter $meter) use ($user, $uuid): int {
            return $this->actingAs(User::findOrFail($user->id), 'sanctum')
                ->postJson('/api/readings', [
                    'meter_id' => $meter->id,
                    'reading_date' => '2026-03-01',
                    'current_reading' => '15.25',
                    'sync_uuid' => $uuid,
                ])->status();
        };
        $results = $this->race(fn () => $submit($first), fn () => $submit($second));
        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
        $statuses = array_column($results, 'value');
        sort($statuses);
        $this->assertSame([201, 409], $statuses, json_encode($results));
        $this->assertSame(1, Reading::where('sync_uuid', $uuid)->count());
        $this->assertSame(3, Reading::count());
        $this->assertSame('5.25', Reading::where('sync_uuid', $uuid)->firstOrFail()->consumption_units);
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, Transaction::count());
    }

    public function test_parallel_first_observation_and_replacement_never_erase_committed_usage(): void
    {
        $subscriber = Subscriber::create([
            'name' => 'Replacement race',
            'square_id' => Square::create(['name' => 'Replacement race'])->id,
            'status' => 'active',
        ]);
        $meter = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'RACE-REPLACE',
            'installed_at' => '2026-01-01', 'initial_reading' => 0]);
        $results = $this->race(
            fn () => Reading::create(['meter_id' => $meter->id,
                'reading_date' => '2026-02-01', 'current_reading' => '7.25'])->id,
            fn () => app(\App\Services\SingleMeterService::class)->replace($meter,
                ['unique_code' => 'RACE-REPLACE-NEW'], '2026-02-02', 'Synthetic concurrent replacement')['current_meter']->id,
        );
        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        $this->assertSame(1, Meter::where('subscriber_id', $subscriber->id)->count());
        if ($results[0]['ok']) {
            $this->assertSame(ValidationException::class, $results[1]['error']);
            $this->assertSame($meter->id, $subscriber->fresh()->currentMeter->id);
            $this->assertSame('7.25', Reading::findOrFail($results[0]['value'])->consumption_units);
        } else {
            $this->assertDatabaseMissing('meters', ['id' => $meter->id]);
            $this->assertSame($results[1]['value'], $subscriber->fresh()->currentMeter->id);
            $this->assertSame(0, Reading::count());
        }
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, Transaction::count());
    }

    public function test_parallel_observation_and_invoice_preserve_quantity_and_ledger(): void
    {
        $meter = $this->fixture();
        $results = $this->race(
            fn () => Reading::create(['meter_id' => $meter->id,
                'reading_date' => '2026-03-01', 'current_reading' => '15.25'])->id,
            fn () => app(InvoiceService::class)->generateInvoice(
                Subscriber::findOrFail($meter->subscriber_id), Meter::findOrFail($meter->id))->id,
        );
        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
        $this->assertSame(2, Reading::count());
        if (Reading::where('is_invoiced', false)->exists()) {
            app(InvoiceService::class)->generateInvoice($meter->subscriber, $meter);
        }
        $this->assertSame(9150.0, $meter->subscriber->getAccountDebt());
        $this->assertEquals(15.25, DB::table('invoice_tariff_lines')->sum('units'));
        $this->assertSame(2, DB::table('invoice_tariff_lines')->count());
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

    public function test_parallel_payments_for_different_subscribers_cannot_share_uuid(): void
    {
        $first = $this->fixture();
        $second = $this->fixture();
        $uuid = (string) Str::uuid();
        $collect = fn (Meter $meter) => app(PaymentService::class)->collectPayment(
            Subscriber::findOrFail($meter->subscriber_id), 1500.25, syncUuid: $uuid)->id;
        $results = $this->race(fn () => $collect($first), fn () => $collect($second));
        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        $this->assertSame(\Symfony\Component\HttpKernel\Exception\HttpException::class,
            collect($results)->firstWhere('ok', false)['error']);
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, Transaction::count());
        $this->assertEquals(1500.25, $first->subscriber->getAccountCredit() + $second->subscriber->getAccountCredit());
    }

    public function test_parallel_distinct_payments_get_unique_receipt_numbers_and_ledger_entries(): void
    {
        $first = $this->fixture();
        $second = $this->fixture();
        $collect = fn (Meter $meter) => app(PaymentService::class)->collectPayment(
            Subscriber::findOrFail($meter->subscriber_id), 1500.25, syncUuid: (string) Str::uuid())->id;
        $results = $this->race(fn () => $collect($first), fn () => $collect($second));
        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
        $this->assertSame(2, Payment::count());
        $this->assertSame(2, Payment::distinct()->count('receipt_number'));
        $this->assertSame(2, Transaction::count());
        $this->assertSame(1500.25, $first->subscriber->getAccountCredit());
        $this->assertSame(1500.25, $second->subscriber->getAccountCredit());
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

    public function test_parallel_overlapping_tariff_approvals_only_commit_once(): void
    {
        \App\Models\Tariff::firstOrFail()->update(['end_date' => '2026-01-31']);
        $approve = fn () => \App\Models\Tariff::create([
            'name' => 'Concurrent approval',
            'unit_price' => 800,
            'is_active' => true,
            'start_date' => '2026-02-01',
        ])->id;
        $results = $this->race($approve, $approve);
        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        $this->assertSame(ValidationException::class, collect($results)->firstWhere('ok', false)['error']);
        $this->assertSame(2, \App\Models\Tariff::where('is_active', true)->count());
    }

    public function test_parallel_invoice_and_tariff_repricing_preserve_pricing_evidence(): void
    {
        $meter = $this->fixture();
        $tariffId = \App\Models\Tariff::firstOrFail()->id;
        $results = $this->race(
            fn () => app(InvoiceService::class)->generateInvoice(
                Subscriber::findOrFail($meter->subscriber_id), Meter::findOrFail($meter->id))->id,
            fn () => \App\Models\Tariff::findOrFail($tariffId)->update(['unit_price' => 800]),
        );
        $this->assertTrue($results[0]['ok'], json_encode($results));
        if (! $results[1]['ok']) {
            $this->assertSame(ValidationException::class, $results[1]['error']);
        }
        $expected = $results[1]['ok'] ? '8000.00' : '6000.00';
        $this->assertSame($expected, Invoice::firstOrFail()->total_amount);
        $this->assertEquals((float) $expected, (float) DB::table('invoice_tariff_lines')->sole()->amount);
        $this->assertSame($results[1]['ok'] ? '800.00' : '600.00',
            \App\Models\Tariff::findOrFail($tariffId)->unit_price);
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
