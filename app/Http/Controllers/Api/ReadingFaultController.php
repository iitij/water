<?php

namespace App\Http\Controllers\Api;

use App\Models\Meter;
use App\Models\Reading;
use App\Models\ReadingFault;
use Illuminate\Http\Request;
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
                'meter:id,unique_code,path_number,tenant_name,subscriber_id',
                'subscriber:id,name,phone',
                'square:id,name',
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
        $fault = ReadingFault::firstOrCreate(
            ['sync_uuid' => $data['sync_uuid']],
            $this->fillFromPayload($data, $request),
        );

        return response()->json([
            'message' => 'تم تسجيل عطل القراءة.',
            'fault' => $fault->load(['meter:id,unique_code,path_number', 'square:id,name']),
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

        $synced = 0;
        $duplicated = 0;

        foreach ($data['faults'] as $item) {
            $fault = ReadingFault::firstOrCreate(
                ['sync_uuid' => $item['sync_uuid']],
                $this->fillFromPayload($item, $request),
            );
            $fault->wasRecentlyCreated ? $synced++ : $duplicated++;
        }

        return response()->json([
            'message' => 'تمت مزامنة الأعطال.',
            'synced_count' => $synced,
            'duplicate_count' => $duplicated,
        ]);
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
