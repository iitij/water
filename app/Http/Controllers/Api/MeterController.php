<?php

namespace App\Http\Controllers\Api;

use App\Models\Meter;
use App\Models\Subscriber;
use App\Services\SingleMeterService;
use App\Services\MeterDataPurger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MeterController extends ApiController
{
    public function index(Request $request)
    {
        $this->authorizeApi(Meter::class, 'viewAny');
        $query = Meter::with(['subscriber:id,name,phone,square_id', 'subscriber.square:id,name,code'])
            ->withCount('readings')->orderBy('id');
        if ($request->filled('subscriber_id')) {
            $query->where('subscriber_id', $request->integer('subscriber_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('square_id')) {
            $query->whereHas('subscriber', fn ($q) => $q->where('square_id', $request->integer('square_id')));
        }
        if ($search = trim((string) $request->query('q', $request->query('search', '')))) {
            $normalized = $this->normalizeDigits($search);
            if ($path = $this->parseCompoundSearch($normalized)) {
                [$squareId, $pathNumber] = $path;
                $query->where('path_number', $pathNumber)
                    ->whereHas('subscriber', fn ($q) => $q->where('square_id', $squareId));
            } else {
                $query->where(function ($builder) use ($search, $normalized) {
                    $builder->where('unique_code', 'like', "%{$search}%")
                        ->orWhereHas('subscriber.square', fn ($square) => $square->where('code', 'like', "%{$search}%"))

                        ->orWhereHas('subscriber', fn ($q) => $q->where('name', 'like', "%{$search}%"));
                    if (ctype_digit($normalized)) {
                        $builder->orWhere('path_number', (int) $normalized);
                    }
                });
            }
        }

        $this->applySorting($query, $request, self::LIST_SORTS['meters']);

        return $query->paginate($this->perPage($request))->appends($request->query());
    }

    public function search(Request $request)
    {
        $this->authorizeApi(Meter::class, 'viewAny');
        $request->query->set('per_page', $request->query('limit', $request->query('per_page', 50)));
        if ($request->query->has('query') && ! $request->query->has('q')) {
            $request->query->set('q', $request->query('query'));
        }
        $results = $this->index($request);
        $search = $this->normalizeDigits(trim((string) $request->query('query', $request->query('q', ''))));
        $exactPath = $this->parseCompoundSearch($search);
        $results->getCollection()->transform(function (Meter $meter) use ($search, $exactPath) {
            $meter->setAttribute('is_path_match', $exactPath !== null
                ? ((string) $meter->path_number === $exactPath[1] && (string) $meter->subscriber?->square_id === $exactPath[0])
                : ($search !== '' && (string) $meter->path_number === $search));
            $meter->setAttribute('last_reading', $meter->readings()->latest('id')->value('current_reading') ?? $meter->initial_reading ?? 0);

            return $meter;
        });
        $results->setCollection($results->getCollection()->sortByDesc('is_path_match')->values());

        return $results;
    }

    public function show(int $id)
    {
        $this->authorizeApi(Meter::class, 'view');
        $meter = Meter::with(['subscriber.square', 'readings' => fn ($q) => $q->latest('reading_date')->latest('id')->limit(20)])
            ->withCount('readings')->findOrFail($id);
        $meter->setAttribute('last_reading', $meter->readings->first()?->current_reading ?? $meter->initial_reading ?? 0);
        $meter->setAttribute('meter_credit', round(max(0, (float) $meter->balance), 2));
        $meter->setAttribute('meter_debit', round($meter->getOutstandingBalance(), 2));

        return response()->json(['meter' => $meter]);
    }

    public function store(Request $request, SingleMeterService $singleMeterService)
    {
        $this->authorizeApi(Meter::class, 'create');
        $data = $request->validate([
            'unique_code' => ['required', 'string', 'max:50', 'unique:meters,unique_code'],
            'path_number' => ['nullable', 'integer', 'min:0'],
            'subscriber_id' => ['required', 'integer', 'exists:subscribers,id'],
            'status' => ['sometimes', 'in:active,inactive'],
            'installed_at' => ['nullable', 'date'],
            'removed_at' => ['prohibited'],
            'initial_reading' => ['sometimes', 'integer', 'min:0'],
        ]);
        $meter = $singleMeterService->create($data)->load(['subscriber.square']);

        return response()->json(['message' => 'تم إضافة العداد بنجاح.', 'meter' => $meter], 201);
    }

    public function update(Request $request, int $id, SingleMeterService $singleMeterService)
    {
        $this->authorizeApi(Meter::class, 'update');
        $meter = Meter::findOrFail($id);
        $data = $request->validate([
            'unique_code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('meters', 'unique_code')->ignore($meter->id)],
            'path_number' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'subscriber_id' => ['sometimes', 'required', 'integer', 'exists:subscribers,id'],
            'status' => ['sometimes', 'required', 'in:active,inactive'],
            'installed_at' => ['sometimes', 'nullable', 'date'],
            'removed_at' => ['prohibited'],
            'initial_reading' => ['sometimes', 'integer', 'min:0'],
        ]);
        $meter = $singleMeterService->update($meter, $data);

        return response()->json(['message' => 'تم تحديث العداد بنجاح.', 'meter' => $meter->fresh(['subscriber.square'])]);
    }

    /**
     * Atomically purge the former meter and its linked records, then assign
     * a fresh physical meter without deleting financial documents.
     */
    public function replace(Request $request, int $id, SingleMeterService $singleMeterService)
    {
        $this->authorizeApi(Meter::class, 'update');
        $this->authorizeApi(Meter::class, 'create');

        $data = $request->validate([
            'removed_at' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'new_meter' => ['required', 'array'],
            'new_meter.unique_code' => ['required', 'string', 'max:50', 'unique:meters,unique_code'],
            'new_meter.path_number' => ['nullable', 'integer', 'min:0'],
            'new_meter.initial_reading' => ['sometimes', 'integer', 'min:0'],
            'new_meter.installed_at' => ['nullable', 'date'],
            'new_meter.status' => ['sometimes', 'in:active,inactive'],
        ]);

        $old = Meter::findOrFail($id);
        if ($old->installed_at !== null
            && \Carbon\Carbon::parse($data['removed_at'])->startOfDay()->lt($old->installed_at->startOfDay())) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'removed_at' => ['تاريخ النزع لا يمكن أن يكون قبل تركيب العداد القديم.'],
            ]);
        }

        $result = $singleMeterService->replace(
            $old,
            $data['new_meter'],
            $data['removed_at'],
            $data['reason'],
            $request->user()?->id,
        );

        return response()->json([
            'message' => 'تم استبدال العداد مع حماية المستندات المالية المرتبطة.',
            ...$result,
        ], 201);
    }

    public function destroy(int $id, MeterDataPurger $purger)
    {
        $this->authorizeApi(Meter::class, 'delete');

        return DB::transaction(function () use ($id, $purger) {
            $meter = Meter::query()->lockForUpdate()->findOrFail($id);
            if ($this->hasHistory($meter)) {
                return response()->json(['message' => 'لا يمكن حذف عداد له قراءات أو فواتير أو سندات محفوظة.'], 409);
            }
            // ForceDelete alone nullifies the meter IDs in fault/route rows;
            // purge them before the record so no hidden meter history remains.
            $purger->purge((int) $meter->id);

            return response()->json(['message' => 'تم حذف العداد نهائياً.']);
        });
    }

    public function bulkDelete(Request $request, MeterDataPurger $purger)
    {
        $this->authorizeApi(Meter::class, 'deleteAny');
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'distinct', 'exists:meters,id']]);
        $deleted = 0;
        $skipped = [];
        DB::transaction(function () use ($data, &$deleted, &$skipped, $purger) {
            foreach (Meter::whereIn('id', $data['ids'])->lockForUpdate()->get() as $meter) {
                if ($this->hasHistory($meter)) {
                    $skipped[] = $meter->id;

                    continue;
                }
                $purger->purge((int) $meter->id);
                $deleted++;
            }
        });

        return response()->json(['deleted_count' => $deleted, 'skipped_count' => count($skipped), 'skipped_ids' => $skipped]);
    }

    public function validatePath(Request $request)
    {
        $this->authorizeApi(Meter::class, 'viewAny');
        $data = $request->validate([
            'path_number' => ['required', 'integer', 'min:0'],
            'subscriber_id' => ['nullable', 'integer', 'exists:subscribers,id'],
            'square_id' => ['nullable', 'integer', 'exists:squares,id'],
            'meter_id' => ['nullable', 'integer', 'exists:meters,id'],
        ]);
        if (empty($data['subscriber_id']) && empty($data['square_id'])) {
            return response()->json(['message' => 'أرسل subscriber_id أو square_id للتحقق من المسار.'], 422);
        }
        $squareId = $data['square_id'] ?? Subscriber::findOrFail($data['subscriber_id'])->square_id;
        $available = $this->pathAvailable($squareId, (int) $data['path_number'], $data['meter_id'] ?? null);

        return response()->json([
            'valid' => $available,
            'available' => $available,
            'square_id' => $squareId,
            'path_number' => (int) $data['path_number'],
            'message' => $available ? 'المسار متاح.' : 'رقم المسار مستخدم بالفعل في هذا المربع السكني.',
        ]);
    }

    private function pathAvailable(int $squareId, ?int $pathNumber, ?int $ignoreMeterId = null): bool
    {
        if ($pathNumber === null) {
            return true;
        }

        return ! Meter::where('path_number', $pathNumber)
            ->whereNull('removed_at')
            ->when($ignoreMeterId, fn ($q) => $q->where('id', '!=', $ignoreMeterId))
            ->whereHas('subscriber', fn ($q) => $q->where('square_id', $squareId))
            ->exists();
    }

    private function hasHistory(Meter $meter): bool
    {
        return $meter->readings()->withTrashed()->exists()
            || $meter->payments()->withTrashed()->exists()
            || $meter->invoices()->withTrashed()->exists()
            || $meter->transactions()->withTrashed()->exists();
    }
}
