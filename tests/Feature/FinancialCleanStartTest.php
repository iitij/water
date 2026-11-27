<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Subscriber;
use App\Models\Square;
use App\Models\User;
use App\Services\FinancialCleanStart;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class FinancialCleanStartTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing') || ! (
            (DB::getDriverName() === 'sqlite' && DB::getDatabaseName() === ':memory:')
            || (DB::getDriverName() === 'mysql' && DB::getDatabaseName() === 'water_test')
        )) {
            $this->markTestSkipped('Requires disposable clean-install database.');
        }
        RefreshDatabaseState::$migrated = false;
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->travelTo(\Carbon\Carbon::parse('2026-12-01T00:00:00+03:00'));
    }

    private function actor(): User
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));
        Sanctum::actingAs($actor);
        return $actor;
    }

    private function rejects(string $reason, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected rejection.');
        } catch (DomainException $error) {
            $this->assertSame($reason, $error->getMessage());
        }
    }

    public function test_clean_start_creates_only_initial_disabled_period_and_audit(): void
    {
        $actor = $this->actor();
        $service = app(FinancialCleanStart::class);
        $this->rejects('clean_start_requires_current_month_and_outer_transaction',
            fn () => $service->initialize($actor, '2026-11'));
        $result = $service->initialize($actor, '2026-12');
        $this->assertSame('2026-12-01', $result['start_on']);
        $this->assertSame('2027-01-01', $result['next_on']);
        $this->assertFalse($result['root_opening_verified']);
        $this->assertFalse($result['closure_authorized']);
        $this->assertDatabaseHas('financial_scopes', ['scope_key' => 'water', 'mode' => 'LEGACY_DISABLED', 'fence_epoch' => 0]);
        $this->assertDatabaseHas('financial_periods', ['id' => $result['period_id'], 'state' => 'OPEN', 'version' => 0]);
        $this->assertDatabaseHas('audit_events', ['event' => 'financial.clean_start_initialized', 'actor_user_id' => $actor->id]);
        $this->rejects('clean_start_nonempty:audit_events', fn () => $service->initialize($actor, '2026-12'));
        $this->assertDatabaseCount('financial_scopes', 1);
        $this->assertDatabaseCount('financial_periods', 1);
        $this->assertDatabaseCount('audit_events', 1);
        $this->assertDatabaseCount('financial_posting_operations', 0);
        $this->assertDatabaseCount('financial_period_closures', 0);
    }

    public function test_clean_start_audit_is_immutable_and_requires_installed_guards(): void
    {
        $actor = $this->actor();
        $service = app(FinancialCleanStart::class);
        $guard = app(\App\Services\FinancialCleanStartAuditProtection::class);
        $this->assertTrue($guard->active());
        DB::unprepared('DROP TRIGGER fin_start_audit_delete');
        try {
            $this->rejects('clean_start_audit_protection_missing',
                fn () => $service->initialize($actor, '2026-12'));
            $this->assertDatabaseCount('financial_scopes', 0);
            $this->assertDatabaseCount('audit_events', 0);
        } finally {
            $guard->install();
        }
        $service->initialize($actor, '2026-12');
        $row = (array) DB::table('audit_events')->sole();
        $duplicate = $row;
        unset($duplicate['id']);
        $actions = [
            fn () => DB::table('audit_events')->where('id', $row['id'])->update(['entity_id' => 'other']),
            fn () => DB::table('audit_events')->where('id', $row['id'])->delete(),
            fn () => DB::table('audit_events')->insert($duplicate),
        ];
        if (DB::getDriverName() === 'sqlite') {
            $replacement = $row;
            $replacement['event'] = 'unrelated.event';
            $actions[] = fn () => DB::statement('INSERT OR REPLACE INTO audit_events ('.implode(',', array_keys($replacement))
                .') VALUES ('.implode(',', array_fill(0, count($replacement), '?')).')', array_values($replacement));
        }
        foreach ($actions as $action) {
            try {
                $action();
                $this->fail('Expected protected audit mutation rejection.');
            } catch (\Illuminate\Database\QueryException $error) {
                $this->assertStringContainsString('Financial clean-start audit is immutable or invalid', $error->getMessage());
            }
            $this->assertEquals($row, (array) DB::table('audit_events')->sole());
        }
        $this->assertTrue($guard->active());
    }

    public function test_independent_review_confirms_empty_start_without_activating_writers(): void
    {
        $initializer = $this->actor();
        $service = app(FinancialCleanStart::class);
        $initial = $service->initialize($initializer, '2026-12');
        $this->rejects('clean_start_review_requires_independent_actor', fn () => $service->review($initializer));
        $reviewer = $this->actor();
        $result = $service->review($reviewer);
        $this->assertSame($initial['period_id'], $result['period_id']);
        $this->assertSame($initializer->id, $result['initialized_by']);
        $this->assertSame($reviewer->id, $result['reviewed_by']);
        $this->assertTrue($result['empty_start_reviewed']);
        $this->assertFalse($result['root_opening_verified']);
        $this->assertFalse($result['closure_authorized']);
        $this->assertDatabaseHas('financial_scopes', ['scope_key' => 'water', 'mode' => 'LEGACY_DISABLED']);
        $this->assertDatabaseHas('audit_events', ['event' => 'financial.clean_start_reviewed', 'actor_user_id' => $reviewer->id]);
        $this->rejects('clean_start_review_audit_count', fn () => $service->review($reviewer));
        $this->assertDatabaseCount('audit_events', 2);
        $row = (array) DB::table('audit_events')->where('event', 'financial.clean_start_reviewed')->sole();
        try {
            DB::table('audit_events')->where('id', $row['id'])->delete();
            $this->fail('Expected immutable review.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('Financial clean-start audit is immutable or invalid', $error->getMessage());
        }
        $this->assertEquals($row, (array) DB::table('audit_events')->where('id', $row['id'])->sole());
    }

    public function test_review_rejects_new_records_after_initialization(): void
    {
        $initializer = $this->actor();
        $service = app(FinancialCleanStart::class);
        $service->initialize($initializer, '2026-12');
        $reviewer = $this->actor();
        $subscriber = Subscriber::create(['name' => 'Synthetic late subscriber',
            'square_id' => Square::create(['name' => 'QA square'])->id, 'status' => 'active']);
        $this->rejects('clean_start_nonempty:subscribers', fn () => $service->review($reviewer));
        $this->assertDatabaseCount('audit_events', 1);
        $this->assertDatabaseHas('subscribers', ['id' => $subscriber->id]);
        $this->assertDatabaseHas('financial_scopes', ['scope_key' => 'water', 'mode' => 'LEGACY_DISABLED']);
    }

    public function test_clean_start_refuses_existing_records_without_deleting_them(): void
    {
        $actor = $this->actor();
        $subscriber = Subscriber::create(['name' => 'Synthetic existing subscriber',
            'square_id' => Square::create(['name' => 'QA square'])->id, 'status' => 'active']);
        $before = DB::table('subscribers')->get()->toArray();
        $this->rejects('clean_start_nonempty:subscribers',
            fn () => app(FinancialCleanStart::class)->initialize($actor, '2026-12'));
        $this->assertEquals($before, DB::table('subscribers')->get()->toArray());
        $this->assertDatabaseHas('subscribers', ['id' => $subscriber->id]);
        $this->assertDatabaseCount('financial_scopes', 0);
        $this->assertDatabaseCount('financial_periods', 0);
        $this->assertDatabaseCount('financial_posting_operations', 0);
    }
}
