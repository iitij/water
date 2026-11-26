<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        $this->applySorting($query, $request, self::LIST_SORTS['users']);
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
            'avatar' => ['nullable', 'image', 'max:5120'],
        ]);
        $avatarPath = $request->hasFile('avatar')
            ? $request->file('avatar')->store('avatars', 'public')
            : null;
        $user = DB::transaction(function () use ($data, $avatarPath) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'avatar' => $avatarPath,
            ]);
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
        abort_if($user->isDisabled(), 409, 'الحساب معطل ولا يمكن تعديله.');
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'roles' => ['sometimes', 'required', 'array', 'min:1'],
            'roles.*' => ['string', 'distinct', 'exists:roles,name'],
            'avatar' => ['nullable', 'image', 'max:5120'],
            'remove_avatar' => ['sometimes', 'boolean'],
        ]);
        $avatarPath = null;
        if ($request->hasFile('avatar')) {
            if ($user->avatar) {
                Storage::disk('public')->delete($user->avatar);
            }
            $avatarPath = $request->file('avatar')->store('avatars', 'public');
        } elseif ($request->boolean('remove_avatar') && $user->avatar) {
            Storage::disk('public')->delete($user->avatar);
        }
        DB::transaction(function () use ($user, $data, $request, $avatarPath) {
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_if($user->isDisabled(), 409, 'الحساب معطل ولا يمكن تعديله.');
            $attributes = collect($data)->only(['name', 'email'])->all();
            if (! empty($data['password'])) $attributes['password'] = Hash::make($data['password']);
            if ($avatarPath !== null) {
                $attributes['avatar'] = $avatarPath;
            } elseif ($request->boolean('remove_avatar')) {
                $attributes['avatar'] = null;
            }
            $user->update($attributes);
            if (array_key_exists('roles', $data)) $user->syncRoles($data['roles']);
        });
        return response()->json(['message' => 'تم تحديث المستخدم.', 'user' => $this->userPayload($user->fresh('roles'))]);
    }

    public function destroy(int $id)
    {
        $this->authorizeApi(User::class, 'delete');
        $user = User::findOrFail($id);
        if (! $user->deactivate(request()->user())) {
            return response()->json(['message' => 'لا يمكن تعطيل هذا الحساب أو أنه معطل بالفعل.'], 409);
        }

        return response()->json(['message' => 'تم تعطيل المستخدم مع حفظ سجلاته.']);
    }

    public function bulkDelete(Request $request)
    {
        $this->authorizeApi(User::class, 'deleteAny');
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'distinct', 'exists:users,id']]);
        $disabled = 0;
        $skipped = [];
        DB::transaction(function () use ($data, &$disabled, &$skipped, $request) {
            $actor = $request->user();
            $locked = User::lockAccounts([...$data['ids'], $actor->id]);
            foreach ($locked->only($data['ids']) as $user) {
                if (! $user->deactivate($actor, bulk: true)) {
                    $skipped[] = $user->id;
                    continue;
                }
                $disabled++;
            }
        });

        // Keep the legacy count for existing Flutter clients; rows are never deleted.
        return response()->json(['deleted_count' => $disabled, 'deactivated_count' => $disabled, 'skipped_count' => count($skipped), 'skipped_ids' => $skipped]);
    }

    private function userPayload(User $user): array
    {
        $user->loadMissing('roles');
        $roles = $user->getRoleNames()->values();
        if ($roles->isEmpty() && ! empty($user->role)) {
            $roles = collect([$user->role])->values();
        }
        $avatarUrl = $user->getFilamentAvatarUrl();
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $avatarUrl ? url($avatarUrl) : null,
            'roles' => $roles,
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
            'created_at' => $user->created_at?->toISOString(),
            'disabled_at' => $user->disabled_at?->toISOString(),
        ];
    }
}
