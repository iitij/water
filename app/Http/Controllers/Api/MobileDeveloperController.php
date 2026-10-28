<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MobileAppLog;
use App\Models\MobileDeveloperSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MobileDeveloperController extends Controller
{
    private const GRANT_MINUTES = 15;

    public function verifyPin(Request $request): JsonResponse
    {
        $data = $request->validate(['pin' => ['required', 'regex:/^[0-9]{5,12}$/']]);
        $settings = MobileDeveloperSetting::current();
        if (! Hash::check($data['pin'], $settings->pin_hash)) {
            return response()->json(['message' => 'رمز المطور غير صحيح.'], 422);
        }

        $grant = Str::random(64);
        Cache::put($this->cacheKey($grant), [
            'user_id' => $request->user()->getAuthIdentifier(),
            'token_id' => $this->tokenId($request),
            'device_id' => (string) $request->header('X-Device-Id', ''),
            'pin_version' => hash('sha256', $settings->pin_hash),
        ], now()->addMinutes(self::GRANT_MINUTES));

        return response()->json([
            'authorized' => true,
            'grant' => $grant,
            'expires_at' => now()->addMinutes(self::GRANT_MINUTES)->toISOString(),
        ]);
    }

    public function config(Request $request): JsonResponse
    {
        $this->requireGrant($request);
        return response()->json(['authorized' => true]);
    }

    public function storeLogs(Request $request): JsonResponse
    {
        $this->requireGrant($request);
        $data = $request->validate([
            'logs' => ['required', 'array', 'min:1', 'max:50'],
            'logs.*.level' => ['required', 'in:error,warning,info,debug'],
            'logs.*.source' => ['nullable', 'string', 'max:100'],
            'logs.*.message' => ['required', 'string', 'max:2000'],
            'logs.*.occurred_at' => ['nullable', 'date'],
        ]);

        foreach ($data['logs'] as $log) {
            MobileAppLog::create([
                'user_id' => $request->user()->getAuthIdentifier(),
                'device_id' => Str::limit((string) $request->header('X-Device-Id', ''), 100, ''),
                'level' => $log['level'],
                'source' => $log['source'] ?? null,
                'message' => $this->redact($log['message']),
                'occurred_at' => $log['occurred_at'] ?? now(),
            ]);
        }

        return response()->json(['received_count' => count($data['logs'])]);
    }

    private function requireGrant(Request $request): void
    {
        $grant = (string) $request->header('X-Developer-Grant', '');
        abort_unless(preg_match('/^[A-Za-z0-9]{64}$/', $grant) === 1, 403, 'يلزم تفعيل وضع المطور.');
        $record = Cache::get($this->cacheKey($grant));
        $settings = MobileDeveloperSetting::current();
        abort_unless(is_array($record)
            && $record['user_id'] === $request->user()->getAuthIdentifier()
            && $record['token_id'] === $this->tokenId($request)
            && $record['device_id'] === (string) $request->header('X-Device-Id', '')
            && $record['pin_version'] === hash('sha256', $settings->pin_hash), 403, 'انتهت صلاحية وضع المطور.');
    }

    private function cacheKey(string $grant): string
    {
        return 'mobile_developer_grant:' . hash('sha256', $grant);
    }

    private function tokenId(Request $request): mixed
    {
        $token = $request->user()->currentAccessToken();
        return $token && method_exists($token, 'getKey') ? $token->getKey() : null;
    }

    private function redact(string $message): string
    {
        $message = preg_replace('/\bBearer\s+[^\s,;]+/i', 'Bearer [REDACTED]', $message);
        $message = preg_replace('/([?&](?:token|password|secret|pin|api_key)=)[^&\s]+/i', '$1[REDACTED]', $message);
        return preg_replace('/("?(?:token|password|secret|pin|api_key)"?\s*[:=]\s*)[^,\s}]+/i', '$1[REDACTED]', $message);
    }
}
