<?php

namespace App\Http\Controllers\Api;

use App\Models\Meter;
use App\Models\Reading;
use App\Models\ReadingFault;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * أعطال القراءة الميدانية — الخطة 2.0: ربط بيانات الميدان كاملة بالخادم.
 * سابقاً كانت تُحفظ محلياً على الجهاز فقط وتموت بفقدان الجهاز.
 */
class ReadingFaultController extends ApiController
{
    /** أنواع الأعطال: أكواد تطبيق الهاتف الأصلية + أسماء الخطة الموسّعة. */
    public const FAULT_TYPES = [
        // أكواد التطبيق (ReadingFaultReason.code)
        'meter_broken',
        'meter_locked',
        'subscriber_absent',
        'other',
        // أسماء موسّعة للأجهزة الأحدث
        'no_access',
        'blocked',
        'closed_house',
        'meter_damaged',
        'no_water',
    ];

    /** قائمة الأعطال للمشرف (شاشة القراءات/جودة البيانات). */
    public function index(Request $request)
    {
        $this->authorizeApi(Reading::class, 'viewAny');

        $query = ReadingFault::query()
            ->with([
                'meter:id,unique_code,path_number,subscriber_id',
                'subscriber:id,name,phone',
                'square:id,name,code',
                'user:id,name',
            ])
            ->latest('reading_date')->latest('id');

        if ($request->filled('from_date')) {
            $query->whereDate('reading_date', '>=', $request->query('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->whereDate('reading_date', '<=', $request->query('to_date'));
        }
        if ($request->filled('square_id')) {
            $query->where('square_id', $request->integer('square_id'));
        }
        if ($request->filled('meter_id')) {
            $query->where('meter_id', $request->integer('meter_id'));
        }
        if ($request->filled('fault_type')) {
            $query->where('fault_type', $request->query('fault_type'));
        }

        return $query->paginate($this->perPage($request))->appends($request->query());
    }

    /** تسجيل عطل واحد (يُستخدم أيضاً للاستجابة الفورية من الجهاز). */
    public function store(Request $request)
    {
        $this->authorizeApi(Reading::class, 'create');

        $data = $this->validatePayload($request);

        // Idempotency: إعادة الإرسال بنفس sync_uuid تعيد العطل الموجود دون تكرار.
        $fault = DB::transaction(fn () => $this->storeOrVerify($data, $request), 3);

        return response()->json([
            'message' => 'تم تسجيل عطل القراءة.',
            'fault' => $fault->load(['meter:id,unique_code,path_number', 'square:id,name,code']),
            'duplicate' => ! $fault->wasRecentlyCreated,
        ], 201);
    }

    /** رفع دفعة أعطال من الجهاز (مزامنة الدفعات مع مفتاح Idempotency-Key). */
    public function syncBatch(Request $request)
    {
        $this->authorizeApi(Reading::class, 'create');

        $data = $request->validate([
            'faults' => ['required', 'array', 'max:200'],
            'faults.*.meter_id' => ['nullable', 'integer', 'exists:meters,id'],
            'faults.*.reading_date' => ['required', 'date'],
            'faults.*.fault_type' => ['required', 'string', Rule::in(self::FAULT_TYPES)],
            'faults.*.notes' => ['nullable', 'string', 'max:2000'],
            'faults.*.recorded_at' => ['nullable', 'date'],
            'faults.*.sync_uuid' => ['required', 'uuid'],
        ]);

        [$synced, $duplicated] = DB::transaction(function () use ($data, $request): array {
            $synced = 0;
            $duplicated = 0;
            // Stable UUID order for concurrent overlapping batches.
            $items = $data['faults'];
            usort($items, fn (array $a, array $b) => strcmp($a['sync_uuid'], $b['sync_uuid']));
            foreach ($items as $item) {
                $fault = $this->storeOrVerify($item, $request);
                $fault->wasRecentlyCreated ? $synced++ : $duplicated++;
            }
            return [$synced, $duplicated];
        }, 3);

        return response()->json([
            'message' => 'تمت مزامنة الأعطال.',
            'synced_count' => $synced,
            'duplicate_count' => $duplicated,
        ]);
    }

    private function storeOrVerify(array $data, Request $request): ReadingFault
    {
        $owner = $request->user()?->id;
        abort_unless($owner !== null, 401);
        $fault = ReadingFault::firstOrCreate(
            ['sync_uuid' => $data['sync_uuid']],
            $this->fillFromPayload($data, $request),
        );
        if ($fault->wasRecentlyCreated) {
            return $fault;
        }

        // A UUID is an operation identity, not authorization to read someone
        // else's prior record. Never rewrite a historical record on replay.
        abort_unless($fault->user_id !== null && (string) $fault->user_id === (string) $owner
            && ($fault->meter_id === null ? null : (int) $fault->meter_id) === (isset($data['meter_id']) ? (int) $data['meter_id'] : null)
            && $fault->reading_date->toDateString() === substr($fault->fromDateTime($data['reading_date']), 0, 10)
            && $fault->fault_type === ($data['fault_type'] ?? null)
            && $fault->notes === ($data['notes'] ?? null)
            && (! isset($data['recorded_at']) || $fault->getRawOriginal('recorded_at') === $fault->fromDateTime($data['recorded_at'])),
            409, 'معرّف المزامنة مرتبط بسجل مختلف أو غير مصرح بإعادته.');
        return $fault;
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'meter_id' => ['nullable', 'integer', 'exists:meters,id'],
            'reading_date' => ['required', 'date'],
            'fault_type' => ['required', 'string', Rule::in(self::FAULT_TYPES)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'recorded_at' => ['nullable', 'date'],
            'sync_uuid' => ['required', 'uuid'],
        ]);
    }

    private function fillFromPayload(array $data, Request $request): array
    {
        $squareId = null;
        $subscriberId = null;
        if (! empty($data['meter_id'])) {
            // العداد مرتبط بالمشترك، والمربع مرتبط بالمشترك (ليس للعداد).
            $meter = Meter::with('subscriber:id,square_id')->find($data['meter_id']);
            $subscriberId = $meter?->subscriber_id;
            $squareId = $meter?->subscriber?->square_id;
        }

        return [
            'meter_id' => $data['meter_id'] ?? null,
            'subscriber_id' => $subscriberId,
            'square_id' => $squareId,
            'reading_date' => $data['reading_date'],
            'fault_type' => $data['fault_type'],
            'notes' => $data['notes'] ?? null,
            'recorded_at' => $data['recorded_at'] ?? now(),
            'sync_uuid' => $data['sync_uuid'],
            'device_uuid' => (string) $request->header('X-Device-Id', ''),
            'user_id' => $request->user()?->id,
        ];
    }
}
