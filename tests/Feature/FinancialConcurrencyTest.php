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
        // This test deliberately recreates the disposable database outside RefreshDatabase.
        // Force the next transactional test class to rebuild its own empty fixtures.
        \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;
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
            $deadline = microtime(true) + 30;
            while ($pids !== []) {
                foreach ($pids as $key => $pid) {
                    $waited = pcntl_waitpid($pid, $status, WNOHANG);
                    if ($waited === $pid) {
                        unset($pids[$key]);
                        $this->assertTrue(pcntl_wifexited($status));
                        $this->assertSame(0, pcntl_wexitstatus($status));
                    } elseif ($waited === -1) {
                        unset($pids[$key]);
                        throw new \RuntimeException('Could not join a concurrency worker.');
                    }
                }
                if ($pids !== [] && microtime(true) > $deadline) {
                    throw new \RuntimeException('Concurrency workers did not finish within the bound.');
                }
                usleep(1000);
            }
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

    public function test_parallel_first_observation_and_explicit_replacement_discard_only_unbilled_old_usage(): void
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
        $this->assertTrue($results[1]['ok'], json_encode($results));
        $this->assertSame(1, Meter::where('subscriber_id', $subscriber->id)->count());
        if (! $results[0]['ok']) {
            $this->assertContains($results[0]['error'], [
                ValidationException::class, \Illuminate\Database\Eloquent\ModelNotFoundException::class,
            ], json_encode($results));
        }
        $this->assertDatabaseMissing('meters', ['id' => $meter->id]);
        $this->assertSame($results[1]['value'], $subscriber->fresh()->currentMeter->id);
        $this->assertSame(0, Reading::count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, Transaction::count());
    }

    public function test_parallel_invoice_and_explicit_replacement_preserve_any_committed_financial_document(): void
    {
        $meter = $this->fixture();
        $subscriber = $meter->subscriber;
        $readingId = $meter->readings()->firstOrFail()->id;
        $results = $this->race(
            fn () => app(InvoiceService::class)->generateInvoice($subscriber, $meter)->id,
            fn () => app(\App\Services\SingleMeterService::class)->replace($meter,
                ['unique_code' => 'RACE-BILL-NEW'], '2026-03-02', 'Synthetic concurrent replacement')['current_meter']->id,
        );
        $this->assertTrue($results[1]['ok'], json_encode($results));
        $this->assertDatabaseMissing('meters', ['id' => $meter->id]);
        $this->assertSame($results[1]['value'], $subscriber->fresh()->currentMeter->id);
        $this->assertSame(1, Meter::where('subscriber_id', $subscriber->id)->count());
        if ($results[0]['ok']) {
            $invoiceId = $results[0]['value'];
            $this->assertDatabaseHas('invoices', ['id' => $invoiceId, 'meter_id' => null,
                'retired_meter_id' => $meter->id, 'total_amount' => '6000.00']);
            $this->assertDatabaseHas('readings', ['id' => $readingId, 'meter_id' => null,
                'retired_meter_id' => $meter->id, 'invoice_id' => $invoiceId]);
            $this->assertDatabaseHas('invoice_tariff_lines', ['invoice_id' => $invoiceId, 'reading_id' => $readingId]);
            $this->assertDatabaseHas('transactions', ['subscriber_id' => $subscriber->id,
                'meter_id' => null, 'retired_meter_id' => $meter->id, 'amount' => '-6000.00']);
            $this->assertSame(-600000, app(TransactionService::class)->accountBalanceMinor($subscriber));
        } else {
            $this->assertContains($results[0]['error'], [
                ValidationException::class, \Illuminate\Database\Eloquent\ModelNotFoundException::class,
            ], json_encode($results));
            $this->assertDatabaseMissing('readings', ['id' => $readingId]);
            $this->assertDatabaseCount('invoices', 0);
            $this->assertDatabaseCount('invoice_tariff_lines', 0);
            $this->assertDatabaseCount('transactions', 0);
        }
    }

    public function test_round9_pending_review_committed_after_snapshot_blocks_meter_deletion(): void
    {
        $subscriber = Subscriber::create([
            'name' => 'Synthetic current-read guard',
            'square_id' => Square::create(['name' => 'Current read guard'])->id,
            'status' => 'active',
        ]);
        $meter = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'REVIEW-SNAPSHOT']);
        $collector = User::factory()->create(['role' => 'admin']);
        $sessionId = DB::table('cash_sessions')->insertGetId([
            'user_id' => $collector->id, 'session_date' => '2026-02-01', 'state' => 'closed',
            'opening_amount' => 0, 'expected_amount' => 0, 'counted_amount' => 0, 'variance_amount' => 0,
        ]);
        $uuid = (string) Str::uuid();
        $dir = sys_get_temp_dir().'/water-review-current-read-'.Str::uuid();
        mkdir($dir, 0700);
        $await = static function (string $path): void {
            $deadline = microtime(true) + 10;
            while (! is_file($path)) {
                if (microtime(true) > $deadline) {
                    throw new \RuntimeException('Current-read coordination timed out.');
                }
                usleep(1000);
            }
        };
        try {
            $results = $this->race(
                function () use ($meter, $dir, $await): array {
                    // Connection-local isolation only; never change global settings.
                    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                    return DB::transaction(function () use ($meter, $dir, $await): array {
                        $old = Meter::findOrFail($meter->id);
                        $before = DB::table('late_receipt_reviews')->where('submitted_meter_id', $meter->id)->count();
                        touch($dir.'/snapshot');
                        $await($dir.'/committed');
                        // Prove the ordinary consistent read still sees the old snapshot.
                        $stale = DB::table('late_receipt_reviews')->where('submitted_meter_id', $meter->id)->count();
                        try {
                            $old->delete();
                            return ['before' => $before, 'stale' => $stale, 'rejected' => false];
                        } catch (ValidationException $e) {
                            return ['before' => $before, 'stale' => $stale, 'rejected' => array_key_exists('meter_id', $e->errors())];
                        }
                    });
                },
                function () use ($subscriber, $meter, $collector, $sessionId, $uuid, $dir, $await): int {
                    $await($dir.'/snapshot');
                    $id = DB::transaction(function () use ($subscriber, $meter, $collector, $sessionId, $uuid) {
                        Subscriber::query()->lockForUpdate()->findOrFail($subscriber->id);
                        Meter::query()->lockForUpdate()->findOrFail($meter->id);
                        // A synthetic ordinary pending review, no financial posting.
                        return DB::table('late_receipt_reviews')->insertGetId([
                            'sync_uuid' => $uuid, 'cash_session_id' => $sessionId,
                            'collector_id' => $collector->id, 'subscriber_id' => $subscriber->id,
                            'submitted_meter_id' => $meter->id, 'amount' => '20.00',
                            'payment_date' => '2026-02-01', 'payment_method' => 'cash', 'status' => 'pending',
                        ]);
                    });
                    touch($dir.'/committed');
                    return $id;
                }
            );
            $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
            $this->assertSame(['before' => 0, 'stale' => 0, 'rejected' => true], $results[0]['value']);
            $this->assertDatabaseHas('meters', ['id' => $meter->id, 'deleted_at' => null]);
            $this->assertDatabaseHas('late_receipt_reviews', ['id' => $results[1]['value'], 'sync_uuid' => $uuid, 'status' => 'pending']);
            $this->assertDatabaseCount('readings', 0);
            $this->assertDatabaseCount('invoices', 0);
            $this->assertDatabaseCount('payments', 0);
            $this->assertDatabaseCount('transactions', 0);
        } finally {
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
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

    private function fundedSession(\App\Models\User $collector, string $amount): \App\Models\CashSession
    {
        $issuer = \App\Models\User::factory()->create(['role' => 'admin']);
        $issuer->assignRole(\Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web'));
        $key = substr((string) \Illuminate\Support\Str::uuid(), 0, 12);
        $account = app(\App\Services\TreasuryRegisterService::class)->open($issuer, [
            'code' => 'CASH-'.$key, 'name' => 'Synthetic cash funding', 'channel' => 'cash',
            'opening_on' => today()->toDateString(), 'opening_amount' => '10000',
            'opening_reference' => 'OPEN-'.$key, 'opening_evidence' => 'Synthetic independently counted opening',
        ]);
        return app(\App\Services\CashSessionService::class)->openFunded($collector, $issuer, (int) $account['id'],
            $amount, 'FUND-'.$key, 'Synthetic actual handover to collector');
    }

    public function test_parallel_cash_collection_and_close_preserve_finalized_cash_snapshot(): void
    {
        $meter = $this->fixture();
        $user = User::factory()->create();
        $session = $this->fundedSession($user, '100.25');
        $results = $this->race(
            fn () => app(PaymentService::class)->collectPayment(
                Subscriber::findOrFail($meter->subscriber_id), 500.25, userId: $user->id)->id,
            fn () => app(CashSessionService::class)->close($session, 100.25)->id,
        );
        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
        $payment = Payment::findOrFail($results[0]['value']);
        $session = $session->fresh();
        if ($payment->cash_session_id === $session->id) {
            $this->assertSame('600.50', $session->expected_amount);
            $this->assertSame('-500.25', $session->variance_amount);
        } else {
            $this->assertNull($payment->cash_session_id);
            $this->assertSame('100.25', $session->expected_amount);
            $this->assertSame('0.00', $session->variance_amount);
        }
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, Transaction::count());
        $this->assertSame(500.25, $meter->subscriber->getAccountCredit());
    }

    public function test_concurrent_late_receipt_approvals_cannot_allocate_same_counted_surplus(): void
    {
        $first = $this->fixture();
        $second = $this->fixture();
        $collector = User::factory()->create();
        $supervisor = User::factory()->create();
        $role = \Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web');
        $supervisor->assignRole($role);
        $sessions = app(CashSessionService::class);
        $session = $this->fundedSession($collector, '100.00');
        $sessions->decide($sessions->close($session, 200), true, $supervisor);
        $reviews = app(\App\Services\LateReceiptReviewService::class);
        $payload = ['cash_session_id' => $session->id, 'amount' => '75.25',
            'payment_method' => 'cash', 'payment_date' => today()->toDateString()];
        $one = $reviews->submit($collector, $payload + ['subscriber_id' => $first->subscriber_id, 'sync_uuid' => (string) Str::uuid()]);
        $two = $reviews->submit($collector, $payload + ['subscriber_id' => $second->subscriber_id, 'sync_uuid' => (string) Str::uuid()]);
        $approve = fn (array $review, string $reference) => app(\App\Services\LateReceiptReviewService::class)->decide(
            $review['id'], User::findOrFail($supervisor->id), ['approve' => true,
                'settlement_method' => 'already_counted', 'evidence_reference' => $reference,
                'evidence' => 'Verified cash already included in closing count'])['payment_id'];
        $results = $this->race(fn () => $approve($one, 'RACE-COUNT-1'), fn () => $approve($two, 'RACE-COUNT-2'));
        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        $this->assertSame(ValidationException::class, collect($results)->firstWhere('ok', false)['error']);
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, Transaction::count());
        $this->assertSame(2475, $reviews->countedCashAllocation($session->fresh())['available_minor']);
        $this->assertSame('100.00', $session->fresh()->variance_amount);
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

    public function test_p1_corrections_concurrent_refund_and_void_cannot_both_commit(): void
    {
        $subscriber = $this->fixture()->subscriber;
        $actor = User::factory()->create(['role' => 'admin']);
        $actor->assignRole(\Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web'));
        $payment = app(PaymentService::class)->collectPayment($subscriber, '0.03');
        $results = $this->race(
            fn () => app(\App\Services\PaymentRefundService::class)->record(Payment::findOrFail($payment->id),
                User::findOrFail($actor->id), '0.03', 'P1-RACE-REFUND', 'treasury_cash', today()->toDateString(),
                'Synthetic verified refund reason', 'Synthetic verified disbursement proof')->id,
            fn () => app(PaymentService::class)->voidPayment(Payment::findOrFail($payment->id),
                'Synthetic documented cancellation reason', $actor->id)->id,
        );
        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        $this->assertSame(ValidationException::class, collect($results)->firstWhere('ok', false)['error']);
        $this->assertSame(0, app(TransactionService::class)->accountBalanceMinor($subscriber));
        $this->assertSame(2, Transaction::count());
        $this->assertSame(1, Payment::count());
        $this->assertSame($results[0]['ok'] ? 1 : 0, DB::table('payment_refunds')->count());
        $this->assertSame($results[1]['ok'], $payment->fresh()->isVoided());
    }

    public function test_p1_corrections_reciprocal_transfers_conserve_both_accounts(): void
    {
        $first = $this->fixture()->subscriber;
        $second = $this->fixture()->subscriber;
        $actor = User::factory()->create(['role' => 'admin']);
        $actor->assignRole(\Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web'));
        $one = app(PaymentService::class)->collectPayment($first, '0.03');
        $two = app(PaymentService::class)->collectPayment($second, '0.02');
        $transfer = fn (int $id, int $destination) => app(\App\Services\PaymentAccountTransferService::class)->transfer(
            Payment::findOrFail($id), $destination, User::findOrFail($actor->id),
            'Synthetic correction of receipt account', 'Synthetic verified payer identity proof')->id;
        $results = $this->race(fn () => $transfer($one->id, $second->id), fn () => $transfer($two->id, $first->id));
        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
        $this->assertSame(2, app(TransactionService::class)->accountBalanceMinor($first));
        $this->assertSame(3, app(TransactionService::class)->accountBalanceMinor($second));
        $this->assertSame(2, Payment::count());
        $this->assertSame(6, Transaction::count());
        $this->assertSame(2, DB::table('payment_account_transfers')->count());
        $this->assertSame($first->id, $one->fresh()->subscriber_id);
        $this->assertSame($second->id, $two->fresh()->subscriber_id);
    }
}
