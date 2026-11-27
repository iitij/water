<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Meter;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\Tariff;
use App\Models\User;
use App\Services\FinancialCloseJournalProtection;
use App\Services\FinancialPostingRegistryProtection;
use App\Services\FinancialSourceInsertProtection;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class FinancialCloseUpgradeTest extends TestCase
{
    private const NEW_MIGRATIONS = [
        '2026_11_26_000137_protect_financial_close_journal.php',
        '2026_11_26_000138_protect_financial_posting_registry.php',
        '2026_11_26_000139_protect_financial_source_inserts.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing') || ! (
            (DB::getDriverName() === 'sqlite' && DB::getDatabaseName() === ':memory:')
            || (DB::getDriverName() === 'mysql' && DB::getDatabaseName() === 'water_test')
        )) {
            $this->markTestSkipped('Requires isolated disposable upgrade database.');
        }
        RefreshDatabaseState::$migrated = false;
        $this->artisan('db:wipe', ['--force' => true])->assertExitCode(0);
        $paths = [];
        foreach (glob(database_path('migrations/*.php')) as $file) {
            if (! in_array(basename($file), self::NEW_MIGRATIONS, true)) {
                $paths[] = 'database/migrations/'.basename($file);
            }
        }
        $this->artisan('migrate', ['--path' => $paths, '--force' => true])->assertExitCode(0);
    }

    private function guards(): array
    {
        return [app(FinancialCloseJournalProtection::class), app(FinancialPostingRegistryProtection::class),
            app(FinancialSourceInsertProtection::class)];
    }

    private function metadata(): array
    {
        return DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->orderBy('name')->get()->keyBy('name')->all()
            : \App\Services\FinancialTriggerMetadata::query()->sortBy('TRIGGER_NAME')->keyBy('TRIGGER_NAME')->all();
    }

    public function test_upgrade_preserves_populated_main_schema_and_restarts_idempotently(): void
    {
        $now = new DateTimeImmutable('2026-11-25T08:30:00Z');
        $this->travelTo(\Carbon\Carbon::instance($now));
        DB::table('financial_scopes')->insert([
            'scope_key' => 'water', 'currency' => 'YER', 'timezone' => 'Asia/Aden',
            'mode' => 'ACTIVE', 'calendar_version' => 1, 'fence_epoch' => 5,
        ]);
        DB::table('financial_periods')->insert([
            'scope_key' => 'water', 'month' => '2026-11', 'start_on' => '2026-11-01',
            'next_on' => '2026-12-01', 'state' => 'OPEN', 'version' => 0,
        ]);
        $actor = User::factory()->create(['role' => 'admin']);
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));
        $subscriber = Subscriber::create(['name' => 'Synthetic upgrade subscriber',
            'square_id' => Square::create(['name' => 'Synthetic upgrade square'])->id, 'status' => 'active']);
        Tariff::create(['name' => 'Synthetic upgrade tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-UPGRADE-METER']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        // Populate the pre-upgrade schema before installing the new guards.
        // The candidate registry writer intentionally requires the guards, so
        // construct the exact previous-format registry rows from real effects.
        $invoice = app(\App\Services\InvoiceService::class)->generateInvoice($subscriber, $meter);
        $receipt = app(\App\Services\PaymentService::class)->collectPayment($subscriber, '0.04',
            userId: (int) $actor->id, syncUuid: '77777777-7777-4777-8777-777777777777');
        $periodId = (int) DB::table('financial_periods')->value('id');
        DB::transaction(function () use ($invoice, $receipt, $periodId, $now): void {
            foreach ([
                [\App\Models\Invoice::class, $invoice->id, 'invoice', '66666666-6666-4666-8666-666666666666'],
                [\App\Models\Payment::class, $receipt->id, 'payment', '77777777-7777-4777-8777-777777777777'],
            ] as [$class, $id, $type, $uuid]) {
                $transaction = DB::table('transactions')->where('reference_type', $class)
                    ->where('reference_id', $id)->where('type', $type)->sole();
                $leg = app(\App\Services\FinancialLedgerEvidence::class)->leg((int) $transaction->id);
                $leg['amount_minor'] = (string) $leg['amount_minor'];
                $payload = json_encode([
                    'format' => 1, 'operation_uuid' => $uuid, 'scope' => 'water',
                    'period_id' => $periodId, 'effective_on' => '2026-11-25',
                    'epoch' => 5, 'version' => 0, 'legs' => [$leg],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                DB::table('financial_posting_operations')->insert([
                    'operation_uuid' => $uuid, 'period_id' => $periodId, 'scope_key' => 'water',
                    'effective_on' => '2026-11-25', 'fence_epoch' => 5, 'period_version' => 0,
                    'recorded_at_utc' => $now->format('Y-m-d\TH:i:s.u\Z'),
                    'canonical_payload' => $payload, 'payload_hash' => hash('sha256', $payload),
                ]);
                DB::table('financial_posting_events')->insert($leg + ['operation_uuid' => $uuid, 'scope_key' => 'water']);
            }
        });
        foreach ($this->guards() as $guard) $this->assertFalse($guard->active());
        $tables = ['financial_scopes', 'financial_periods', 'financial_close_commands',
            'financial_period_closures', 'subscribers', 'meters', 'readings', 'invoices',
            'invoice_items', 'invoice_tariff_lines', 'payments', 'transactions', 'payment_allocations',
            'payment_allocation_revisions', 'financial_posting_operations', 'financial_posting_events', 'audit_events'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->get()->toArray(), $tables);
        $before = $snapshot();
        $previous = $this->metadata();
        $this->assertNotEmpty($previous);
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $after = $this->metadata();
        $this->assertGreaterThan(count($previous), count($after));
        $this->assertEquals($previous, array_intersect_key($after, $previous));
        $this->assertEquals($before, $snapshot());
        foreach ($this->guards() as $guard) {
            $this->assertTrue($guard->active());
            $guard->install();
        }
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertEquals($after, $this->metadata());
        $this->assertEquals($before, $snapshot());
        $verified = DB::transaction(fn () => app(\App\Services\FinancialPostingRegistry::class)->verifyPeriod('water', $periodId));
        $this->assertSame(2, $verified['operations']);
        $this->assertSame(2, $verified['events']);
        $this->assertFalse($verified['source_coverage_verified']);
        foreach (self::NEW_MIGRATIONS as $migration) {
            $this->assertDatabaseHas('migrations', ['migration' => basename($migration, '.php')]);
        }
        DB::table('financial_periods')->where('month', '2026-11')->update(['state' => 'CLOSED']);
        try {
            DB::table('invoice_tariff_lines')->where('invoice_id', $invoice->id)->update(['amount' => '9.99']);
            $this->fail('Upgraded invoice detail fence did not reject.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('Financial invoice detail is fenced', $error->getMessage());
        }
    }

    public function test_counterfeit_late_guard_stops_installation_before_any_new_trigger(): void
    {
        $name = 'fin_reading_evidence_delete';
        $sql = DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER {$name} BEFORE DELETE ON readings FOR EACH ROW BEGIN SELECT 1; END"
            : "CREATE TRIGGER {$name} BEFORE DELETE ON readings FOR EACH ROW BEGIN SET @qa_counterfeit_guard = 1; END";
        DB::unprepared($sql);
        $before = $this->metadata();
        try {
            app(FinancialSourceInsertProtection::class)->install();
            $this->fail('Counterfeit protection was accepted.');
        } catch (\LogicException $error) {
            $this->assertSame('Unexpected source period fence: '.$name, $error->getMessage());
        }
        $this->assertEquals($before, $this->metadata());
        // Remove the deliberately forged QA-only trigger. A deployer must
        // independently investigate unexpected definitions in real databases.
        DB::unprepared('DROP TRIGGER '.$name);
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        foreach ($this->guards() as $guard) $this->assertTrue($guard->active());
    }
}
