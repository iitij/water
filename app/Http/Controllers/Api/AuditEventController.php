<?php

namespace App\Http\Controllers\Api;

use App\Models\AuditEvent;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

/**
 * سجل التدقيق: القراءة للمشرفين، والكتابة المجمّعة من التطبيق.
 *
 * الأحداث القادمة من التطبيق تُخزَّن كما هي (المصدر = mobile) ولا يُسمح للعميل
 * بانتحال هوية فاعل آخر: الفاعل دائمًا المستخدم المُصادَق.
 */
class AuditEventController extends ApiController
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(Request $request)
    {
        $this->authorizeSystem('ViewAny:AuditEvent');

        $query = AuditEvent::query()->with('actor:id,name')->latest('id');

        if ($request->filled('event')) {
            $query->where('event', (string) $request->query('event'));
        }
        if ($request->filled('entity_type')) {
            $query->where('entity_type', (string) $request->query('entity_type'));
        }
        if ($request->filled('entity_id')) {
            $query->where('entity_id', (string) $request->query('entity_id'));
        }
        if ($request->filled('user_id')) {
            $query->where('actor_user_id', $request->integer('user_id'));
        }
        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->query('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->query('to_date'));
        }

        return $query->paginate($this->perPage($request))->through(
            fn (AuditEvent $event) => [
                'id' => $event->id,
                'event' => $event->event,
                'actor' => $event->actor?->name,
                'actor_user_id' => $event->actor_user_id,
                'entity_type' => $event->entity_type,
                'entity_id' => $event->entity_id,
                'before' => $event->before,
                'after' => $event->after,
                'device_id' => $event->device_id,
                'source' => $event->source,
                'created_at' => optional($event->created_at)->toIso8601String(),
            ],
        )->appends($request->query());
    }

    /** استقبال دفعة أحداث من جهاز (حد أقصى 100 حدث في الطلب). */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 401, 'يلزم تسجيل الدخول.');

        $data = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:100'],
            'events.*.event' => ['required', 'string', 'max:80'],
            'events.*.entity_type' => ['nullable', 'string', 'max:80'],
            'events.*.entity_id' => ['nullable', 'string', 'max:80'],
            'events.*.before' => ['nullable', 'array'],
            'events.*.after' => ['nullable', 'array'],
            'events.*.occurred_at' => ['nullable', 'date'],
            'events.*.device_id' => ['nullable', 'string', 'max:120'],
        ]);

        $stored = 0;
        foreach ($data['events'] as $event) {
            $this->audit->record($event['event'], [
                'entity_type' => $event['entity_type'] ?? null,
                'entity_id' => $event['entity_id'] ?? null,
                'before' => $event['before'] ?? null,
                'after' => $event['after'] ?? null,
                'device_id' => $event['device_id'] ?? null,
                'source' => 'mobile',
                'actor_user_id' => $user->id,
            ]);
            $stored++;
        }

        return response()->json(['message' => 'تم تسجيل الأحداث.', 'stored' => $stored], 201);
    }
}
