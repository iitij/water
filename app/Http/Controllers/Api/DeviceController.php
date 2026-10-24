<?php

namespace App\Http\Controllers\Api;

use App\Models\Device;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

/**
 * أجهزة المستخدم وإبطالها. الجهاز المُبطَل يُرفض في أول طلب لاحق عبر
 * `EnsureDeviceIsActive` (راجع app/Http/Middleware).
 */
class DeviceController extends ApiController
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /** أجهزة المستخدم الحالي. */
    public function index(Request $request)
    {
        $devices = Device::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_seen_at')
            ->get()
            ->map(fn (Device $device) => $this->payload($device));

        return response()->json(['devices' => $devices]);
    }

    /** تسجيل/تحديث جهاز. العملية idempotent بحسب device_uuid. */
    public function store(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'device_uuid' => ['required', 'string', 'max:80'],
            'name' => ['nullable', 'string', 'max:120'],
            'platform' => ['nullable', 'string', 'max:20'],
            'app_version' => ['nullable', 'string', 'max:40'],
        ]);

        $device = Device::query()->firstOrNew(['device_uuid' => $data['device_uuid']]);
        if ($device->exists && (int) $device->user_id !== (int) $user->id) {
            abort(409, 'معرّف الجهاز مرتبط بحساب آخر. سجّل الخروج ثم أعد تسجيل الدخول.');
        }

        $device->fill([
            'user_id' => $user->id,
            'name' => $data['name'] ?? $device->name,
            'platform' => $data['platform'] ?? $device->platform,
            'app_version' => $data['app_version'] ?? $device->app_version,
            'last_seen_at' => now(),
        ])->save();

        return response()->json(['device' => $this->payload($device)], $device->wasRecentlyCreated ? 201 : 200);
    }

    /** إبطال جهاز. */
    public function destroy(Request $request, int $id)
    {
        $user = $request->user();
        $device = Device::findOrFail($id);

        $isOwner = (int) $device->user_id === (int) $user->id;
        abort_unless(
            $isOwner || $this->isAdminUser($user),
            403,
            'لا تملك صلاحية إبطال هذا الجهاز.',
        );

        if (! $device->isRevoked()) {
            $device->forceFill([
                'revoked_at' => now(),
                'revoked_by' => $user->id,
            ])->save();

            $this->audit->record('device.revoked', [
                'entity_type' => Device::class,
                'entity_id' => $device->id,
                'after' => ['device_uuid' => $device->device_uuid, 'user_id' => $device->user_id],
            ]);
        }

        return response()->json(['message' => 'تم إبطال الجهاز.', 'device' => $this->payload($device)]);
    }

    private function payload(Device $device): array
    {
        return [
            'id' => $device->id,
            'device_uuid' => $device->device_uuid,
            'name' => $device->name,
            'platform' => $device->platform,
            'app_version' => $device->app_version,
            'last_seen_at' => optional($device->last_seen_at)->toIso8601String(),
            'revoked_at' => optional($device->revoked_at)->toIso8601String(),
        ];
    }
}
