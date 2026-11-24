<?php

namespace App\Http\Controllers\Api;

use App\Models\Tariff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TariffController extends ApiController
{
    public function index(Request $request)
    {
        $this->authorizeApi(Tariff::class, 'viewAny');
        $validated = $request->validate(['trashed' => ['sometimes', 'in:without,with,only']]);
        $trashed = $validated['trashed'] ?? 'without';
        $query = match ($trashed) {
            'with' => Tariff::withTrashed(),
            'only' => Tariff::onlyTrashed(),
            default => Tariff::query(),
        };
        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }
        // يقبل البحث `q` أو `query` (يرسلها تطبيق الهاتف).
        $search = (string) ($request->query('q', $request->query('query', '')));
        if ($search !== '') {
            $query->where('name', 'like', "%{$search}%");
        }
        // الهيكل الجديد: المُصفّح في المستوى الأعلى (data/meta/links) ليقرأه
        // تطبيق الهاتف مباشرة عبر unwrapList، مع إبقاء active_price متاحاً.
        $query->orderByDesc('start_date')->orderByDesc('id');
        $this->applySorting($query, $request, self::LIST_SORTS['tariffs']);
        $paginator = $query->paginate($this->perPage($request))->appends($request->query());

        return response()->json(array_merge(
            $paginator->toArray(),
            ['active_price' => Tariff::getActivePrice()],
        ));
    }

    public function store(Request $request)
    {
        $this->authorizeApi(Tariff::class, 'create');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'is_active' => ['sometimes', 'boolean'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);
        $tariff = DB::transaction(function () use ($data) {
            // Active means approved for its dated interval, not today's sole tariff.
            return Tariff::create($data + ['is_active' => false]);
        });

        return response()->json(['message' => 'تم إنشاء شريحة التعرفة.', 'tariff' => $tariff], 201);
    }

    public function show(int $id)
    {
        $this->authorizeApi(Tariff::class, 'view');

        return response()->json(['tariff' => Tariff::withTrashed()->findOrFail($id), 'active_price' => Tariff::getActivePrice()]);
    }

    public function update(Request $request, int $id)
    {
        $this->authorizeApi(Tariff::class, 'update');
        $tariff = Tariff::findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'unit_price' => ['sometimes', 'required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'is_active' => ['sometimes', 'boolean'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date'],
        ]);
        DB::transaction(function () use ($tariff, $data) {
            $tariff->update($data);
        });

        return response()->json(['message' => 'تم تحديث التعرفة.', 'tariff' => $tariff->fresh(), 'active_price' => Tariff::getActivePrice()]);
    }

    public function destroy(int $id)
    {
        $this->authorizeApi(Tariff::class, 'delete');
        $tariff = Tariff::findOrFail($id);
        $tariff->delete();

        return response()->json(['message' => 'تم نقل التعرفة إلى المحذوفات.']);
    }

    public function restore(int $id)
    {
        $this->authorizeApi(Tariff::class, 'restore');
        $tariff = Tariff::onlyTrashed()->findOrFail($id);
        $tariff->restore();

        return response()->json(['message' => 'تمت استعادة التعرفة.', 'tariff' => $tariff]);
    }

    public function forceDestroy(int $id)
    {
        $this->authorizeApi(Tariff::class, 'forceDelete');
        Tariff::withTrashed()->findOrFail($id)->forceDelete();

        return response()->json(['message' => 'تم حذف التعرفة نهائياً.']);
    }

    /** استعادة جماعية لتعرفات محذوفة (فجوة v1 رقم 1). */
    public function bulkRestore(Request $request)
    {
        $this->authorizeApi(Tariff::class, 'restoreAny');
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'distinct']]);
        $restored = 0;
        DB::transaction(function () use ($data, &$restored) {
            foreach (Tariff::onlyTrashed()->whereIn('id', $data['ids'])->lockForUpdate()->get() as $tariff) {
                $tariff->restore();
                $restored++;
            }
        });

        return response()->json(['restored_count' => $restored]);
    }

    /** حذف نهائي جماعي لتعرفات (فجوة v1 رقم 1). */
    public function bulkForceDelete(Request $request)
    {
        $this->authorizeApi(Tariff::class, 'forceDeleteAny');
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'distinct']]);
        $deleted = 0;
        DB::transaction(function () use ($data, &$deleted) {
            foreach (Tariff::withTrashed()->whereIn('id', $data['ids'])->lockForUpdate()->get() as $tariff) {
                $tariff->forceDelete();
                $deleted++;
            }
        });

        return response()->json(['force_deleted_count' => $deleted]);
    }

    public function bulkDelete(Request $request)
    {
        $this->authorizeApi(Tariff::class, 'deleteAny');
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'distinct', 'exists:tariffs,id']]);
        $count = DB::transaction(function () use ($data) {
            $tariffs = Tariff::whereIn('id', $data['ids'])->orderBy('id')->lockForUpdate()->get();
            foreach ($tariffs as $tariff) {
                $tariff->delete();
            }

            return $tariffs->count();
        });

        return response()->json(['deleted_count' => $count]);
    }
}
