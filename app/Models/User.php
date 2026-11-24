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
    use HasFactory, Notifiable, HasRoles;
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

    public function canBeDeactivatedBy(User $actor): bool
    {
        return ! $this->isDisabled() && ! $actor->isDisabled()
            && ! $this->is($actor) && ! $this->hasRole('super_admin');
    }

    /** Preserve the identity referenced by cash sessions, receipts and audits. */
    public function deactivate(User $actor): bool
    {
        return DB::transaction(function () use ($actor): bool {
            $account = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            if (! $account->canBeDeactivatedBy($actor)) {
                return false;
            }

            $account->forceFill([
                'disabled_at' => now(),
                'password' => Hash::make(Str::random(64)),
                'remember_token' => null,
            ])->saveOrFail();
            $account->tokens()->delete();
            // The application's database-backed sessions share this database.
            if (config('session.driver') === 'database' && ! config('session.connection')) {
                DB::table(config('session.table', 'sessions'))->where('user_id', $account->id)->delete();
            }
            DB::table('password_reset_tokens')->where('email', $account->email)->delete();

            app(\App\Services\AuditLogger::class)->recordRequired('user.deactivated', [
                'actor_user_id' => $actor->id,
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
        abort_if($this->isDisabled(), 401, 'الحساب معطل.');

        return $this->attachSanctumToken($accessToken);
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
        return ! $this->isDisabled();
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
