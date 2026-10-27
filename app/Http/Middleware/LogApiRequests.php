<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * يسجل جميع طلبات واستجابات الـ API بين تطبيق الهاتف والخادم.
 *
 * يهدف هذا الوسيط إلى:
 * 1. تتبّع أي خطأ (4xx أو 5xx أو استثناءات غير متوقعة).
 * 2. قياس زمن الاستجابة واكتشاف اللاق / البطء (أكثر من 1000 مللي ثانية).
 * 3. حفظ بيانات الطلب والرد ومقارنتها بتطبيق الهاتف عبر ترويسة X-Request-Id.
 * 4. حفظ سجل دائري لآخر 200 طلب يمكن قراءته مباشرة من لوحة التحكم أو التطبيق.
 */
class LogApiRequests
{
    private const MAX_RECENT_LOGS = 200;
    private const SLOW_THRESHOLD_MS = 1000;
    private const LOG_FILE = 'logs/api_requests.log';
    private const RECENT_JSON_FILE = 'logs/api_logs_recent.json';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();
        $startTime = microtime(true);
        $requestTime = now()->format('Y-m-d H:i:s');

        // لا نسجل طلبات السجلات نفسها لتجنب الحلقات اللانهائية
        $isLogRoute = str_contains($request->path(), 'api-logs') || str_contains($request->path(), 'system/logs') || str_contains($request->path(), 'developer/');

        // تنظيف البيانات الحساسة (كلمات المرور، التوكنات، أرقام البطاقات، PIN)
        $sanitizedPayload = $this->sanitizeData($request->all());
        $sanitizedHeaders = [
            'x-device-id' => $request->header('X-Device-Id'),
            'x-client-version' => $request->header('X-Client-Version'),
            'user-agent' => $request->header('User-Agent'),
            'accept' => $request->header('Accept'),
            'content-type' => $request->header('Content-Type'),
        ];

        $userId = $request->user()?->id;
        $deviceId = $request->header('X-Device-Id') ?? 'unknown';
        $method = $request->method();
        $path = $request->path();
        $ip = $request->ip();

        try {
            /** @var Response $response */
            $response = $next($request);
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            // إضافة ترويسات زمن المعالجة ومعرّف الطلب في الرد
            $response->headers->set('X-Request-Id', $requestId);
            $response->headers->set('X-Response-Time-Ms', (string) $durationMs);

            if (!$isLogRoute) {
                $statusCode = $response->getStatusCode();
                $isSlow = $durationMs >= self::SLOW_THRESHOLD_MS;
                $isError = $statusCode >= 400;

                $responseBodyPreview = $this->getResponseBodyPreview($response);

                $logData = [
                    'id' => $requestId,
                    'timestamp' => $requestTime,
                    'method' => $method,
                    'path' => $path,
                    'full_url' => $request->fullUrl(),
                    'status' => $statusCode,
                    'duration_ms' => $durationMs,
                    'is_slow' => $isSlow,
                    'is_error' => $isError,
                    'user_id' => $userId,
                    'device_id' => $deviceId,
                    'client_version' => $request->header('X-Client-Version'),
                    'ip' => $ip,
                    'query' => $request->query(),
                    'payload' => $sanitizedPayload,
                    'headers' => $sanitizedHeaders,
                    'response_preview' => $responseBodyPreview,
                    'error' => $isError ? $responseBodyPreview : null,
                ];

                $this->writeToLogFile($logData);
                $this->saveRecentLog($logData);
            }

            return $response;
        } catch (Throwable $e) {
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            $errorDetails = [
                'message' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 8),
            ];

            if (!$isLogRoute) {
                $logData = [
                    'id' => $requestId,
                    'timestamp' => $requestTime,
                    'method' => $method,
                    'path' => $path,
                    'full_url' => $request->fullUrl(),
                    'status' => 500,
                    'duration_ms' => $durationMs,
                    'is_slow' => $durationMs >= self::SLOW_THRESHOLD_MS,
                    'is_error' => true,
                    'user_id' => $userId,
                    'device_id' => $deviceId,
                    'client_version' => $request->header('X-Client-Version'),
                    'ip' => $ip,
                    'query' => $request->query(),
                    'payload' => $sanitizedPayload,
                    'headers' => $sanitizedHeaders,
                    'response_preview' => 'Internal Server Exception: ' . $e->getMessage(),
                    'error' => $errorDetails,
                ];

                $this->writeToLogFile($logData);
                $this->saveRecentLog($logData);

                Log::error("[API EXCEPTION 500] {$method} /{$path} ({$durationMs}ms) - " . $e->getMessage(), [
                    'request_id' => $requestId,
                    'user_id' => $userId,
                    'device_id' => $deviceId,
                    'exception' => $errorDetails,
                ]);
            }

            throw $e;
        }
    }

    /**
     * إخفاء الحقول الحساسة لضمان الأمان والخصوصية
     */
    private function sanitizeData(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        $sensitiveKeys = [
            'password',
            'password_confirmation',
            'token',
            'secret',
            'pin',
            'credit_card',
            'authorization',
        ];

        $cleaned = [];
        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);
            if (in_array($lowerKey, $sensitiveKeys, true)) {
                $cleaned[$key] = '******** (مخفي لأسباب أمنية)';
            } elseif (is_array($value)) {
                $cleaned[$key] = $this->sanitizeData($value);
            } else {
                $cleaned[$key] = $value;
            }
        }

        return $cleaned;
    }

    /**
     * استخراج معاينة من جسم الرد
     */
    private function getResponseBodyPreview(Response $response): mixed
    {
        $contentType = $response->headers->get('Content-Type', '');
        if (str_contains($contentType, 'application/pdf') || str_contains($contentType, 'image/')) {
            return '[ملف ثنائي: ' . $contentType . ']';
        }

        $content = $response->getContent();
        if ($content === false || $content === '') {
            return null;
        }

        // محاولة فك JSON إذا كان متاحاً
        $json = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $json;
        }

        if (mb_strlen($content) > 1500) {
            return mb_substr($content, 0, 1500) . '... (مقتطع)';
        }

        return $content;
    }

    /**
     * الكتابة في ملف السجل المخصص api_requests.log
     */
    private function writeToLogFile(array $data): void
    {
        try {
            $tag = $data['is_error'] ? 'ERROR' : ($data['is_slow'] ? 'SLOW' : 'OK');
            $line = sprintf(
                "[%s] [API %s %s] (%dms%s) %s /%s [User: %s, Device: %s]%s\n",
                $data['timestamp'],
                $data['status'],
                $tag,
                $data['duration_ms'],
                $data['is_slow'] ? ' - بطء/لاق' : '',
                $data['method'],
                $data['path'],
                $data['user_id'] ?? 'guest',
                $data['device_id'],
                $data['is_error'] ? ' -> ' . json_encode($data['error'], JSON_UNESCAPED_UNICODE) : ''
            );

            $path = storage_path(self::LOG_FILE);
            $dir = dirname($path);
            if (!File::isDirectory($dir)) {
                File::makeDirectory($dir, 0755, true, true);
            }

            File::append($path, $line);
        } catch (Throwable) {
            // لا يجوز أن يفشل الطلب إذا تعذر حفظ السجل
        }
    }

    /**
     * حفظ السجلات الدائرية لآخر 200 طلب كملف JSON لسهولة القراءة
     */
    private function saveRecentLog(array $data): void
    {
        try {
            $path = storage_path(self::RECENT_JSON_FILE);
            $recent = [];

            if (File::exists($path)) {
                $content = File::get($path);
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    $recent = $decoded;
                }
            }

            // إضافة الطلب في البداية (الأحدث أولاً)
            array_unshift($recent, $data);

            // الحفاظ على الحد الأقصى
            if (count($recent) > self::MAX_RECENT_LOGS) {
                $recent = array_slice($recent, 0, self::MAX_RECENT_LOGS);
            }

            File::put($path, json_encode($recent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } catch (Throwable) {
            // تجاهل أخطاء التخزين المؤقت
        }
    }
}
