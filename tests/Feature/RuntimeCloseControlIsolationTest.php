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
