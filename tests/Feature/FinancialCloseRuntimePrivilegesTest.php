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
    public function test_close_control_read_only_runtime_can_post_but_cannot_write_close_control(): void
    {
        if (! app()->environment('testing') || DB::getDriverName() !== 'mysql'
            || DB::getDatabaseName() !== 'water_test' || getenv('WATER_QA_CLOSE_CONTROL') !== '1') {
            $this->markTestSkipped('Requires isolated MySQL close-control privilege fixture.');
        }
        $this->assertSame(0, DB::table('financial_scopes')->count());
        $admin = DB::getDefaultConnection();
        $originalConfig = config('database.financial_trigger_metadata_routine');
        config(['database.connections.qa_close_runtime' => array_replace(config('database.connections.'.$admin), [
            'username' => 'water_close_runtime', 'password' => 'disposable_close_password', 'url' => null,
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
        $subscriber = Subscriber::create(['name' => 'Synthetic isolated runtime subscriber',
            'square_id' => Square::create(['name' => 'Synthetic isolated runtime square'])->id, 'status' => 'active']);
        Tariff::create(['name' => 'Synthetic runtime tariff', 'unit_price' => '0.10', 'is_active' => true]);
        $meter = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-CLOSE-RUNTIME-METER']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        $protected = ['financial_scopes', 'financial_periods', 'financial_close_commands', 'financial_period_closures'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->get()->toArray(), $protected);
        $before = $snapshot();
        try {
            DB::setDefaultConnection('qa_close_runtime');
            config(['database.financial_trigger_metadata_routine' => true]);
            $this->assertStringStartsWith('water_close_runtime@', (string) DB::selectOne('SELECT CURRENT_USER() AS identity')->identity);
            $report = app(RuntimeDatabasePrivileges::class)->inspect();
            $this->assertTrue($report['accepted'], json_encode($report));
            $this->assertTrue($report['close_control_write_isolated'], json_encode($report));
            $this->assertSame([], $report['close_control_issues']);
            $this->artisan('financial:database-preflight', ['--close-control' => true])->assertExitCode(0);
            foreach ($protected as $table) {
                $column = $table === 'financial_scopes' ? 'scope_key' : ($table === 'financial_close_commands' ? 'command_uuid' : 'period_id');
                if ($table === 'financial_periods') $column = 'id';
                foreach ([
                    "INSERT INTO {$table} ({$column}) SELECT {$column} FROM {$table} WHERE 1 = 0",
                    "UPDATE {$table} SET {$column} = {$column} WHERE 1 = 0",
                    "DELETE FROM {$table} WHERE 1 = 0",
                ] as $sql) {
                    try {
                        DB::unprepared($sql);
                        $this->fail('Operational identity received prohibited close-control write permission.');
                    } catch (QueryException $error) {
                        $this->assertContains((int) ($error->errorInfo[1] ?? 0), [1142, 1143], $error->getMessage());
                    }
                }
            }
            $this->assertEquals($before, $snapshot());
            $invoice = app(GatedInvoiceTransferWriter::class)->issue($actor, (int) $subscriber->id,
                (int) $meter->id, '66666666-6666-4666-8666-666666666666', $now)['invoice'];
            $this->assertSame('0.10', $invoice->total_amount);
            $receipt = app(GatedCashReceiptWriter::class)->collect($actor, (int) $subscriber->id, '0.04',
                '77777777-7777-4777-8777-777777777777', $now);
            $this->assertTrue($receipt['registry_created']);
            $this->assertDatabaseCount('financial_posting_operations', 2);
            $this->assertDatabaseCount('financial_posting_events', 2);
            $this->assertEquals($before, $snapshot());
            $inventory = DB::transaction(fn () => app(\App\Services\FinancialWriterCoverageDiagnostic::class)->inspect());
            $this->assertTrue($inventory['runtime_close_control_write_isolated']);
            $this->assertFalse($inventory['closure_authorized']);
        } finally {
            DB::setDefaultConnection($admin);
            config(['database.financial_trigger_metadata_routine' => $originalConfig]);
            DB::purge('qa_close_runtime');
        }
    }

    public function test_restricted_runtime_can_post_and_verify_guards_but_cannot_remove_them(): void
    {
        if (! app()->environment('testing') || DB::getDriverName() !== 'mysql'
            || DB::getDatabaseName() !== 'water_test' || getenv('WATER_QA_RUNTIME') !== '1') {
            $this->markTestSkipped('Requires isolated MySQL runtime privilege fixture.');
        }
        $this->assertSame(0, DB::table('financial_scopes')->count());
        $this->assertTrue(app(FinancialTriggerMetadata::class)->deploymentVerified());
        $this->assertTrue(app(\App\Services\LedgerProtection::class)->active(), 'Migration identity must explicitly install ledger protection.');
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
        $reviewer = User::factory()->create(['role' => 'admin']);
        $reviewer->assignRole(Role::findOrCreate('super_admin', 'web'));
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
            // Exercise the live metadata routine before the diagnostic catches individual guard errors.
            $this->assertEquals($before, $metadata());
            $inventory = fn () => DB::transaction(fn () => app(\App\Services\FinancialWriterCoverageDiagnostic::class)->inspect());
            $verified = $inventory();
            $this->assertTrue($verified['runtime_grant_allowlist_accepted'], json_encode($verified));
            $this->assertSame([], $verified['runtime_grant_issues']);
            $this->assertTrue($verified['listed_guards_active'], json_encode($verified['guards']));
            $this->assertTrue($verified['guards']['custody_shortage_decisions']);
            $this->assertTrue($verified['guards']['cash_count_period']);
            $this->assertTrue($verified['guards']['refund_release_period']);
            $this->assertTrue($verified['guards']['live_metadata_routine']);
            $this->assertSame($verified['guards'], app(\App\Services\FinancialGuardInventory::class)->inspect());
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
            // A DML-only identity must not be able to append a fictitious ledger leg.
            $registryBefore = DB::table('financial_posting_events')->orderBy('id')->get()->all();
            $source = (array) DB::table('financial_posting_events')->where('source_type', 'ledger_transaction')->orderBy('id')->first();
            $this->assertNotEmpty($source);
            unset($source['id']);
            foreach ([
                array_replace($source, ['source_id' => '9223372036854775807']),
                array_replace($source, ['leg' => 'bank']),
            ] as $invalidLeg) {
                try {
                    DB::table('financial_posting_events')->insert($invalidLeg);
                    $this->fail('Restricted runtime appended an invalid ledger source.');
                } catch (QueryException $error) {
                    $this->assertSame('45000', $error->errorInfo[0]);
                    $this->assertStringContainsString('Financial registry source identity is invalid', $error->getMessage());
                }
                $this->assertEquals($registryBefore, DB::table('financial_posting_events')->orderBy('id')->get()->all());
            }
            config(['financial_candidate.collection_evidence' => true]);
            $claims = app(\App\Services\NonCashClaimService::class);
            $claim = $claims->submit($actor, ['sync_uuid' => '88888888-8888-4888-8888-888888888888',
                'subscriber_id' => $subscriber->id, 'amount' => '0.25', 'payment_method' => 'check',
                'instrument_reference' => 'QA-RUNTIME-CHECK', 'instrument_on' => '2026-11-25']);
            $this->assertSame('pending', $claim['status']);
            $rejected = $claims->reject($reviewer, (int) $claim['id'], 'Independent runtime claim rejection evidence');
            $this->assertSame('rejected', $rejected['status']);
            $this->assertDatabaseCount('financial_posting_events', 2);
            $audit = DB::table('audit_events')->where('event', 'noncash_claim.submitted')->sole();
            try {
                DB::table('audit_events')->where('id', $audit->id)->delete();
                $this->fail('Restricted runtime deleted collection evidence.');
            } catch (QueryException $error) {
                $this->assertSame('45000', $error->errorInfo[0]);
                $this->assertStringContainsString('Collection evidence audit is immutable or invalid', $error->getMessage());
            }
            $this->assertEquals($audit, DB::table('audit_events')->where('id', $audit->id)->sole());
            foreach ([
                'DROP TRIGGER ledger_reject_update',
                'DROP TRIGGER fin_source_invoices_insert',
                'DROP TRIGGER fin_collection_audit_insert',
                'DROP TRIGGER fin_count_treasury_cash_counts_insert',
                'DROP TRIGGER fin_refund_release_audit_period',
                'DROP INDEX fin_collection_audit_identity ON audit_events',
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
            $ledger = DB::table('transactions')->orderBy('id')->first();
            $this->assertNotNull($ledger);
            try {
                DB::table('transactions')->where('id', $ledger->id)->update(['amount' => '9.99']);
                $this->fail('Restricted runtime changed immutable ledger amount.');
            } catch (QueryException $error) {
                $this->assertSame('45000', $error->errorInfo[0]);
                $this->assertStringContainsString('Ledger posting is immutable', $error->getMessage());
            }
            $this->assertEquals($ledger, DB::table('transactions')->where('id', $ledger->id)->sole());
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
            $useAdmin();
            DB::unprepared('DROP TRIGGER fin_shortage_audit_delete');
            try {
                $useRuntime();
                $damaged = $inventory();
                $this->assertFalse($damaged['guards']['custody_shortage_decisions']);
                $this->assertFalse($damaged['listed_guards_active']);
                $this->artisan('financial:database-preflight', ['--guards' => true])
                    ->expectsOutput('custody_shortage_decisions: missing or unverifiable')->assertExitCode(1);
            } finally {
                $useAdmin();
                app(\App\Services\CustodyShortageDecisionProtection::class)->install();
            }
            foreach ([
                ['fin_count_treasury_cash_counts_insert', 'cash_count_period', \App\Services\CashCountPeriodProtection::class],
                ['fin_refund_release_audit_period', 'refund_release_period', \App\Services\RefundReleasePeriodProtection::class],
            ] as [$trigger, $key, $protection]) {
                $useAdmin();
                DB::unprepared('DROP TRIGGER '.$trigger);
                try {
                    $useRuntime();
                    $damaged = $inventory();
                    $this->assertFalse($damaged['guards'][$key]);
                    $this->assertFalse($damaged['listed_guards_active']);
                    $this->assertFalse($damaged['closure_authorized']);
                    $this->artisan('financial:database-preflight', ['--guards' => true])
                        ->expectsOutput($key.': missing or unverifiable')->assertExitCode(1);
                } finally {
                    $useAdmin();
                    app($protection)->install();
                }
            }
            $useAdmin();
            DB::unprepared('DROP TRIGGER held_assignment_reject_update');
            DB::unprepared('CREATE TRIGGER held_assignment_reject_update BEFORE UPDATE ON treasury_held_cash_assignments FOR EACH ROW SET NEW.amount_minor = NEW.amount_minor');
            try {
                $useRuntime();
                $damaged = $inventory();
                $this->assertTrue($damaged['runtime_grant_allowlist_accepted']);
                $this->assertFalse($damaged['guards']['held_documents']);
                $this->assertFalse($damaged['listed_guards_active']);
                $this->assertFalse($damaged['closure_authorized']);
                $this->artisan('financial:database-preflight', ['--guards' => true])
                    ->expectsOutput('held_documents: missing or unverifiable')->assertExitCode(1);
            } finally {
                $useAdmin();
                DB::unprepared('DROP TRIGGER held_assignment_reject_update');
                app(\App\Services\HeldCashDocumentProtection::class)->install();
            }
            $useAdmin();
            foreach ([
                [\App\Services\HeldRegistryLinkProtection::class, 'fin_held_link_held_cash_assignment', 'fin_held_link_held_cash_payout', 'treasury_held_cash_payouts'],
                [\App\Services\HeldCashDestinationProtection::class, 'fin_held_dest_treasury_held_cash_assignments_i', 'fin_held_dest_treasury_held_cash_payouts_i', 'treasury_held_cash_payouts'],
            ] as [$service, $missing, $wrong, $table]) {
                $protection = app($service);
                $this->assertTrue($protection->active());
                DB::unprepared('DROP TRIGGER '.$missing);
                DB::unprepared('DROP TRIGGER '.$wrong);
                $body = DB::getDriverName() === 'sqlite' ? 'BEGIN SELECT 1; END' : 'FOR EACH ROW SET NEW.amount_minor = NEW.amount_minor';
                DB::unprepared("CREATE TRIGGER {$wrong} BEFORE INSERT ON {$table} {$body}");
                try {
                    $this->assertFalse($protection->active());
                    try {
                        $protection->install();
                        $this->fail('Accepted conflicting trigger definition.');
                    } catch (\LogicException $error) {
                        $this->assertStringContainsString($wrong, $error->getMessage());
                    }
                    $exists = DB::getDriverName() === 'sqlite'
                        ? DB::table('sqlite_master')->where('type', 'trigger')->where('name', $missing)->exists()
                        : \App\Services\FinancialTriggerMetadata::query()->where('TRIGGER_NAME', $missing)->isNotEmpty();
                    $this->assertFalse($exists, 'Installation must not perform partial DDL.');
                } finally {
                    DB::unprepared('DROP TRIGGER '.$wrong);
                    $protection->install();
                }
                $this->assertTrue($protection->active());
            }
            $useAdmin();
            DB::unprepared('DROP TRIGGER ledger_reject_update');
            try {
                $useRuntime();
                $damaged = $inventory();
                $this->assertFalse($damaged['guards']['ledger']);
                $this->assertFalse($damaged['listed_guards_active']);
                $this->assertFalse($damaged['closure_authorized']);
                $this->artisan('ledger:protect', ['--check' => true])->assertExitCode(1);
                $this->artisan('ledger:protect', ['--install' => true])->assertExitCode(1);
                $this->assertFalse(app(\App\Services\LedgerProtection::class)->active());
            } finally {
                $useAdmin();
                app(\App\Services\LedgerProtection::class)->install();
            }
            $useAdmin();
            DB::unprepared('DROP INDEX fin_collection_audit_identity ON audit_events');
            try {
                $useRuntime();
                $damaged = $inventory();
                $this->assertTrue($damaged['runtime_grant_allowlist_accepted']);
                $this->assertFalse($damaged['guards']['collection_evidence_audit']);
                $this->assertFalse($damaged['listed_guards_active']);
                $this->assertFalse($damaged['closure_authorized']);
                $this->artisan('financial:database-preflight', ['--guards' => true])
                    ->expectsOutput('collection_evidence_audit: missing or unverifiable')->assertExitCode(1);
            } finally {
                $useAdmin();
                app(\App\Services\CollectionEvidenceAuditProtection::class)->installIdentity();
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
