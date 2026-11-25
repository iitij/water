<?php

namespace Tests\Feature;

use App\Models\Meter;
use App\Models\Reading;
use App\Models\Setting;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\Tariff;
use App\Services\InvoiceService;
use App\Services\TransactionService;
use App\Support\FinancialAmount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * G07: bill already-stored observations, not the accepted reading-write guards.
 *
 * Ordinary synthetic fixtures only. All actual DB constraints remain enabled.
 * A rejected fixture or unrelated exception is NOT a successful billing guard.
 */
final class V2StoredObservationBillingTest extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as private refreshSyntheticDatabase;
    }

    /** Fail before migration setup if the approved runner targets another database. */
    public function refreshDatabase(): void
    {
        $this->assertTrue(app()->environment('testing'), 'G07 requires APP_ENV=testing.');
        $driver = DB::getDriverName();
        $database = DB::connection()->getDatabaseName();
        $this->assertTrue(
            ($driver === 'sqlite' && $database === ':memory:')
            || ($driver === 'mysql' && $database === 'water_test'),
            'G07 requires isolated SQLite memory or disposable MySQL water_test; no live data.'
        );
        $this->refreshSyntheticDatabase();
    }

    private const SNAPSHOT_TABLES = [
        'subscribers', 'meters', 'readings', 'tariffs', 'tariff_catalog_locks',
        'invoices', 'invoice_items', 'invoice_tariff_lines', 'transactions',
        'payments', 'payment_allocations', 'payment_allocation_revisions',
        'payment_refunds', 'payment_account_transfers', 'historical_invoice_corrections', 'audit_events',
        'cash_sessions', 'treasury_accounts', 'treasury_postings',
        'bank_statements', 'bank_statement_lines', 'bank_statement_matches',
        'late_receipt_reviews', 'treasury_bank_collections', 'cash_handovers',
    ];

    private function fixture(bool $twoTariffs = false): array
    {
        $this->assertTrue(app()->environment('testing'));
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-04-10 12:00:00', 'UTC'));
        Setting::set('auto_issue_invoice_on_reading', '0');
        Setting::set('auto_send_whatsapp_invoice', '0');
        $square = Square::create(['name' => 'G07 synthetic square']);
        $subscriber = Subscriber::create([
            'name' => 'G07 synthetic target', 'square_id' => $square->id, 'status' => 'active',
        ]);
        $meter = Meter::create([
            'subscriber_id' => $subscriber->id, 'unique_code' => 'G07-TARGET',
            'installed_at' => '2026-01-01', 'initial_reading' => '100.00',
        ]);
        $other = Subscriber::create([
            'name' => 'G07 independent control account', 'square_id' => $square->id, 'status' => 'active',
        ]);
        $otherMeter = Meter::create([
            'subscriber_id' => $other->id, 'unique_code' => 'G07-OTHER',
            'installed_at' => '2026-01-01', 'initial_reading' => '50.00',
        ]);
        $firstTariff = Tariff::create([
            'name' => 'G07 tariff 2', 'unit_price' => '2.00', 'is_active' => true,
            'start_date' => '2026-01-01', 'end_date' => $twoTariffs ? '2026-01-31' : null,
        ]);
        $secondTariff = $twoTariffs ? Tariff::create([
            'name' => 'G07 tariff 3', 'unit_price' => '3.00', 'is_active' => true,
            'start_date' => '2026-02-01', 'end_date' => null,
        ]) : $firstTariff;

        $otherReading = $this->normalReading($otherMeter, '2026-02-01', '55.00');
        $this->assertSame('5.00', $otherReading->consumption_units);
        $this->assertSame(0, $this->balance($subscriber));
        $this->assertSame(0, $this->balance($other));
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('transactions', 0);
        return [$subscriber, $meter, $firstTariff, $secondTariff, $other, $otherMeter, $otherReading];
    }

    private function normalReading(Meter $meter, string $actual, string $current): Reading
    {
        return Reading::create([
            'meter_id' => $meter->id, 'reading_date' => $actual,
            'actual_reading_date' => $actual, 'current_reading' => $current,
        ])->fresh();
    }

    /** Simulate existing imported rows, valid individually but ambiguous together. */
    private function storedReading(
        Meter $meter, string $entry, string $start, string $end,
        string $previous, string $current, string $units
    ): Reading {
        // Deliberately use a distinct entry date for duplicate actual observations.
        // No constraints, triggers, migrations, or connection protections are changed.
        $id = DB::table('readings')->insertGetId([
            'meter_id' => $meter->id, 'reading_date' => $entry,
            'actual_reading_date' => $end, 'period_basis' => 'observation',
            'period_start' => $start, 'period_end' => $end,
            'previous_reading' => $previous, 'current_reading' => $current,
            'consumption_units' => $units, 'is_invoiced' => false, 'invoice_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Reading::findOrFail($id);
    }

    /** Prove the actual stored row and one unambiguous covering tariff before billing. */
    private function assertFixture(
        Reading $reading, Meter $meter, Tariff $tariff, string $start, string $end,
        string $previous, string $current, string $units, string $price, int $amountMinor
    ): void {
        $row = $reading->fresh();
        $this->assertSame($meter->id, $row->meter_id);
        $this->assertSame('observation', $row->period_basis);
        $this->assertSame($start, $row->period_start);
        $this->assertSame($end, $row->period_end);
        $this->assertSame($end, $row->actual_reading_date->toDateString());
        $this->assertSame($previous, $row->previous_reading);
        $this->assertSame($current, $row->current_reading);
        $this->assertSame($units, $row->consumption_units);
        $this->assertFalse($row->is_invoiced);
        $this->assertNull($row->invoice_id);
        $this->assertNull($row->retired_meter_id);
        $this->assertNull($row->retired_meter_code);
        $this->assertNull($row->deleted_at);
        $this->assertTrue($start < $end);
        $this->assertSame(FinancialAmount::parse($units)->minor,
            FinancialAmount::parse($current)->minor - FinancialAmount::parse($previous)->minor);
        $this->assertGreaterThanOrEqual(0, FinancialAmount::parse($previous)->minor);
        $lastDay = \Illuminate\Support\Carbon::parse($end)->subDay()->toDateString();
        $cover = Tariff::query()->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('start_date')->orWhereDate('start_date', '<=', $lastDay))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $start))
            ->get();
        $this->assertCount(1, $cover, 'Fixture must have a unique overlapping tariff.');
        $this->assertSame($tariff->id, $cover->sole()->id);
        $this->assertSame($price, $cover->sole()->unit_price);
        $this->assertTrue($tariff->start_date === null || $tariff->start_date->toDateString() <= $start);
        $this->assertTrue($tariff->end_date === null || $tariff->end_date->toDateString() >= $lastDay);
        $this->assertSame($amountMinor, \App\Support\ExactMoney::tariffLine($units, $price)->minor);
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (self::SNAPSHOT_TABLES as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()
                ->map(static fn ($row) => (array) $row)->all();
        }
        return $snapshot;
    }

    private function balance(Subscriber $subscriber): int
    {
        return app(TransactionService::class)->accountBalanceMinor($subscriber);
    }

    private function assertAccount(Subscriber $subscriber, Meter $meter, int $minor, string $meterCache): void
    {
        $this->assertSame($minor, $this->balance($subscriber));
        $this->assertSame('0.00', $subscriber->fresh()->balance);
        $this->assertSame($meterCache, $meter->fresh()->balance);
        $this->assertSame($meter->id, $subscriber->fresh()->currentMeter->id);
    }

    private function assertBillingRejected(
        Subscriber $subscriber, Meter $meter, array $ids, string $reason, int $priorMinor, string $priorCache
    ): void {
        $before = $this->snapshot();
        $this->assertAccount($subscriber, $meter, $priorMinor, $priorCache);
        $level = DB::transactionLevel();
        try {
            $invoice = app(InvoiceService::class)->generateInvoice($subscriber, $meter);
        } catch (ValidationException $e) {
            // Do not count missing tariff, unrelated validation or setup errors as PASS.
            $errors = $e->errors();
            $this->assertNotEmpty(array_intersect(array_keys($errors),
                ['readings', 'reading', 'reading_date', 'actual_reading_date', 'period_start', 'period_end', 'previous_reading']),
                'Expected a reading-chain domain rejection, not '.json_encode($errors, JSON_UNESCAPED_UNICODE));
            $message = implode(' ', array_merge(...array_values($errors)));
            $this->assertMatchesRegularExpression($reason, $message, 'Wrong rejection reason: '.$message);
            $this->assertSame($level, DB::transactionLevel(), 'Billing left a transaction open.');
            $this->assertSame($before, $this->snapshot(), 'Rejected billing changed stored evidence.');
            $this->assertAccount($subscriber, $meter, $priorMinor, $priorCache);
            return;
        }

        // A successful negative issuance is the actual defect. Capture the
        // persisted financial result BEFORE RefreshDatabase rolls the fixture back.
        $after = $this->snapshot();
        $changed = [];
        foreach ($before as $table => $rows) {
            if ($rows !== $after[$table]) {
                $changed[$table] = ['before' => $rows, 'after' => $after[$table]];
            }
        }
        $this->fail('G07 STORED BILLING GAP: '.json_encode([
            'reading_ids' => $ids, 'invoice_id' => $invoice->id,
            'invoice_total' => $invoice->fresh()->total_amount,
            'balance_before_minor' => $priorMinor, 'balance_after_minor' => $this->balance($subscriber),
            'meter_cache_after' => $meter->fresh()->balance,
            'transaction_level_before' => $level, 'transaction_level_after' => DB::transactionLevel(),
            'changed_tables' => $changed,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function test_g07_c01_duplicate_stored_actual_period_must_not_be_billed(): void
    {
        [$subscriber, $meter, $tariff] = $this->fixture();
        $first = $this->normalReading($meter, '2026-02-01', '120.00');
        $second = $this->storedReading($meter, '2026-02-02', '2026-01-01', '2026-02-01',
            '100.00', '120.00', '20.00');
        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($first->reading_date->toDateString(), $second->reading_date->toDateString());
        foreach ([$first, $second] as $reading) {
            $this->assertFixture($reading, $meter, $tariff, '2026-01-01', '2026-02-01',
                '100.00', '120.00', '20.00', '2.00', 4000);
        }
        $this->assertBillingRejected($subscriber, $meter, [$first->id, $second->id],
            '/تكرار|مكرر|مكررة|متطابق|التاريخ الفعلي نفسه|نفس التاريخ|duplicate|same\s+(?:actual\s+)?(?:date|period)/iu',
            0, '0.00');
    }

    public function test_g07_c02_distinct_stored_overlapping_periods_must_not_be_billed(): void
    {
        [$subscriber, $meter, $tariff] = $this->fixture();
        $first = $this->normalReading($meter, '2026-02-01', '120.00');
        $second = $this->storedReading($meter, '2026-03-01', '2026-01-20', '2026-03-01',
            '110.00', '130.00', '20.00');
        $this->assertFixture($first, $meter, $tariff, '2026-01-01', '2026-02-01',
            '100.00', '120.00', '20.00', '2.00', 4000);
        $this->assertFixture($second, $meter, $tariff, '2026-01-20', '2026-03-01',
            '110.00', '130.00', '20.00', '2.00', 4000);
        $this->assertBillingRejected($subscriber, $meter, [$first->id, $second->id],
            '/تداخل|متداخل|overlap/iu', 0, '0.00');
    }

    public function test_g07_c03_stored_pending_reading_must_match_billed_predecessor(): void
    {
        [$subscriber, $meter, $tariff] = $this->fixture();
        $first = $this->normalReading($meter, '2026-02-01', '120.00');
        $this->assertFixture($first, $meter, $tariff, '2026-01-01', '2026-02-01',
            '100.00', '120.00', '20.00', '2.00', 4000);
        $invoice = app(InvoiceService::class)->generateInvoice($subscriber, $meter);
        $this->assertSame('40.00', $invoice->total_amount);
        $this->assertSame('0.00', $invoice->amount_paid);
        $this->assertSame($invoice->id, $first->fresh()->invoice_id);
        $this->assertTrue($first->fresh()->is_invoiced);
        $this->assertDatabaseHas('invoice_tariff_lines', [
            'invoice_id' => $invoice->id, 'reading_id' => $first->id, 'tariff_id' => $tariff->id,
            'units' => '20.00', 'unit_price' => '2.00', 'amount' => '40.00',
        ]);
        $this->assertDatabaseHas('transactions', [
            'subscriber_id' => $subscriber->id, 'meter_id' => $meter->id,
            'type' => 'invoice', 'reference_id' => $invoice->id, 'amount' => '-40.00',
        ]);
        $this->assertAccount($subscriber, $meter, -4000, '-40.00');
        $second = $this->storedReading($meter, '2026-03-01', '2026-02-01', '2026-03-01',
            '115.00', '130.00', '15.00');
        $this->assertFixture($second, $meter, $tariff, '2026-02-01', '2026-03-01',
            '115.00', '130.00', '15.00', '2.00', 3000);
        $this->assertSame('120.00', $first->fresh()->current_reading);
        $this->assertBillingRejected($subscriber, $meter, [$first->id, $second->id],
            '/القراءة السابقة|آخر قراءة|قراءة مفوترة|أساس القراءة|تسلسل القراءات|انقطاع|predecessor|anchor|previous\s+reading|continuity/iu',
            -4000, '-40.00');
    }

    public function test_g07_c04_adjacent_observations_at_two_tariffs_bill_exactly_once(): void
    {
        [$subscriber, $meter, $tariff1, $tariff2, $other, $otherMeter, $otherReading] = $this->fixture(true);
        $first = $this->normalReading($meter, '2026-02-01', '120.00');
        $second = $this->normalReading($meter, '2026-03-01', '130.00');
        $this->assertFixture($first, $meter, $tariff1, '2026-01-01', '2026-02-01',
            '100.00', '120.00', '20.00', '2.00', 4000);
        $this->assertFixture($second, $meter, $tariff2, '2026-02-01', '2026-03-01',
            '120.00', '130.00', '10.00', '3.00', 3000);
        $before = $this->snapshot();
        $invoice = app(InvoiceService::class)->generateInvoice($subscriber, $meter);
        $this->assertSame('70.00', $invoice->total_amount);
        $this->assertSame('70.00', $invoice->consumption_fee);
        foreach (['amount_paid', 'previous_balance', 'credit_applied'] as $field) {
            $this->assertSame('0.00', $invoice->{$field});
        }
        $this->assertSame('2026-01-01', $invoice->billing_period_start->toDateString());
        $this->assertSame('2026-03-01', $invoice->billing_period_end->toDateString());
        $this->assertSame('issued', $invoice->status);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('invoice_items', 1);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'consumption', 'amount' => '70.00']);
        $this->assertDatabaseCount('invoice_tariff_lines', 2);
        foreach ([[$first, $tariff1, '20.00', '2.00', '40.00'], [$second, $tariff2, '10.00', '3.00', '30.00']] as [$reading, $tariff, $units, $price, $amount]) {
            $this->assertDatabaseHas('invoice_tariff_lines', [
                'invoice_id' => $invoice->id, 'reading_id' => $reading->id,
                'tariff_id' => $tariff->id, 'period_start' => $reading->period_start,
                'period_end' => $reading->period_end, 'units' => $units, 'unit_price' => $price, 'amount' => $amount,
            ]);
            $this->assertTrue($reading->fresh()->is_invoiced);
            $this->assertSame($invoice->id, $reading->fresh()->invoice_id);
        }
        $this->assertDatabaseCount('payment_allocation_revisions', 1);
        $this->assertDatabaseHas('payment_allocation_revisions', [
            'subscriber_id' => $subscriber->id, 'revision' => 1,
            'available_minor' => 0, 'allocated_minor' => 0, 'unallocated_minor' => 0,
        ]);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseHas('transactions', [
            'subscriber_id' => $subscriber->id, 'meter_id' => $meter->id,
            'type' => 'invoice', 'reference_type' => \App\Models\Invoice::class,
            'reference_id' => $invoice->id, 'amount' => '-70.00',
        ]);
        $this->assertAccount($subscriber, $meter, -7000, '-70.00');
        $this->assertAccount($other, $otherMeter, 0, '0.00');
        $this->assertFalse($otherReading->fresh()->is_invoiced);
        $this->assertNull($otherReading->fresh()->invoice_id);
        $after = $this->snapshot();
        foreach (self::SNAPSHOT_TABLES as $table) {
            if (! in_array($table, ['invoices', 'invoice_items', 'invoice_tariff_lines', 'transactions', 'readings', 'meters', 'subscribers', 'payment_allocation_revisions'], true)) {
                $this->assertSame($before[$table], $after[$table], 'Unrelated table changed: '.$table);
            }
        }
        foreach (['subscribers' => $other->id, 'meters' => $otherMeter->id, 'readings' => $otherReading->id] as $table => $id) {
            $this->assertSame(collect($before[$table])->firstWhere('id', $id),
                collect($after[$table])->firstWhere('id', $id), 'Other account changed: '.$table);
        }
        // Only the two target reading links/flags and the target meter
        // projection may change, not dates, quantities or original identity.
        foreach (['readings' => [$first->id, $second->id], 'meters' => [$meter->id],
            'subscribers' => [$subscriber->id]] as $table => $ids) {
            foreach ($ids as $id) {
                $old = collect($before[$table])->firstWhere('id', $id);
                $new = collect($after[$table])->firstWhere('id', $id);
                $this->assertNotNull($old);
                $this->assertNotNull($new);
                $allowed = match ($table) {
                    'readings' => ['is_invoiced', 'invoice_id', 'updated_at'],
                    'meters' => ['balance', 'updated_at'],
                    default => ['updated_at'],
                };
                $this->assertSame(array_diff_key($old, array_flip($allowed)),
                    array_diff_key($new, array_flip($allowed)), 'Unexpected target mutation: '.$table);
            }
        }
        $this->assertSame(count($before['readings']), count($after['readings']));
        $this->assertSame(count($before['meters']), count($after['meters']));
        $this->assertSame(count($before['subscribers']), count($after['subscribers']));
        try {
            app(InvoiceService::class)->generateInvoice($subscriber, $meter);
            $this->fail('Valid observations were billed twice.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('readings', $e->errors());
            $this->assertStringContainsString('لا توجد قراءات جديدة', implode(' ', $e->errors()['readings']));
        }
        $this->assertSame($after, $this->snapshot(), 'Repeated request changed the first invoice or related records.');
        $this->assertAccount($subscriber, $meter, -7000, '-70.00');
    }
}
