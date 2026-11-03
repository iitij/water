<?php

namespace App\Http\Controllers\Api;

use App\Models\Meter;
use App\Models\Reading;
use App\Models\RouteVisit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * زيارات جولة المسار الميداني (Route Mode) — الخطة 2.0: ربط تقدّم الجولة
 * بالخادم حتى يرى المشرف اكتمال مسار اليوم من أي جهاز.
 */
class RouteVisitController extends ApiController
{
    public const VISIT_STATUSES = [
        'visited',
        'no_answer',
        'refused',
        'skipped',
        'payment_collected',
    ];

    /** سجل زيارات الجولات — للمشرف وللجهاز نفسه لاستعادة التقدّم بعد إعادة التثبيت. */
    public function index(Request $request)
    {
        $this->authorizeApi(Reading::class, 'viewAny');

        $query = RouteVisit::query()
            ->with([
                'meter:id,unique_code,path_number,tenant_name',
                'square:id,name,code',
                'user:id,name',
            ])
            ->latest('route_date')->latest('id');

        if ($request->filled('route_date')) {
            $query->whereDate('route_date', $request->query('route_date'));
        }
        if ($request->filled('from_date')) {
            $query->whereDate('route_date', '>=', $request->query('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->whereDate('route_date', '<=', $request->query('to_date'));
        }
        if ($request->filled('square_id')) {
            $query->where('square_id', $request->integer('square_id'));
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }
        // الجهاز يسحب تقدّمه المحلي فقط افتراضياً.
        if ($request->boolean('mine')) {
            $query->where('user_id', $request->user()?->id);
        }

        return $query->paginate($this->perPage($request))->appends($request->query());
    }

    /** تسجيل زيارة واحدة. */
    public function store(Request $request)
    {
        $this->authorizeApi(Reading::class, 'create');

        $data = $this->validatePayload($request);

        $visit = RouteVisit::firstOrCreate(
            ['sync_uuid' => $data['sync_uuid']],
            $this->fillFromPayload($data, $request),
        );

        return response()->json([
            'message' => 'تم تسجيل الزيارة.',
            'visit' => $visit->load(['meter:id,unique_code,path_number', 'square:id,name,code']),
            'duplicate' => ! $visit->wasRecentlyCreated,
        ], 201);
    }

    /** رفع دفعة زيارات من الجهاز في نهاية الجولة أو عند عودة الاتصال. */
    public function syncBatch(Request $request)
    {
        $this->authorizeApi(Reading::class, 'create');

        $data = $request->validate([
            'visits' => ['required', 'array', 'max:500'],
            'visits.*.route_date' => ['required', 'date'],
            'visits.*.meter_id' => ['nullable', 'integer', 'exists:meters,id'],
            'visits.*.meter_code' => ['nullable', 'string', 'max:100'],
            'visits.*.status' => ['required', 'string', Rule::in(self::VISIT_STATUSES)],
            'visits.*.collected_amount' => ['nullable', 'numeric', 'min:0'],
            'visits.*.notes' => ['nullable', 'string', 'max:2000'],
            'visits.*.visit_at' => ['nullable', 'date'],
            'visits.*.sync_uuid' => ['required', 'uuid'],
        ]);

        $synced = 0;
        $duplicated = 0;

        foreach ($data['visits'] as $item) {
            $visit = RouteVisit::firstOrCreate(
                ['sync_uuid' => $item['sync_uuid']],
                $this->fillFromPayload($item, $request),
            );
            $visit->wasRecentlyCreated ? $synced++ : $duplicated++;
        }

        return response()->json([
            'message' => 'تمت مزامنة الزيارات.',
            'synced_count' => $synced,
            'duplicate_count' => $duplicated,
        ]);
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'route_date' => ['required', 'date'],
            'meter_id' => ['nullable', 'integer', 'exists:meters,id'],
            'meter_code' => ['nullable', 'string', 'max:100'],
            'status' => ['required', 'string', Rule::in(self::VISIT_STATUSES)],
            'collected_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'visit_at' => ['nullable', 'date'],
            'sync_uuid' => ['required', 'uuid'],
        ]);
    }

    private function fillFromPayload(array $data, Request $request): array
    {
        $squareId = null;
        if (! empty($data['meter_id'])) {
            $meter = Meter::with('subscriber:id,square_id')->find($data['meter_id']);
            $squareId = $meter?->subscriber?->square_id;
        }

        return [
            'route_date' => $data['route_date'],
            'meter_id' => $data['meter_id'] ?? null,
            'square_id' => $squareId,
            'meter_code' => $data['meter_code'] ?? null,
            'status' => $data['status'],
            'collected_amount' => (float) ($data['collected_amount'] ?? 0),
            'notes' => $data['notes'] ?? null,
            'visit_at' => $data['visit_at'] ?? now(),
            'sync_uuid' => $data['sync_uuid'],
            'device_uuid' => (string) $request->header('X-Device-Id', ''),
            'user_id' => $request->user()?->id,
        ];
    }
}
