<?php

namespace App\Http\Controllers\Api;

use App\Models\Device;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends ApiController
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();
        if (! $user || $user->isDisabled() || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['بيانات تسجيل الدخول غير صحيحة.'],
            ]);
        }

        [$token, $expiresAt] = $this->issueToken($user, $request);

        app(AuditLogger::class)->record('auth.login', [
            'entity_type' => User::class,
            'entity_id' => $user->id,
            'actor_user_id' => $user->id,
            'source' => $request->header('X-Client-Source') === 'mobile' ? 'mobile' : 'api',
        ]);

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt?->toIso8601String(),
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * تدوير التوكن دون إعادة إدخال كلمة المرور.
     *
     * Sanctum لا يملك refresh token منفصلاً، لذلك نُصدر توكنًا جديدًا بنفس
     * الصلاحيات ونحذف القديم فورًا (rotation). التطبيق يستدعي هذا المسار مرة
     * واحدة عند أول 401 ثم يعيد الطلب الأصلي.
     *
     * مرجع: خطة الإصدار 2.0 — الفصل 9.1.
     */
    public function refresh(Request $request)
    {
        $user = $request->user();
        $current = $user->currentAccessToken();
        $abilities = $current instanceof PersonalAccessToken ? ($current->abilities ?: ['*']) : ['*'];

        [$token, $expiresAt] = $this->issueToken($user, $request, $abilities);

        if ($current instanceof PersonalAccessToken) {
            $current->delete();
        }

        app(AuditLogger::class)->record('auth.token_refreshed', [
            'entity_type' => User::class,
            'entity_id' => $user->id,
            'source' => $request->header('X-Client-Source') === 'mobile' ? 'mobile' : 'api',
        ]);

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt?->toIso8601String(),
            'user' => $this->userPayload($user),
        ]);
    }

    /** The /me route is the canonical mobile endpoint; /user remains compatible. */
    public function me(Request $request)
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    public function user(Request $request)
    {
        return $this->me($request);
    }

    /** Supports both PUT /profile and legacy POST /profile multipart requests. */
    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:6', 'confirmed'],
            'password_confirmation' => ['nullable', 'string'],
            'avatar' => ['nullable', 'image', 'max:5120'],
        ]);

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        if (! empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        if ($request->hasFile('avatar')) {
            if ($user->avatar) {
                Storage::disk('public')->delete($user->avatar);
            }
            $user->avatar = $request->file('avatar')->store('avatars', 'public');
        }

        $user->save();

        return response()->json([
            'message' => 'تم تحديث الملف الشخصي بنجاح.',
            'user' => $this->userPayload($user->fresh()),
        ]);
    }

    public function logout(Request $request)
    {
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['message' => 'تم تسجيل الخروج بنجاح.']);
    }

    /**
     * إنشاء توكن بصلاحية زمنية محددة. المدة تُقرأ من الإعدادات (بالساعات) مع
     * افتراضي 12 ساعة، ويمكن ضبطها إلى null لتعطيل الانتهاء المطلق.
     *
     * @param  array<int, string>|null  $abilities
     * @return array{0: string, 1: \Illuminate\Support\Carbon|null}
     */
    private function issueToken(User $user, Request $request, ?array $abilities = null): array
    {
        $deviceId = $request->header('X-Device-Id') ?: $request->input('device_id');
        $name = 'water-mobile';
        if (is_string($deviceId) && $deviceId !== '') {
            $name .= ':' . substr($deviceId, 0, 60);
            // ربط الجهاز بالحساب دون إلزام التطبيق بالتسجيل المسبق.
            Device::query()->firstOrCreate(
                ['device_uuid' => substr($deviceId, 0, 80)],
                ['user_id' => $user->id, 'platform' => $request->header('X-Client-Platform')],
            )->forceFill(['last_seen_at' => now()])->save();
        }

        $hours = (int) \App\Models\Setting::get('api_token_ttl_hours', 12);
        $expiresAt = $hours > 0 ? now()->addHours($hours) : null;

        $created = $user->createToken($name, $abilities ?? ['*'], $expiresAt);

        return [$created->plainTextToken, $expiresAt];
    }

    private function userPayload(?User $user): array
    {
        if (! $user) {
            return [];
        }

        $avatarUrl = $user->getFilamentAvatarUrl();
        $roles = $user->getRoleNames()->values();
        if ($roles->isEmpty() && ! empty($user->role)) {
            $roles = collect([$user->role])->values();
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $avatarUrl ? url($avatarUrl) : null,
            'roles' => $roles,
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
        ];
    }
}
