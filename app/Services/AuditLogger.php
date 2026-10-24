<?php

namespace App\Services;

use App\Models\AuditEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * كتابة أحداث التدقيق. التسجيل لا يجوز أن يُسقِط العملية الأصلية:
 * أي فشل في السجل يُكتب في اللوج فقط ويُكمَل تنفيذ العملية.
 *
 * مرجع: خطة الإصدار 2.0 — الفصل 9.6.
 */
class AuditLogger
{
    /**
     * @param  array{before?: mixed, after?: mixed, entity_type?: string, entity_id?: mixed,
     *               device_id?: string, source?: string, actor_user_id?: int|null}  $context
     */
    public function record(string $event, array $context = []): ?AuditEvent
    {
        try {
            $request = request();

            return AuditEvent::create([
                'actor_user_id' => $context['actor_user_id'] ?? $this->actorId(),
                'event' => $event,
                'entity_type' => $context['entity_type'] ?? null,
                'entity_id' => isset($context['entity_id']) ? (string) $context['entity_id'] : null,
                'before' => $context['before'] ?? null,
                'after' => $context['after'] ?? null,
                'device_id' => $context['device_id'] ?? $this->deviceId($request),
                'source' => $context['source'] ?? $this->source($request),
                'ip_address' => $request?->ip(),
                'user_agent' => substr((string) $request?->userAgent(), 0, 255) ?: null,
                'occurred_at' => now(),
            ]);
        } catch (Throwable $error) {
            Log::warning('audit.record_failed', [
                'event' => $event,
                'error' => $error->getMessage(),
            ]);

            return null;
        }
    }

    private function actorId(): ?int
    {
        $user = request()?->user();

        return $user ? (int) $user->getKey() : null;
    }

    private function deviceId(?Request $request): ?string
    {
        if (! $request) {
            return null;
        }
        $device = $request->header('X-Device-Id') ?: $request->input('device_id');

        return is_string($device) && $device !== '' ? substr($device, 0, 120) : null;
    }

    private function source(?Request $request): string
    {
        if (! $request) {
            return 'cli';
        }
        $source = $request->header('X-Client-Source');
        if (is_string($source) && $source !== '') {
            return substr($source, 0, 20);
        }

        return $request->expectsJson() ? 'api' : 'web';
    }
}
