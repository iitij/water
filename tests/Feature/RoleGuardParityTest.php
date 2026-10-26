<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleGuardParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.guards.staff' => ['driver' => 'session', 'provider' => 'users']]);
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
    }

    public function test_matrix_only_lists_real_user_guards_and_separates_permission_names(): void
    {
        Permission::create(['name' => 'ViewAny:Invoice', 'guard_name' => 'web']);
        Permission::create(['name' => 'ViewAny:Payment', 'guard_name' => 'staff']);
        $result = $this->getJson('/api/roles/permissions-matrix')->assertOk();
        $this->assertContains('web', $result->json('guard_names'));
        $this->assertContains('staff', $result->json('guard_names'));
        $this->assertNotContains('sanctum', $result->json('guard_names'));
        $result->assertJsonPath('guard_permissions.web', ['ViewAny:Invoice'])
            ->assertJsonPath('guard_permissions.staff', ['ViewAny:Payment']);
    }

    public function test_invalid_guards_and_cross_guard_permissions_are_rejected_without_partial_creation(): void
    {
        $permission = Permission::create(['name' => 'ViewAny:Invoice', 'guard_name' => 'web']);
        $this->postJson('/api/roles', ['name' => 'unknown_guard', 'guard_name' => 'unknown'])
            ->assertUnprocessable()->assertJsonValidationErrors('guard_name');
        $this->postJson('/api/roles', [
            'name' => 'wrong_guard_permission', 'guard_name' => 'staff', 'permissions' => [$permission->id],
        ])->assertUnprocessable();
        $this->assertDatabaseMissing('roles', ['name' => 'wrong_guard_permission']);
    }

    public function test_unassigned_role_can_change_guard_with_explicit_permissions(): void
    {
        $webPermission = Permission::create(['name' => 'ViewAny:Invoice', 'guard_name' => 'web']);
        $staffPermission = Permission::create(['name' => 'ViewAny:Payment', 'guard_name' => 'staff']);
        $role = Role::create(['name' => 'auditor', 'guard_name' => 'web']);
        $role->givePermissionTo($webPermission);
        $this->putJson('/api/roles/'.$role->id, ['guard_name' => 'staff'])
            ->assertStatus(409);
        $this->putJson('/api/roles/'.$role->id, [
            'guard_name' => 'staff', 'permissions' => [$staffPermission->id],
        ])->assertOk()->assertJsonPath('role.guard_name', 'staff');
        $this->assertSame([$staffPermission->id], $role->fresh()->permissions->pluck('id')->all());
    }

    public function test_guard_change_cannot_silently_reassign_a_users_role(): void
    {
        $role = Role::create(['name' => 'assigned', 'guard_name' => 'web']);
        User::factory()->create(['role' => 'reader'])->assignRole($role);
        $this->putJson('/api/roles/'.$role->id, ['guard_name' => 'staff', 'permissions' => []])
            ->assertStatus(409);
        $this->assertSame('web', $role->fresh()->guard_name);
    }

    public function test_failed_permission_validation_rolls_back_role_name_and_guard(): void
    {
        $role = Role::create(['name' => 'original', 'guard_name' => 'web']);
        $permission = Permission::create(['name' => 'ViewAny:Invoice', 'guard_name' => 'web']);
        $this->putJson('/api/roles/'.$role->id, [
            'name' => 'changed', 'guard_name' => 'staff', 'permissions' => [$permission->id],
        ])->assertUnprocessable();
        $role->refresh();
        $this->assertSame('original', $role->name);
        $this->assertSame('web', $role->guard_name);
    }
}
