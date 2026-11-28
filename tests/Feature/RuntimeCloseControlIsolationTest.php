<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\FinancialWriteGate;
use App\Services\RuntimeDatabasePrivileges;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

/** Live database permissions, not a simulated SHOW GRANTS response.
 * Provisioned only in the disposable MySQL integration workflow.
 */
final class RuntimeCloseControlIsolationTest extends TestCase
{
    public function test_prepare_disposable_runtime_posting_fixture(): void
    {
        if (! app()->environment('testing') || DB::getDriverName() !== 'mysql'
            || DB::getDatabaseName() !== 'water_test'
            || config('database.connections.mysql.username') !== 'water_qa') {
            $this->markTestSkipped('Requires privileged disposable migration identity.');
        }
        $actor = \App\Models\User::factory()->create(['role' => 'admin', 'email' => 'runtime-posting@example.test']);
        $actor->assignRole(\Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web'));
        $this->travelTo(\Carbon\Carbon::parse('2026-11-25T08:30:00Z'));
        app(\App\Services\LedgerProtection::class)->install();
        $subscriber = \App\Models\Subscriber::create(['name' => 'Synthetic restricted subscriber',
            'square_id' => \App\Models\Square::create(['name' => 'Restricted posting square'])->id, 'status' => 'active']);
        \App\Models\Tariff::create(['name' => 'Restricted posting tariff', 'unit_price' => '1.00', 'is_active' => true]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-RESTRICTED-METER']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-11-24',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-24', 'previous_reading' => 0, 'current_reading' => 1]);
        app(\App\Services\FinancialTriggerMetadata::class)->install();
        $this->assertTrue(app(\App\Services\FinancialTriggerMetadata::class)->deploymentVerified());
        $this->assertTrue($actor->hasRole('super_admin'));
    }

    public function test_restricted_runtime_can_post_and_replay_registered_treasury_opening(): void
    {
        if (! app()->environment('testing') || DB::getDriverName() !== 'mysql'
            || DB::getDatabaseName() !== 'water_test'
            || config('database.connections.mysql.username') !== 'water_runtime_qa') {
            $this->markTestSkipped('Requires restricted disposable MySQL runtime identity.');
        }
        $this->assertTrue(config('database.financial_trigger_metadata_routine'));
        $this->assertTrue(app(RuntimeDatabasePrivileges::class)->inspect()['close_control_write_isolated']);
        $this->assertTrue(app(\App\Services\FinancialPostingRegistryProtection::class)->active());
        $actor = \App\Models\User::query()->where('email', 'runtime-posting@example.test')->sole();
        $this->assertTrue($actor->hasRole('super_admin'));
        $now = new DateTimeImmutable('2026-11-25T08:30:00Z');
        $this->travelTo(\Carbon\Carbon::instance($now));
        $writer = app(\App\Services\GatedTreasuryOpeningFundingWriter::class);
        $data = ['code' => 'QA-RESTRICTED-POST', 'name' => 'Synthetic restricted treasury',
            'channel' => 'cash', 'opening_on' => '2026-11-25', 'opening_amount' => '1.23',
            'opening_reference' => 'QA-RESTRICTED-PROOF', 'opening_evidence' => 'Synthetic independent count evidence'];
        $uuid = 'e138e335-1c32-4ab4-8c46-68dbdb5f6720';
        $first = $writer->open($actor, $data, $uuid, $now);
        $this->assertTrue($first['registry_created']);
        $this->assertSame(123, (int) $first['account']['opening_minor']);
        $replay = $writer->open($actor, $data, $uuid, $now);
        $this->assertFalse($replay['registry_created']);
        $this->assertSame($first['account']['id'], $replay['account']['id']);
        $this->assertDatabaseCount('treasury_accounts', 1);
        $this->assertDatabaseCount('financial_posting_operations', 1);
        $this->assertDatabaseCount('financial_posting_events', 1);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $uuid,
            'source_type' => 'treasury_opening', 'leg' => 'opening', 'amount_minor' => 123]);
        $this->assertDatabaseHas('audit_events', ['event' => 'treasury.opened', 'entity_id' => (string) $first['account']['id']]);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertDatabaseCount('financial_period_closures', 0);
        $this->assertDatabaseHas('financial_periods', ['month' => '2026-11', 'state' => 'OPEN', 'version' => 2]);
    }

    public function test_restricted_subscriber_invoice_receipt_and_allocation_cycle(): void
    {
        if (! app()->environment('testing') || DB::getDriverName() !== 'mysql'
            || DB::getDatabaseName() !== 'water_test'
            || config('database.connections.mysql.username') !== 'water_runtime_qa') {
            $this->markTestSkipped('Requires restricted disposable MySQL runtime identity.');
        }
        $this->assertTrue(app(RuntimeDatabasePrivileges::class)->inspect()['close_control_write_isolated']);
        app(\App\Services\SubscriberFinancialWriteProtection::class)->requireActive();
        $actor = \App\Models\User::query()->where('email', 'runtime-posting@example.test')->sole();
        $meter = \App\Models\Meter::query()->where('unique_code', 'QA-RESTRICTED-METER')->sole();
        $subscriber = $meter->subscriber;
        $now = new DateTimeImmutable('2026-11-25T08:30:00Z');
        $this->travelTo(\Carbon\Carbon::instance($now));
        $invoiceUuid = 'e138e336-1c32-4ab4-8c46-68dbdb5f6720';
        $receiptUuid = 'e138e337-1c32-4ab4-8c46-68dbdb5f6720';
        $invoices = app(\App\Services\GatedInvoiceTransferWriter::class);
        $receiptWriter = app(\App\Services\GatedCashReceiptWriter::class);
        $issue = fn () => $invoices->issue($actor, (int) $subscriber->id, (int) $meter->id, $invoiceUuid, $now);
        $first = $issue();
        $invoice = $first['invoice'];
        $this->assertTrue($first['registry_created']);
        $this->assertSame('1.00', $invoice->total_amount);
        $this->assertSame(-100, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertFalse($issue()['registry_created']);
        $collect = fn () => $receiptWriter->collect($actor, (int) $subscriber->id, '0.40', $receiptUuid,
            $now, invoiceId: (int) $invoice->id);
        $receipt = $collect();
        $this->assertTrue($receipt['registry_created']);
        $this->assertFalse($collect()['registry_created']);
        $this->assertSame(-60, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertSame('0.40', $invoice->fresh()->amount_paid);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('readings', ['meter_id' => $meter->id, 'invoice_id' => $invoice->id, 'is_invoiced' => 1]);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $invoiceUuid,
            'source_type' => 'ledger_transaction', 'leg' => 'subscriber', 'amount_minor' => -100]);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $receiptUuid,
            'source_type' => 'ledger_transaction', 'leg' => 'subscriber', 'amount_minor' => 40]);
        $this->assertSame(2, DB::table('financial_posting_operations')->whereIn('operation_uuid', [$invoiceUuid, $receiptUuid])->count());
        $this->assertSame(2, DB::table('financial_posting_events')->whereIn('operation_uuid', [$invoiceUuid, $receiptUuid])->count());
        $period = (int) DB::table('financial_periods')->where('month', '2026-11')->value('id');
        $this->travelTo(\Carbon\Carbon::parse('2026-12-01T00:00:00+03:00'));
        $proof = DB::transaction(fn () => app(\App\Services\FinancialSubscriberPeriodEvidence::class)->inspect($period));
        $this->assertTrue($proof['all_cutoff_accounts_match'], json_encode($proof));
        $this->assertTrue($proof['allocation_policy_verified']);
        $this->assertSame(40, $proof['rows'][0]['allocated_minor']);
        $this->assertSame(0, $proof['rows'][0]['opening_minor']);
        $this->assertSame(-60, $proof['rows'][0]['net_movement_minor']);
        $this->assertSame(-60, $proof['rows'][0]['closing_minor']);
        $this->assertFalse($proof['closure_authorized']);
        $this->assertDatabaseCount('financial_period_closures', 0);
        $this->assertDatabaseHas('financial_periods', ['month' => '2026-11', 'state' => 'OPEN', 'version' => 2]);
    }

    public function test_restricted_runtime_reversal_in_next_period_preserves_prior_cutoff(): void
    {
        if (! app()->environment('testing') || DB::getDriverName() !== 'mysql'
            || DB::getDatabaseName() !== 'water_test'
            || config('database.connections.mysql.username') !== 'water_runtime_qa') {
            $this->markTestSkipped('Requires restricted disposable MySQL runtime identity.');
        }
        $this->assertTrue(app(RuntimeDatabasePrivileges::class)->inspect()['close_control_write_isolated']);
        $actor = \App\Models\User::query()->where('email', 'runtime-posting@example.test')->sole();
        $meter = \App\Models\Meter::query()->where('unique_code', 'QA-RESTRICTED-METER')->sole();
        $subscriber = $meter->subscriber;
        $invoice = \App\Models\Invoice::query()->where('subscriber_id', $subscriber->id)->sole();
        $nov = DB::table('financial_periods')->where('month', '2026-11')->sole();
        $dec = DB::table('financial_periods')->where('month', '2026-12')->sole();
        // The isolated migration identity establishes a CLOSED projection only.
        // It is not a certified journal or an authorized close transition.
        $this->assertSame('CLOSED', $nov->state);
        $this->assertSame('OPEN', $dec->state);
        $this->assertDatabaseCount('financial_period_closures', 0);
        $now = new DateTimeImmutable('2026-12-02T08:30:00Z');
        $this->travelTo(\Carbon\Carbon::instance($now));
        $inspect = fn (int $id) => DB::transaction(fn () => app(\App\Services\FinancialSubscriberPeriodEvidence::class)->inspect($id));
        $before = $inspect((int) $nov->id);
        $this->assertTrue($before['all_cutoff_accounts_match'], json_encode($before));
        $this->assertSame(-60, $before['rows'][0]['closing_minor']);
        $this->assertSame(40, $before['rows'][0]['allocated_minor']);
        $priorTransactions = DB::table('transactions')->where('subscriber_id', $subscriber->id)->orderBy('id')->get()->all();
        $priorOperations = DB::table('financial_posting_operations')->where('period_id', $nov->id)->orderBy('operation_uuid')->get()->all();
        $priorLegs = DB::table('financial_posting_events')->whereIn('operation_uuid', array_column($priorOperations, 'operation_uuid'))->orderBy('id')->get()->all();
        $receipt = app(\App\Services\GatedCashReceiptWriter::class);
        try {
            $receipt->collect($actor, (int) $subscriber->id, '0.10', 'e138e338-1c32-4ab4-8c46-68dbdb5f6720',
                $now, invoiceId: (int) $invoice->id, effectiveOn: '2026-11-30');
            $this->fail('Closed period accepted a new receipt.');
        } catch (\DomainException $error) {
            $this->assertSame('financial_period_not_open', $error->getMessage());
        }
        $this->assertDatabaseCount('payments', 1);
        $writer = app(\App\Services\GatedInvoiceTransferWriter::class);
        $uuid = 'e138e339-1c32-4ab4-8c46-68dbdb5f6720';
        $cancel = fn () => $writer->cancel($actor, (int) $invoice->id,
            'Independent documented prospective invoice cancellation', $uuid, $now);
        $this->assertTrue($cancel()['registry_created']);
        $this->assertFalse($cancel()['registry_created']);
        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->assertSame(40, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseHas('financial_posting_operations', ['operation_uuid' => $uuid, 'period_id' => $dec->id, 'effective_on' => '2026-12-02']);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => $uuid, 'amount_minor' => 100, 'leg' => 'subscriber']);
        $this->assertDatabaseHas('audit_events', ['event' => 'invoice.cancelled', 'entity_id' => (string) $invoice->id]);
        $this->assertSame(1, DB::table('financial_posting_events')->where('operation_uuid', $uuid)->count());
        $this->assertEquals($priorTransactions, DB::table('transactions')->whereIn('id', array_column($priorTransactions, 'id'))->orderBy('id')->get()->all());
        $this->assertEquals($priorOperations, DB::table('financial_posting_operations')->where('period_id', $nov->id)->orderBy('operation_uuid')->get()->all());
        $this->assertEquals($priorLegs, DB::table('financial_posting_events')->whereIn('operation_uuid', array_column($priorOperations, 'operation_uuid'))->orderBy('id')->get()->all());
        $this->travelTo(\Carbon\Carbon::parse('2027-01-01T00:00:00+03:00'));
        $this->assertSame($before, $inspect((int) $nov->id));
        $after = $inspect((int) $dec->id);
        $this->assertTrue($after['all_cutoff_accounts_match'], json_encode($after));
        $this->assertTrue($after['allocation_policy_verified']);
        $row = $after['rows'][0];
        $this->assertSame(-60, $row['opening_minor']);
        $this->assertSame(100, $row['net_movement_minor']);
        $this->assertSame(40, $row['closing_minor']);
        $this->assertSame(0, $row['allocated_minor']);
        $this->assertSame(40, $row['unallocated_minor']);
        $this->assertSame($before['rows'][0]['source_hash'], $row['prior_source_hash']);
        $this->assertFalse($after['closure_authorized']);
        $this->assertDatabaseCount('financial_period_closures', 0);
        $this->assertEquals($nov, DB::table('financial_periods')->where('id', $nov->id)->sole());
    }

    public function test_restricted_runtime_can_lock_control_rows_but_cannot_write_them(): void
    {
        if (! app()->environment('testing') || DB::getDriverName() !== 'mysql'
            || DB::getDatabaseName() !== 'water_test'
            || config('database.connections.mysql.username') !== 'water_runtime_qa') {
            $this->markTestSkipped('Requires restricted disposable MySQL runtime identity.');
        }
        $pdo = DB::connection()->getPdo();
        $this->assertStringStartsWith('water_runtime_qa@', (string) $pdo->query('SELECT CURRENT_USER()')->fetchColumn());
        $report = app(RuntimeDatabasePrivileges::class)->inspect();
        $this->assertTrue($report['accepted'], json_encode($report));
        $this->assertTrue($report['close_control_write_isolated'], json_encode($report));
        $this->assertSame([], $report['close_control_issues']);

        // Exercise the real application lock path using the restricted identity.
        $context = app(FinancialWriteGate::class)->withinOpenPeriod('water', '2026-11-25',
            new DateTimeImmutable('2026-11-25T08:30:00Z'), fn (array $context) => $context);
        $this->assertSame('water', $context['scope']);
        $this->assertSame(5, $context['epoch']);
        $this->assertSame(2, $context['version']);
        $this->assertSame(0, DB::transactionLevel());

        $tables = [
            'financial_scopes' => 'scope_key',
            'financial_periods' => 'id',
            'financial_close_commands' => 'command_uuid',
            'financial_period_closures' => 'period_id',
        ];
        $before = [];
        foreach ($tables as $table => $key) {
            $before[$table] = $pdo->query("SELECT * FROM {$table} ORDER BY {$key}")->fetchAll(\PDO::FETCH_ASSOC);
            // Explicitly empty statements test SQL privilege enforcement without
            // modifying any row even if the deployment is misconfigured.
            foreach ([
                "INSERT INTO {$table} SELECT * FROM {$table} WHERE 1=0",
                "UPDATE {$table} SET {$key}={$key} WHERE 1=0",
                "DELETE FROM {$table} WHERE 1=0",
            ] as $statement) {
                try {
                    $pdo->exec($statement);
                    $this->fail('Unexpected permission for protected control table: '.$table);
                } catch (PDOException $error) {
                    $this->assertSame('42000', $error->errorInfo[0]);
                    $this->assertSame(1142, (int) $error->errorInfo[1]);
                }
            }
        }
        // Compare with an ordinary permitted application table. These are
        // no-op privilege probes, not a claim of full posting-path coverage.
        $this->assertSame(0, $pdo->exec('UPDATE payments SET id=id WHERE 1=0'));
        $this->assertSame(0, $pdo->exec('DELETE FROM payments WHERE 1=0'));
        foreach ($tables as $table => $key) {
            $this->assertSame($before[$table], $pdo->query("SELECT * FROM {$table} ORDER BY {$key}")->fetchAll(\PDO::FETCH_ASSOC));
        }
    }
}
