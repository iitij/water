<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Shared helpers for the mobile API controllers. */
abstract class ApiController extends Controller
{
    /**
     * Check the project’s Shield-style permission names as well as the older
     * snake_case names used by sync_permissions.php. Admin roles are explicit
     * API super-users; all other users must have the permission for the action.
     */
    protected function isAdminUser(object $user): bool
    {
        if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['super_admin', 'admin'])) {
            return true;
        }
        if (method_exists($user, 'roles') && $user->roles()->doesntExist()) {
            return in_array($user->role ?? null, ['super_admin', 'admin'], true);
        }
        return false;
    }

    protected function authorizeApi(string $resource, string $ability): void
    {
        abort_unless(request()->user(), 401, 'يلزم تسجيل الدخول.');
        abort_unless($this->allowsApi($resource, $ability), 403, 'ليست لديك صلاحية لتنفيذ هذا الإجراء.');
    }

    /**
     * نسخة غير مُجهضة من `authorizeApi` للشاشات المجمّعة (مثل البحث الشامل)
     * التي تبني نتائجها حسب ما يُسمح به للمستخدم دون إفشال الطلب كله.
     */
    protected function allowsApi(string $resource, string $ability): bool
    {
        $user = request()->user();
        if (! $user) {
            return false;
        }

        if ($this->isAdminUser($user)) {
            return true;
        }

        $resourceName = class_basename($resource);
        $legacy = ucfirst($ability) . ':' . $resourceName;
        $permission = Str::snake($ability) . '_' . Str::snake($resourceName);

        return $user->can($legacy)
            || $user->can($permission)
            || $this->hasBuiltInRoleAccess($user, $resourceName, $ability);
    }

    protected function authorizeSystem(string $permission = 'ViewAny:User'): void
    {
        $user = request()->user();
        abort_unless($user, 401, 'يلزم تسجيل الدخول.');

        if ($this->isAdminUser($user)) {
            return;
        }

        $legacy = Str::snake(str_replace(':', '_', $permission));
        abort_unless($user->can($permission) || $user->can($legacy), 403, 'ليست لديك صلاحية لإدارة النظام.');
    }

    protected function authorizeSystemWrite(string $resource): void
    {
        $user = request()->user();
        abort_unless($user, 401, 'يلزم تسجيل الدخول.');

        if ($this->isAdminUser($user)) return;

        $permission = 'Update:' . $resource;
        $legacy = Str::snake('update_' . $resource);
        abort_unless($user->can($permission) || $user->can($legacy), 403, 'ليست لديك صلاحية تعديل إعدادات النظام.');
    }

    private function hasBuiltInRoleAccess(object $user, string $resource, string $ability): bool
    {
        if (! method_exists($user, 'hasAnyRole')) return false;
        $access = [
            'reader' => [
                'Meter' => ['viewAny', 'view'], 'Reading' => ['viewAny', 'view', 'create', 'update'],
                'Subscriber' => ['viewAny', 'view'], 'Square' => ['viewAny', 'view'],
            ],
            'meter_reader' => [
                'Meter' => ['viewAny', 'view'], 'Reading' => ['viewAny', 'view', 'create', 'update'],
                'Subscriber' => ['viewAny', 'view'], 'Square' => ['viewAny', 'view'],
            ],
            'collector' => [
                'Invoice' => ['viewAny', 'view'],
                // `void`: المحصّل يطلب الإلغاء بسبب مكتوب؛ المبالغ الكبيرة تنتظر
                // اعتماد مشرف داخل PaymentService::requestVoid.
                'Payment' => ['viewAny', 'view', 'create', 'update', 'void'],
                'Reading' => ['viewAny', 'view'], 'Meter' => ['viewAny', 'view'],
                'Subscriber' => ['viewAny', 'view'], 'Square' => ['viewAny', 'view'],
            ],
        ];
        $hasSpatieRoles = method_exists($user, 'roles') && $user->roles()->exists();
        foreach (array_keys($access) as $role) {
            $matches = $hasSpatieRoles ? $user->hasRole($role) : (($user->role ?? null) === $role);
            if ($matches && in_array($ability, $access[$role][$resource] ?? [], true)) return true;
        }
        return false;
    }

    protected function perPage(Request $request, int $default = 25, int $max = 100): int
    {
        return max(1, min($max, (int) $request->query('per_page', $default)));
    }

    protected function normalizeDigits(string $value): string
    {
        return strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }

    /** @return array{0:string,1:string}|null */
    protected function parseCompoundSearch(string $value): ?array
    {
        $value = $this->normalizeDigits(trim($value));
        return preg_match('/^(\d+)[\/\-\\\\_](\d+)$/', $value, $matches)
            ? [$matches[1], $matches[2]]
            : null;
    }
}
