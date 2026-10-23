<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends ApiController
{
    public function index(Request $request)
    {
        $this->authorizeApi(Role::class, 'viewAny');
        $query = Role::query()->withCount('permissions')->with('permissions:id,name')->orderBy('name');
        if ($request->filled('q')) $query->where('name', 'like', '%' . $request->query('q') . '%');
        return $query->paginate($this->perPage($request))->appends($request->query());
    }

    public function store(Request $request)
    {
        $this->authorizeApi(Role::class, 'create');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->where('guard_name', $request->input('guard_name', 'web'))],
            'guard_name' => ['sometimes', 'string', 'max:255'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['required'],
        ]);
        $role = DB::transaction(function () use ($data) {
            $role = Role::create(['name' => $data['name'], 'guard_name' => $data['guard_name'] ?? 'web']);
            if (array_key_exists('permissions', $data)) $role->syncPermissions($this->resolvePermissions($data['permissions']));
            return $role;
        });
        return response()->json(['message' => 'تم إنشاء الدور.', 'role' => $role->load('permissions')->loadCount('permissions')], 201);
    }

    public function show(int $id)
    {
        $this->authorizeApi(Role::class, 'view');
        return response()->json(['role' => Role::with('permissions')->withCount('permissions')->findOrFail($id)]);
    }

    public function update(Request $request, int $id)
    {
        $this->authorizeApi(Role::class, 'update');
        $role = Role::findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($role->id)->where('guard_name', $role->guard_name)],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['required'],
        ]);
        DB::transaction(function () use ($role, $data) {
            if (array_key_exists('name', $data)) $role->name = $data['name'];
            $role->save();
            if (array_key_exists('permissions', $data)) $role->syncPermissions($this->resolvePermissions($data['permissions']));
        });
        return response()->json(['message' => 'تم تحديث الدور وصلاحياته.', 'role' => $role->fresh()->load('permissions')->loadCount('permissions')]);
    }

    public function destroy(int $id)
    {
        $this->authorizeApi(Role::class, 'delete');
        $role = Role::findOrFail($id);
        if (in_array($role->name, ['super_admin', 'admin'], true)) {
            return response()->json(['message' => 'لا يمكن حذف الدور الأساسي.'], 409);
        }
        $role->delete();
        return response()->json(['message' => 'تم حذف الدور.']);
    }

    public function bulkDelete(Request $request)
    {
        $this->authorizeApi(Role::class, 'deleteAny');
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'distinct', 'exists:roles,id']]);
        $deleted = 0;
        $skipped = [];
        foreach (Role::whereIn('id', $data['ids'])->get() as $role) {
            if (in_array($role->name, ['super_admin', 'admin'], true)) {
                $skipped[] = $role->id;
                continue;
            }
            $role->delete();
            $deleted++;
        }
        return response()->json(['deleted_count' => $deleted, 'skipped_count' => count($skipped), 'skipped_ids' => $skipped]);
    }

    public function permissionsMatrix()
    {
        $this->authorizeApi(Role::class, 'viewAny');
        $permissions = Permission::query()->orderBy('name')->get(['id', 'name', 'guard_name']);
        $groups = $permissions->groupBy(function (Permission $permission) {
            $name = $permission->name;
            if (str_contains($name, ':')) return explode(':', $name, 2)[1];
            $parts = explode('_', $name);
            return count($parts) > 1 ? end($parts) : 'general';
        })->map(fn ($items, $key) => [
            'key' => (string) $key,
            'permissions' => $items->values(),
        ])->values();

        return response()->json([
            'groups' => $groups,
            'permissions' => $permissions,
            'roles' => Role::with('permissions:id,name')->withCount('permissions')->orderBy('name')->get(),
        ]);
    }

    private function resolvePermissions(array $values): array
    {
        $resolved = [];
        foreach ($values as $value) {
            $permission = is_numeric($value)
                ? Permission::find((int) $value)
                : Permission::where('name', (string) $value)->where('guard_name', 'web')->first();
            if (! $permission) {
                abort(422, 'تتضمن الصلاحيات قيمة غير معروفة: ' . (string) $value);
            }
            $resolved[] = $permission;
        }
        return $resolved;
    }
}
