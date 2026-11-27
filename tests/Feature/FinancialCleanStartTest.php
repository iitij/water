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
