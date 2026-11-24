<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CashSession;
use App\Models\Payment;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\AuditLogger;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Str;
use Livewire\Livewire;
use App\Filament\Resources\Users\Pages\ListUsers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DeletedRecordIntegrityTest extends TestCase
{
    use RefreshDatabase { refreshDatabase as private refreshOrdinaryDatabase; }

    public function refreshDatabase()
    {
        if ($this->name() !== 'test_mysql_parallel_deactivation_token_issuance_and_financial_posting') {
            $this->refreshOrdinaryDatabase();
            return;
        }
        if (! app()->environment('testing') || DB::getDriverName() !== 'mysql'
            || DB::getDatabaseName() !== 'water_test' || getenv('ACC10_RACE_TEST') !== '1'
            || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires explicitly authorized disposable MySQL water_test and pcntl.');
        }
        // The forked connections must see committed fixtures, not a parent transaction.
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        RefreshDatabaseState::$migrated = false;
    }

    private function admin(): User
    {
        Role::findOrCreate('super_admin', 'web');
        return tap(User::factory()->create(), fn (User $user) => $user->assignRole('super_admin'));
    }

    private function financialFixture(User $collector): array
    {
        $subscriber = Subscriber::create([
            'name' => 'Synthetic integrity account',
            'square_id' => Square::create(['name' => 'Synthetic integrity square'])->id,
            'status' => 'active',
        ]);
        $session = CashSession::create([
            'user_id' => $collector->id, 'session_date' => '2026-11-24',
            'opening_amount' => 50, 'expected_amount' => 150, 'counted_amount' => 150,
            'variance_amount' => 0, 'state' => CashSession::STATE_APPROVED,
        ]);
        $payment = Payment::create([
            'subscriber_id' => $subscriber->id, 'user_id' => $collector->id,
            'cash_session_id' => $session->id, 'receipt_number' => 'ACC10-R1',
            'amount' => 100, 'payment_date' => '2026-11-24', 'payment_method' => 'cash',
        ]);
        $event = AuditEvent::create([
            'actor_user_id' => $collector->id, 'event' => 'synthetic.posting',
            'source' => 'test', 'occurred_at' => now(),
        ]);
        return [$subscriber, $session, $payment, $event];
    }

    public function test_model_rejects_physical_deletion_of_user(): void
    {
        $user = User::factory()->create();
        $this->expectException(ValidationException::class);
        $user->delete();
    }

    public function test_deactivation_preserves_financial_relationships_amounts_and_audit_identity(): void
    {
        $collector = User::factory()->create();
        [$subscriber, $session, $payment, $event] = $this->financialFixture($collector);
        $before = [
            $subscriber->fresh()->getAttributes(), $session->fresh()->getAttributes(),
            $payment->fresh()->getAttributes(), $event->fresh()->getAttributes(),
        ];
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')->deleteJson('/api/users/'.$collector->id)->assertOk();
        $this->assertTrue($collector->fresh()->isDisabled());
        $this->assertSame($before, [
            $subscriber->fresh()->getAttributes(), $session->fresh()->getAttributes(),
            $payment->fresh()->getAttributes(), $event->fresh()->getAttributes(),
        ]);
        $this->assertSame($collector->id, $session->fresh()->user->id);
        $this->assertSame($collector->id, $payment->fresh()->user->id);
        $this->assertSame($collector->id, $event->fresh()->actor->id);
        $this->assertDatabaseHas('audit_events', [
            'event' => 'user.deactivated', 'actor_user_id' => $admin->id,
            'entity_id' => (string) $collector->id,
        ]);
        $this->getJson('/api/users/'.$collector->id)->assertOk()
            ->assertJsonPath('user.id', $collector->id)
            ->assertJsonStructure(['user' => ['disabled_at']]);
        $this->putJson('/api/users/'.$collector->id, ['name' => 'Changed'])->assertStatus(409);
    }

    public function test_direct_sql_delete_cannot_cascade_through_cash_session(): void
    {
        $collector = User::factory()->create();
        [, $session, $payment, $event] = $this->financialFixture($collector);
        try {
            DB::table('users')->where('id', $collector->id)->delete();
            $this->fail('Database accepted physical user deletion');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('User identity cannot be deleted', $exception->getMessage());
        }
        $this->assertDatabaseHas('users', ['id' => $collector->id]);
        $this->assertDatabaseHas('cash_sessions', ['id' => $session->id, 'user_id' => $collector->id]);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'user_id' => $collector->id]);
        $this->assertDatabaseHas('audit_events', ['id' => $event->id, 'actor_user_id' => $collector->id]);
    }

    public function test_disabled_account_cannot_log_in_with_previous_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('SyntheticPassword123')]);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'SyntheticPassword123'])->assertOk();
        $this->assertTrue($user->deactivate($this->admin()));
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'SyntheticPassword123'])->assertUnprocessable();
        $this->assertFalse(Hash::check('SyntheticPassword123', $user->fresh()->password));
        $this->assertFalse($user->fresh()->canAccessPanel(Filament::getDefaultPanel()));
    }

    public function test_existing_bearer_token_is_revoked_and_rejected(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('synthetic')->plainTextToken;
        $this->assertTrue($user->deactivate($this->admin()));
        $this->assertSame(0, $user->tokens()->count());
        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_stale_instance_cannot_issue_token_after_deactivation(): void
    {
        $user = User::factory()->create();
        $stale = $user->fresh();
        $this->assertTrue($user->deactivate($this->admin()));
        try {
            $stale->createToken('stale refresh');
            $this->fail('Stale account issued a token');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(401, $exception->getStatusCode());
        }
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_disabled_web_identity_is_denied_even_with_existing_session(): void
    {
        $user = User::factory()->create();
        $this->assertTrue($user->deactivate($this->admin()));
        $this->actingAs($user->fresh(), 'web')->get('/admin')->assertForbidden();
    }

    public function test_disabled_session_cannot_be_used_as_sanctum_identity(): void
    {
        $user = User::factory()->create();
        $this->assertTrue($user->deactivate($this->admin()));
        $this->actingAs($user->fresh(), 'web')->getJson('/api/me')->assertUnauthorized();
    }

    public function test_bulk_deactivation_skips_self_super_admin_and_already_disabled(): void
    {
        $admin = $this->admin();
        $otherAdmin = $this->admin();
        $user = User::factory()->create();
        $disabled = User::factory()->create();
        $disabled->deactivate($admin);
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/users/bulk-delete', ['ids' => [$admin->id, $otherAdmin->id, $user->id, $disabled->id]])
            ->assertOk()->assertJsonPath('deactivated_count', 1)->assertJsonPath('skipped_count', 3);
        $this->assertTrue($user->fresh()->isDisabled());
        $this->assertFalse($admin->fresh()->isDisabled());
        $this->assertFalse($otherAdmin->fresh()->isDisabled());
        $this->assertDatabaseCount('users', 4);
    }

    public function test_deactivation_rolls_back_when_required_audit_fails(): void
    {
        $user = User::factory()->create();
        $user->createToken('synthetic');
        $password = $user->password;
        $this->mock(AuditLogger::class, function ($mock) {
            $mock->shouldReceive('recordRequired')->once()->andThrow(new \RuntimeException('synthetic audit failure'));
        });
        try {
            $user->deactivate($this->admin());
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('synthetic audit failure', $exception->getMessage());
        }
        $this->assertFalse($user->fresh()->isDisabled());
        $this->assertSame($password, $user->fresh()->password);
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_repeat_deactivation_is_idempotent_and_does_not_duplicate_audit(): void
    {
        $user = User::factory()->create();
        $admin = $this->admin();
        $this->assertTrue($user->deactivate($admin));
        $this->assertFalse($user->fresh()->deactivate($admin));
        $this->assertSame(1, AuditEvent::where('event', 'user.deactivated')->count());
    }
    public function test_unprivileged_user_cannot_deactivate_another_account(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $this->actingAs($actor, 'sanctum')->deleteJson('/api/users/'.$target->id)->assertForbidden();
        $this->assertFalse($target->fresh()->isDisabled());
    }

    public function test_filament_single_deactivation_keeps_user_record(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach (['ViewAny:User', 'View:User', 'Delete:User'] as $permission) {
            $admin->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($admin, 'web');
        Livewire::test(ListUsers::class)
            ->assertStatus(200)
            ->assertCanSeeTableRecords([$target])
            ->callTableAction('delete', $target)
            ->assertHasNoTableActionErrors();
        $this->assertTrue($target->fresh()->isDisabled());
        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    public function test_filament_bulk_deactivation_retains_protected_and_disabled_identities(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $protected = $this->admin();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach (['ViewAny:User', 'View:User', 'Delete:User', 'DeleteAny:User'] as $permission) {
            $admin->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($admin, 'web');
        Livewire::test(ListUsers::class)
            ->assertStatus(200)
            ->callTableBulkAction('delete', [$target, $protected]);
        $this->assertTrue($target->fresh()->isDisabled());
        $this->assertFalse($protected->fresh()->isDisabled());
        $this->assertDatabaseCount('users', 3);
    }

    public function test_database_sessions_and_password_reset_tokens_are_revoked(): void
    {
        config(['session.driver' => 'database', 'session.connection' => null, 'session.table' => 'sessions']);
        $admin = $this->admin();
        $target = User::factory()->create();
        DB::table('sessions')->insert([
            'id' => 'acc10-target', 'user_id' => $target->id,
            'payload' => '', 'last_activity' => time(),
        ]);
        DB::table('sessions')->insert([
            'id' => 'acc10-admin', 'user_id' => $admin->id,
            'payload' => '', 'last_activity' => time(),
        ]);
        DB::table('password_reset_tokens')->insert([
            'email' => $target->email, 'token' => 'synthetic', 'created_at' => now(),
        ]);
        $this->assertTrue($target->deactivate($admin));
        $this->assertDatabaseMissing('sessions', ['id' => 'acc10-target']);
        $this->assertDatabaseHas('sessions', ['id' => 'acc10-admin']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $target->email]);
    }

    public function test_unlinked_user_is_also_protected_from_direct_sql_delete(): void
    {
        $target = User::factory()->create();
        try {
            DB::table('users')->where('id', $target->id)->delete();
            $this->fail('Direct deletion bypassed identity protection');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('User identity cannot be deleted', $exception->getMessage());
        }
        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    /** Independent connections and a start barrier, on a disposable database only. */
    private function race(callable $first, callable $second): array
    {
        $dir = sys_get_temp_dir().'/water-acc10-race-'.Str::uuid();
        mkdir($dir, 0700);
        DB::disconnect();
        $pids = [];
        try {
            foreach ([$first, $second] as $index => $callback) {
                $pid = pcntl_fork();
                if ($pid === -1) throw new \RuntimeException('fork failed');
                if ($pid === 0) {
                    DB::purge();
                    touch("$dir/ready-$index");
                    $deadline = microtime(true) + 15;
                    while (! is_file("$dir/start")) {
                        if (microtime(true) > $deadline) exit(2);
                        usleep(1000);
                    }
                    try {
                        $result = ['ok' => true, 'value' => $callback()];
                    } catch (\Throwable $error) {
                        $result = ['ok' => false, 'class' => get_class($error), 'message' => $error->getMessage()];
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
            return array_map(fn ($index) => json_decode(file_get_contents("$dir/result-$index"), true, 512, JSON_THROW_ON_ERROR), [0, 1]);
        } finally {
            foreach ($pids as $pid) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
            foreach (glob("$dir/*") as $file) unlink($file);
            rmdir($dir);
        }
    }

    public function test_mysql_parallel_deactivation_token_issuance_and_financial_posting(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $targetId = $target->id;
        $adminId = $admin->id;
        $result = $this->race(
            fn () => User::findOrFail($targetId)->deactivate(User::findOrFail($adminId)),
            fn () => User::findOrFail($targetId)->createToken('concurrent')->accessToken->id,
        );
        $this->assertTrue($result[0]['ok'] && $result[0]['value'], json_encode($result));
        if (! $result[1]['ok']) {
            $this->assertSame(\Symfony\Component\HttpKernel\Exception\HttpException::class, $result[1]['class']);
        }
        $this->assertTrue(User::findOrFail($targetId)->isDisabled());
        $this->assertSame(0, User::findOrFail($targetId)->tokens()->count());
        $this->assertSame(1, AuditEvent::where('event', 'user.deactivated')->count());

        $collector = User::factory()->create();
        [$subscriber, $session] = $this->financialFixture($collector);
        $collectorId = $collector->id;
        $subscriberId = $subscriber->id;
        $sessionId = $session->id;
        $result = $this->race(
            fn () => DB::table('users')->where('id', $collectorId)->delete(),
            fn () => Payment::create([
                'subscriber_id' => $subscriberId, 'user_id' => $collectorId,
                'cash_session_id' => $sessionId, 'receipt_number' => 'ACC10-R2',
                'amount' => 25, 'payment_date' => '2026-11-24', 'payment_method' => 'cash',
            ])->id,
        );
        $this->assertFalse($result[0]['ok'], json_encode($result));
        $this->assertStringContainsString('User identity cannot be deleted', $result[0]['message']);
        $this->assertTrue($result[1]['ok'], json_encode($result));
        $this->assertDatabaseHas('users', ['id' => $collectorId]);
        $this->assertDatabaseHas('cash_sessions', ['id' => $sessionId, 'user_id' => $collectorId]);
        $this->assertDatabaseHas('payments', ['receipt_number' => 'ACC10-R2', 'user_id' => $collectorId, 'amount' => 25]);
    }

}
