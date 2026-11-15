<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Shared helpers for the mobile API controllers. */
abstract class ApiController extends Controller
{
    /** Trusted field mappings only; request values are never SQL identifiers. */
    protected const LIST_SORTS = [
        'subscribers' => ['id', 'name', 'subscription_start_date', 'balance', 'status', 'created_at', 'square_name' => 'square.name', 'meters_count' => '@meters_count'],
        'meters' => ['id', 'unique_code', 'path_number', 'status', 'created_at', 'subscriber_name' => 'subscriber.name', 'square_name' => 'subscriber.square.name'],
        'readings' => ['id', 'reading_date', 'actual_reading_date', 'previous_reading', 'current_reading', 'consumption_units', 'created_at', 'meter_code' => 'meter.unique_code', 'subscriber_name' => 'meter.subscriber.name'],
        'invoices' => ['path_number' => 'meter.path_number', 'square_name' => 'subscriber.square.name', 'id', 'bill_number', 'bill_date', 'total_amount', 'amount_paid', 'status', 'created_at', 'beneficiary_name' => 'subscriber.name', 'subscriber_name' => 'subscriber.name', 'total_due' => ['expression' => 'COALESCE(invoices.total_amount, 0) + COALESCE(invoices.previous_balance, 0)']],
        'payments' => ['path_number' => 'meter.path_number', 'square_name' => 'subscriber.square.name', 'id', 'receipt_number', 'payment_date', 'amount', 'created_at', 'subscriber_name' => 'subscriber.name', 'user_name' => 'user.name'],
        'void_requests' => ['created_at', 'path_number' => 'meter.path_number', 'square_name' => 'subscriber.square.name', 'id', 'receipt_number', 'amount', 'void_requested_at', 'subscriber_name' => 'subscriber.name'],
        'subscription_fees' => ['path_number' => 'meter.path_number', 'square_name' => 'subscriber.square.name', 'id', 'amount', 'status', 'created_at', 'subscriber_name' => 'subscriber.name', 'meter_code' => 'meter.unique_code', 'invoice_number' => 'invoice.bill_number'],
        'squares' => ['id', 'name', 'code', 'created_at', 'subscribers_count' => '@subscribers_count'],
        'tariffs' => ['id', 'name', 'unit_price', 'start_date', 'end_date', 'created_at', 'updated_at', 'deleted_at'],
        'transactions' => ['path_number' => 'meter.path_number', 'square_name' => 'subscriber.square.name', 'id', 'created_at', 'amount', 'type', 'subscriber_name' => 'subscriber.name'],
        'users' => ['id', 'name', 'email', 'email_verified_at', 'created_at', 'updated_at'],
        'roles' => ['id', 'name', 'guard_name', 'created_at', 'updated_at', 'permissions_count' => '@permissions_count'],
        'cash_sessions' => ['created_at', 'id', 'session_date', 'expected_amount', 'counted_amount', 'variance_amount', 'closed_at', 'collector_name' => 'user.name'],
        'audit_events' => ['id', 'event', 'source', 'created_at', 'occurred_at' => 'created_at', 'actor_name' => 'actor.name'],
        'settings' => ['id', 'key', 'label', 'type', 'created_at', 'updated_at'],
    ];

    /** Sort the complete authorized query before pagination, with a stable key. */
    protected function applySorting(Builder $query, Request $request, array $fields): Builder
    {
        $this->applyListFilters($query, $request);
        $allowed = [];
        foreach ($fields as $key => $field) {
            $allowed[is_int($key) ? $field : $key] = $field;
        }
        $input = $request->validate([
            'sort_by' => ['nullable', 'string', Rule::in(array_keys($allowed))],
            'sort_direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $primaryKey = $query->getModel()->getQualifiedKeyName();
        if (! empty($input['sort_by'])) {
            $field = $allowed[$input['sort_by']];
            $direction = $input['sort_direction'] ?? 'asc';
            $query->reorder();
            if (is_array($field)) {
                $query->orderByRaw($field['expression'].' '.$direction);
            } elseif (str_starts_with($field, '@')) {
                $query->orderBy(substr($field, 1), $direction);
            } elseif (str_contains($field, '.')) {
                [$relation, $column] = [substr($field, 0, strrpos($field, '.')), substr($field, strrpos($field, '.') + 1)];
                $query->orderBy($this->relatedSortQuery($query->getModel(), $relation, $column), $direction);
            } else {
                $query->orderBy($query->getModel()->qualifyColumn($field), $direction);
            }
            if ($field !== $query->getModel()->getKeyName()) {
                $query->orderBy($primaryKey, $direction);
            }
        } else {
            // Keep every existing default order and make equal values stable.
            $orders = $query->getQuery()->orders ?? [];
            $hasPrimaryKey = collect($orders)->contains(fn ($order) => in_array($order['column'] ?? null, [$primaryKey, $query->getModel()->getKeyName()], true));
            if (! $hasPrimaryKey) {
                $query->orderBy($primaryKey, end($orders)['direction'] ?? 'asc');
            }
        }

        return $query;
    }

    /** Optional facets always narrow the already authorized list query. */
    protected function applyListFilters(Builder $query, Request $request): void
    {
        $input = $request->validate([
            'added_from' => ['nullable', 'date_format:Y-m-d'],
            'added_to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('added_from'), ['after_or_equal:added_from'])],
            'path_number' => ['nullable', 'integer', 'min:0'],
            'square_code' => ['nullable', 'string', 'max:100'],
        ], [
            'added_to.after_or_equal' => 'تاريخ البداية يجب أن يسبق تاريخ النهاية.',
            'path_number.integer' => 'أدخل رقم مسار صحيحاً.',
        ]);
        $model = $query->getModel();
        if ($model->usesTimestamps()) {
            if (! empty($input['added_from'])) {
                $query->where($model->qualifyColumn($model->getCreatedAtColumn()), '>=', $input['added_from'].' 00:00:00');
            }
            if (! empty($input['added_to'])) {
                $query->where($model->qualifyColumn($model->getCreatedAtColumn()), '<', Carbon::parse($input['added_to'])->addDay()->format('Y-m-d').' 00:00:00');
            }
        }
        $table = $model->getTable();
        $squareRelations = [
            'subscribers' => 'square', 'meters' => 'subscriber.square',
            'readings' => 'meter.subscriber.square', 'invoices' => 'subscriber.square',
            'payments' => 'subscriber.square', 'subscription_fees' => 'subscriber.square',
            'transactions' => 'subscriber.square',
            'cash_sessions' => 'square',
        ];
        if (isset($input['square_code']) && trim($input['square_code']) !== '') {
            $code = trim($input['square_code']);
            if ($table === 'squares') {
                $query->where('code', $code);
            } elseif (isset($squareRelations[$table])) {
                $query->whereHas($squareRelations[$table], fn ($square) => $square->where('code', $code));
            }
        }
        $paths = ['subscribers' => 'meters', 'readings' => 'meter', 'invoices' => 'meter',
            'payments' => 'meter', 'subscription_fees' => 'meter', 'transactions' => 'meter',
        if (isset($input['path_number'])) {
            if ($table === 'meters') {
                $query->where('path_number', $input['path_number']);
            } elseif (in_array($table, ['invoices', 'payments', 'subscription_fees', 'transactions'], true)) {
                $path = $input['path_number'];
                $query->where(function ($builder) use ($path) {
                    $builder->whereHas('meter', fn ($meter) => $meter->where('path_number', $path))
                        ->orWhere(fn ($general) => $general->whereNull('meter_id')
                            ->whereHas('subscriber.meters', fn ($meter) => $meter->where('path_number', $path)));
                });
            } elseif (isset($paths[$table])) {
                $query->whereHas($paths[$table], fn ($related) => $related->where('path_number', $input['path_number']));
            }
        }
    }

    /** Correlated belongs-to subqueries avoid joins changing rows or scopes. */
    private function relatedSortQuery(Model $model, string $path, string $column): Builder
    {
        [$head, $tail] = array_pad(explode('.', $path, 2), 2, null);
        $relation = Relation::noConstraints(fn () => $model->{$head}());
        if (! $relation instanceof BelongsTo) {
            throw new \LogicException('Only belongs-to relations can be used in list sorting.');
        }
        $related = $relation->getRelated();
        $sort = $related->newQuery();
        if ($tail !== null) {
            $sort->selectSub($this->relatedSortQuery($related, $tail, $column), 'list_sort_value');
        } else {
            $sort->select($related->qualifyColumn($column));
        }

        return $sort->whereColumn($relation->getQualifiedOwnerKeyName(), $relation->getQualifiedForeignKeyName())->limit(1);
    }

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
        $legacy = ucfirst($ability).':'.$resourceName;
        $permission = Str::snake($ability).'_'.Str::snake($resourceName);

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

        [$ability, $resource] = array_pad(explode(':', $permission, 2), 2, '');
        $legacy = Str::snake($ability).($resource === '' ? '' : '_'.Str::snake($resource));
        $previousLegacy = Str::snake(str_replace(':', '_', $permission));
        abort_unless($user->can($permission) || $user->can($legacy) || $user->can($previousLegacy), 403, 'ليست لديك صلاحية لإدارة النظام.');
    }

    protected function authorizeSystemWrite(string $resource): void
    {
        $user = request()->user();
        abort_unless($user, 401, 'يلزم تسجيل الدخول.');

        abort_unless($this->allowsSystemWrite($resource), 403, 'ليست لديك صلاحية تعديل إعدادات النظام.');
    }

    protected function allowsSystemWrite(string $resource): bool
    {
        $user = request()->user();
        if (! $user) {
            return false;
        }

        return $this->isAdminUser($user)
            || $user->can('Update:'.$resource)
            || $user->can('update_'.Str::snake($resource))
            // Preserve previously issued aliases while accepting the normal
            // snake_case names used by the permission catalog.
            || $user->can(Str::snake('update_'.$resource));
    }

    private function hasBuiltInRoleAccess(object $user, string $resource, string $ability): bool
    {
        if (! method_exists($user, 'hasAnyRole')) {
            return false;
        }
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
            if ($matches && in_array($ability, $access[$role][$resource] ?? [], true)) {
                return true;
            }
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
