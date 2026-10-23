<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserController extends ApiController
{
    public function index(Request $request)
    {
        $this->authorizeApi(User::class, 'viewAny');
        $query = User::with(['roles:id,name'])->orderBy('name');
        if ($request->filled('role')) $query->role($request->query('role'));
        if ($search = trim((string) $request->query('q', $request->query('search', '')))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }
        return $query->paginate($this->perPage($request))->through(fn (User $user) => $this->userPayload($user))
            ->appends($request->query());
    }

    public function store(Request $request)
    {
        $this->authorizeApi(User::class, 'create');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'distinct', 'exists:roles,name'],
        ]);
        $user = DB::transaction(function () use ($data) {
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password'])]);
            $user->syncRoles($data['roles']);
            return $user;
        });
        return response()->json(['message' => 'تم إنشاء المستخدم.', 'user' => $this->userPayload($user->load('roles'))], 201);
    }

    public function show(int $id)
    {
        $this->authorizeApi(User::class, 'view');
        return response()->json(['user' => $this->userPayload(User::with('roles')->findOrFail($id))]);
    }

    public function update(Request $request, int $id)
    {
        $this->authorizeApi(User::class, 'update');
        $user = User::findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'roles' => ['sometimes', 'required', 'array', 'min:1'],
            'roles.*' => ['string', 'distinct', 'exists:roles,name'],
        ]);
        DB::transaction(function () use ($user, $data) {
            $attributes = collect($data)->only(['name', 'email'])->all();
            if (! empty($data['password'])) $attributes['password'] = Hash::make($data['password']);
            $user->update($attributes);
            if (array_key_exists('roles', $data)) $user->syncRoles($data['roles']);
        });
        return response()->json(['message' => 'تم تحديث المستخدم.', 'user' => $this->userPayload($user->fresh('roles'))]);
    }

    public function destroy(int $id)
    {
        $this->authorizeApi(User::class, 'delete');
        $user = User::findOrFail($id);
        if ((int) $user->id === (int) request()->user()->id) {
            return response()->json(['message' => 'لا يمكن حذف المستخدم الحالي.'], 409);
        }
        if ($user->hasRole('super_admin')) {
            return response()->json(['message' => 'لا يمكن حذف حساب مدير النظام الأعلى عبر واجهة الموبايل.'], 409);
        }
        $user->delete();
        return response()->json(['message' => 'تم حذف المستخدم.']);
    }

    public function bulkDelete(Request $request)
    {
        $this->authorizeApi(User::class, 'deleteAny');
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'distinct', 'exists:users,id']]);
        $deleted = 0;
        $skipped = [];
        DB::transaction(function () use ($data, &$deleted, &$skipped, $request) {
            foreach (User::whereIn('id', $data['ids'])->lockForUpdate()->get() as $user) {
                if ((int) $user->id === (int) $request->user()->id || $user->hasRole('super_admin')) {
                    $skipped[] = $user->id;
                    continue;
                }
                $user->delete();
                $deleted++;
            }
        });
        return response()->json(['deleted_count' => $deleted, 'skipped_count' => count($skipped), 'skipped_ids' => $skipped]);
    }

    private function userPayload(User $user): array
    {
        $user->loadMissing('roles');
        $roles = $user->getRoleNames()->values();
        if ($roles->isEmpty() && ! empty($user->role)) {
            $roles = collect([$user->role])->values();
        }
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $user->getFilamentAvatarUrl(),
            'roles' => $roles,
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
            'created_at' => $user->created_at?->toISOString(),
        ];
    }
}
