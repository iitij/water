<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * يرفض الطلبات الصادرة من جهاز أُبطل من لوحة الإدارة أو من شاشة «أجهزتي».
 *
 * الجهاز يُعرَّف بترويسة `X-Device-Id`. الأجهزة غير المسجَّلة تمرّ كما هي حتى
 * لا نُجبر النسخ القديمة على ترقية فورية؛ الحماية تسري على الأجهزة المعروفة.
 */
class EnsureDeviceIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $deviceUuid = $request->header('X-Device-Id');
        if (! is_string($deviceUuid) || $deviceUuid === '' || ! $request->user()) {
            return $next($request);
        }

        $device = Device::query()->where('device_uuid', substr($deviceUuid, 0, 80))->first();
        if ($device && $device->isRevoked()) {
            return response()->json([
                'message' => 'تم إبطال هذا الجهاز. تواصل مع المشرف ثم سجّل الدخول من جديد.',
                'code' => 'DEVICE_REVOKED',
            ], 401);
        }

        if ($device) {
            $device->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
