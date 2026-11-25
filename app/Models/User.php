<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

use Spatie\Permission\Traits\HasRoles;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use DateTimeInterface;

#[Fillable(['name', 'email', 'password', 'role', 'avatar'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAvatar
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;
    use HasRoles {
        assignRole as private assignRoleInternal;
        syncRoles as private syncRolesInternal;
        removeRole as private removeRoleInternal;
        givePermissionTo as private givePermissionToInternal;
        syncPermissions as private syncPermissionsInternal;
        revokePermissionTo as private revokePermissionToInternal;
    }
    use HasApiTokens {
        createToken as private issueSanctumToken;
        withAccessToken as private attachSanctumToken;
    }

    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw ValidationException::withMessages([
                'user' => 'لا يجوز حذف حساب المستخدم نهائياً. استخدم تعطيل الحساب.',
            ]);
        });

        static::updating(function (User $user): void {
            if ($user->getOriginal('disabled_at') !== null && $user->isDirty(['disabled_at', 'password'])) {
                throw ValidationException::withMessages(['user' => 'الحساب المعطل لا يقبل إعادة التفعيل أو تغيير كلمة المرور.']);
            }
        });
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /** Lock the actor and all targets in ascending ID order. */
    public static function lockAccounts(array $ids)
    {
        return static::query()->whereIn('id', array_unique($ids))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    public function canBeDeactivatedBy(User $actor): bool
    {
        return ! $this->isDisabled() && ! $actor->isDisabled()
            && ! $this->is($actor) && ! $this->hasRole('super_admin')
            && $this->role !== 'super_admin';
    }

    private function mayDeactivate(bool $bulk, bool $api): bool
    {
        if ($this->isDisabled()) {
            return false;
        }
        $roles = $this->roles()->lockForUpdate()->get();
        $this->setRelation('roles', $roles);
        if ($api && ($roles->contains(fn ($role) => in_array($role->name, ['admin', 'super_admin'], true))
            || ($roles->isEmpty() && in_array($this->role, ['admin', 'super_admin'], true)))) {
            return true;
        }
        $names = [$bulk ? 'DeleteAny:User' : 'Delete:User'];
        if ($api) {
            $names[] = $bulk ? 'delete_any_user' : 'delete_user';
        }
        // Use current locking reads, not cached role/permission relationships.
        $permissions = $this->permissions()->lockForUpdate()->get();
        foreach ($roles as $role) {
            $permissions = $permissions->concat($role->permissions()->lockForUpdate()->get());
        }

        return $permissions->contains(fn ($permission) => in_array($permission->name, $names, true));
    }

    /** Preserve the identity referenced by cash sessions, receipts and audits. */
    public function deactivate(User $actor, bool $bulk = false, bool $api = true): bool
    {
        return DB::transaction(function () use ($actor, $bulk, $api): bool {
            $locked = static::lockAccounts([$actor->getKey(), $this->getKey()]);
            $account = $locked->get($this->getKey());
            $freshActor = $locked->get($actor->getKey());
            if (! $account || ! $freshActor || ! $freshActor->mayDeactivate($bulk, $api)) {
                return false;
            }
            $account->setRelation('roles', $account->roles()->lockForUpdate()->get());
            if (! $account->canBeDeactivatedBy($freshActor)) {
                return false;
            }

            $account->forceFill([
                'disabled_at' => now(),
                'password' => Hash::make(Str::random(64)),
                'remember_token' => null,
            ])->saveOrFail();
            $account->tokens()->delete();
            // The application's database-backed sessions share this database.
            if (config('session.driver') === 'database' && (! config('session.connection') || config('session.connection') === DB::getDefaultConnection())) {
                DB::table(config('session.table', 'sessions'))->where('user_id', $account->id)->delete();
            }
            DB::table('password_reset_tokens')->where('email', $account->email)->delete();

            app(\App\Services\AuditLogger::class)->recordRequired('user.deactivated', [
                'actor_user_id' => $freshActor->id,
                'entity_type' => self::class,
                'entity_id' => $account->id,
                'before' => ['disabled_at' => null],
                'after' => ['disabled_at' => $account->disabled_at->toISOString()],
            ]);
            $this->setRawAttributes($account->getAttributes(), true);

            return true;
        }, 3);
    }

    /** Serialize issuance with deactivation, including refresh on a stale model. */
    public function createToken(string $name, array $abilities = ['*'], ?DateTimeInterface $expiresAt = null)
    {
        return DB::transaction(function () use ($name, $abilities, $expiresAt) {
            $account = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            abort_if($account->isDisabled(), 401, 'الحساب معطل.');

            return $account->issueSanctumToken($name, $abilities, $expiresAt);
        }, 3);
    }

    public function withAccessToken($accessToken)
    {
        abort_if($this->isCurrentlyDisabled(), 401, 'الحساب معطل.');
        if ($accessToken instanceof \Laravel\Sanctum\PersonalAccessToken && $accessToken->exists) {
            abort_unless($this->tokens()->useWritePdo()->whereKey($accessToken->getKey())->exists(), 401, 'رمز الوصول ملغى.');
        }

        return $this->attachSanctumToken($accessToken);
    }

    public function isCurrentlyDisabled(): bool
    {
        return $this->isDisabled() || ($this->exists
            && ! static::query()->useWritePdo()->whereKey($this->getKey())->whereNull('disabled_at')->exists());
    }

    /** Serialize all model edits with deactivation, including stale instances. */
    public function save(array $options = [])
    {
        if (! $this->exists || ! $this->isDirty()) {
            return parent::save($options);
        }

        return DB::transaction(function () use ($options) {
            $fresh = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            if ($fresh->isDisabled()) {
                throw ValidationException::withMessages(['user' => 'الحساب معطل ولا يمكن تعديله.']);
            }
            return parent::save($options);
        }, 3);
    }

    private function mutateAccess(callable $callback): static
    {
        if (! $this->exists) {
            return $callback();
        }
        return DB::transaction(function () use ($callback): static {
            $fresh = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            if ($fresh->isDisabled()) {
                throw ValidationException::withMessages(['user' => 'لا يمكن تغيير صلاحيات الحساب المعطل.']);
            }
            $this->unsetRelation('roles')->unsetRelation('permissions');
            return $callback();
        }, 3);
    }

    public function assignRole(...$roles): static
    {
        return $this->mutateAccess(fn () => $this->assignRoleInternal(...$roles));
    }

    public function syncRoles(...$roles): static
    {
        return $this->mutateAccess(fn () => $this->syncRolesInternal(...$roles));
    }

    public function removeRole(...$roles): static
    {
        return $this->mutateAccess(fn () => $this->removeRoleInternal(...$roles));
    }

    public function givePermissionTo(...$permissions): static
    {
        return $this->mutateAccess(fn () => $this->givePermissionToInternal(...$permissions));
    }

    public function syncPermissions(...$permissions): static
    {
        return $this->mutateAccess(fn () => $this->syncPermissionsInternal(...$permissions));
    }

    public function revokePermissionTo($permission): static
    {
        return $this->mutateAccess(fn () => $this->revokePermissionToInternal($permission));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'disabled_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatar ? Storage::url($this->avatar) : null;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return ! $this->isCurrentlyDisabled();
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    public function isCollector(): bool
    {
        return $this->hasRole('collector');
    }

    public function isMeterReader(): bool
    {
        return $this->hasRole('meter_reader');
    }
}
