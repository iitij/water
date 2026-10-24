<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * صلاحيات الإصدار 2.0 الخاصة بالصندوق وسجل التدقيق.
 *
 * تُنشأ هنا بدل الاعتماد على توليد Filament Shield، لأن أسماء هذه الصلاحيات
 * مذكورة صراحةً في `CashSessionController` و`AuditEventResource`، وتغييرها
 * يعني كسر الحماية بصمت.
 */
class CashSessionPermissionsSeeder extends Seeder
{
    /** @var list<string> */
    public const PERMISSIONS = [
        'cash_sessions.manage',
        'cash_sessions.approve',
        'audit.view',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // المشرف الأعلى يملك كل الصلاحيات؛ صلاحية الاعتماد تُمنح أيضاً لدور
        // `admin` إن كان موجوداً لأنه الدور الإداري الشائع في هذا المشروع.
        foreach (['super_admin', 'admin'] as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role !== null) {
                $role->givePermissionTo(self::PERMISSIONS);
            }
        }

        // المحصّل يحتاج فتح/إغلاق الصندوق فقط، لا الاعتماد ولا سجل التدقيق.
        $collector = Role::where('name', 'collector')->where('guard_name', 'web')->first();
        if ($collector !== null) {
            $collector->givePermissionTo('cash_sessions.manage');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
