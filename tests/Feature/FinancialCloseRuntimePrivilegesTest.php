<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Meter;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\Tariff;
use App\Models\User;
use App\Services\FinancialSourceInsertProtection;
use App\Services\FinancialTriggerMetadata;
use App\Services\GatedCashReceiptWriter;
use App\Services\GatedInvoiceTransferWriter;
use App\Services\RuntimeDatabasePrivileges;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Requires a freshly migrated disposable CI database and separate DML-only user. */
final class FinancialCloseRuntimePrivilegesTest extends TestCase
{
    public function test_restricted_runtime_can_post_and_verify_guards_but_cannot_remove_them(): void
    {
        if (! app()->environment('testing') || DB::getDriverName() !== 'mysql'
            || DB::getDatabaseName() !== 'water_test' || getenv('WATER_QA_RUNTIME') !== '1') {
            $this->markTestSkipped('Requires isolated MySQL runtime privilege fixture.');
        }
        $this->assertSame(0, DB::table('financial_scopes')->count());
        $this->assertTrue(app(FinancialTriggerMetadata::class)->deploymentVerified());
        $admin = DB::getDefaultConnection();
        $originalConfig = config('database.financial_trigger_metadata_routine');
        $config = config('database.connections.'.$admin);
        config(['database.connections.qa_runtime' => array_replace($config, [
            'username' => 'water_runtime', 'password' => 'disposable_runtime_password',
            'url' => null,
        ])]);
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
        $subscriber = Subscriber::create(['name' => 'Synthetic runtime subscriber',
            'square_id' => Square::create(['name' => 'Synthetic runtime square'])->id, 'status' => 'active']);
        Tariff::create(['name' => 'Synthetic runtime tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-RUNTIME-METER']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $fields = array_flip(['TRIGGER_SCHEMA', 'TRIGGER_NAME', 'EVENT_OBJECT_TABLE', 'EVENT_MANIPULATION', 'ACTION_TIMING', 'ACTION_STATEMENT']);
        $metadata = fn () => FinancialTriggerMetadata::query()->sortBy('TRIGGER_NAME')
            ->map(fn ($row) => array_intersect_key((array) $row, $fields))->values()->all();
        $before = $metadata();
        $this->assertNotEmpty($before);
        $this->assertFalse(app(RuntimeDatabasePrivileges::class)->inspect()['accepted']);
        $useRuntime = static function (): void {
            DB::setDefaultConnection('qa_runtime');
            config(['database.financial_trigger_metadata_routine' => true]);
        };
        $useAdmin = static function () use ($admin, $originalConfig): void {
            DB::setDefaultConnection($admin);
            config(['database.financial_trigger_metadata_routine' => $originalConfig]);
        };
        try {
            $useRuntime();
            $this->assertStringStartsWith('water_runtime@', (string) DB::selectOne('SELECT CURRENT_USER() AS identity')->identity);
            $result = app(RuntimeDatabasePrivileges::class)->inspect();
            $this->assertTrue($result['accepted'], json_encode($result));
            $inventory = fn () => DB::transaction(fn () => app(\App\Services\FinancialWriterCoverageDiagnostic::class)->inspect());
            $verified = $inventory();
            $this->assertTrue($verified['runtime_grant_allowlist_accepted'], json_encode($verified));
            $this->assertSame([], $verified['runtime_grant_issues']);
            $this->assertTrue($verified['listed_guards_active'], json_encode($verified['guards']));
            $this->assertFalse($verified['complete_writer_coverage_verified']);
            $this->assertFalse($verified['closure_authorized']);
            $this->artisan('financial:database-preflight', ['--runtime' => true])->assertExitCode(0);
            $exit = \Illuminate\Support\Facades\Artisan::call('financial:database-preflight', ['--guards' => true]);
            $this->assertSame(0, $exit, \Illuminate\Support\Facades\Artisan::output());
            $this->assertEquals($before, $metadata());
            $invoice = app(GatedInvoiceTransferWriter::class)->issue($actor, (int) $subscriber->id,
                (int) $meter->id, '66666666-6666-4666-8666-666666666666', $now)['invoice'];
            $receipt = app(GatedCashReceiptWriter::class)->collect($actor, (int) $subscriber->id, '0.04',
                '77777777-7777-4777-8777-777777777777', $now);
            $this->assertTrue($receipt['registry_created']);
            $this->assertDatabaseCount('financial_posting_operations', 2);
            $this->assertDatabaseCount('financial_posting_events', 2);
            foreach ([
                'DROP TRIGGER fin_source_invoices_insert',
                'ALTER TABLE invoices ADD COLUMN qa_forbidden INT NULL',
                'TRUNCATE TABLE financial_posting_events',
                'DROP PROCEDURE financial_read_trigger_metadata',
            ] as $sql) {
                try {
                    DB::unprepared($sql);
                    $this->fail('Restricted runtime was able to execute forbidden DDL.');
                } catch (QueryException $error) {
                    $this->assertContains((int) ($error->errorInfo[1] ?? 0), [1142, 1227, 1370], $error->getMessage());
                }
            }
            $this->assertEquals($before, $metadata());
            $useAdmin();
            DB::table('financial_periods')->where('month', '2026-11')->update(['state' => 'CLOSED']);
            $useRuntime();
            $original = DB::table('invoice_tariff_lines')->where('invoice_id', $invoice->id)->sole();
            try {
                DB::table('invoice_tariff_lines')->where('id', $original->id)->update(['amount' => '9.99']);
                $this->fail('Restricted runtime bypassed the closed invoice detail fence.');
            } catch (QueryException $error) {
                $this->assertStringContainsString('Financial invoice detail is fenced', $error->getMessage());
            }
            $this->assertEquals($original, DB::table('invoice_tariff_lines')->where('id', $original->id)->sole());

            // A missing trigger must be detected even though the runtime
            // account cannot inspect metadata through its own TRIGGER grant.
            $useAdmin();
            DB::unprepared('DROP TRIGGER fin_reading_evidence_delete');
            try {
                $useRuntime();
                $this->assertFalse(app(FinancialSourceInsertProtection::class)->active());
                $damaged = $inventory();
                $this->assertTrue($damaged['runtime_grant_allowlist_accepted']);
                $this->assertFalse($damaged['guards']['source_period']);
                $this->assertFalse($damaged['listed_guards_active']);
                $this->assertFalse($damaged['closure_authorized']);
                $this->artisan('financial:database-preflight', ['--guards' => true])
                    ->expectsOutput('financial_source_fences: missing or unverifiable')->assertExitCode(1);
            } finally {
                $useAdmin();
                app(FinancialSourceInsertProtection::class)->install();
            }
            $useRuntime();
            $exit = \Illuminate\Support\Facades\Artisan::call('financial:database-preflight', ['--guards' => true]);
            $this->assertSame(0, $exit, \Illuminate\Support\Facades\Artisan::output());
        } finally {
            $useAdmin();
            DB::purge('qa_runtime');
        }
    }
}
