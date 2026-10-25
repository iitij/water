<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class SystemLogController extends Controller
{
    private const RECENT_JSON_FILE = 'logs/api_logs_recent.json';

    private const LOG_FILE = 'logs/api_requests.log';

    private const CLIENT_ERRORS_FILE = 'logs/client_error_reports.json';

    private const MAX_CLIENT_REPORTS = 200;

    /**
     * جلب سجلات الـ API الحديثة مع إمكانية التصفية والبحث
     */
    public function index(Request $request): JsonResponse
    {
        $path = storage_path(self::RECENT_JSON_FILE);
        $logs = [];

        if (File::exists($path)) {
            $content = File::get($path);
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $logs = $decoded;
            }
        }

        $filter = $request->query('filter'); // 'errors', 'slow', or null
        $search = strtolower((string) $request->query('search', ''));
        $limit = (int) $request->query('limit', 50);
        if ($limit < 1 || $limit > 200) {
            $limit = 50;
        }

        $totalRecorded = count($logs);
        $totalErrors = 0;
        $totalSlow = 0;

        foreach ($logs as $log) {
            if (! empty($log['is_error'])) {
                $totalErrors++;
            }
            if (! empty($log['is_slow'])) {
                $totalSlow++;
            }
        }

        $filtered = array_filter($logs, function ($item) use ($filter, $search) {
            // تصفية حسب النوع
            if ($filter === 'errors' && empty($item['is_error'])) {
                return false;
            }
            if ($filter === 'slow' && empty($item['is_slow'])) {
                return false;
            }

            // بحث نصي
            if ($search !== '') {
                $matchMethod = str_contains(strtolower($item['method'] ?? ''), $search);
                $matchPath = str_contains(strtolower($item['path'] ?? ''), $search);
                $matchStatus = str_contains((string) ($item['status'] ?? ''), $search);
                $matchError = false;

                if (! empty($item['error'])) {
                    $errorStr = is_string($item['error']) ? $item['error'] : json_encode($item['error']);
                    $matchError = str_contains(strtolower($errorStr), $search);
                }

                if (! $matchMethod && ! $matchPath && ! $matchStatus && ! $matchError) {
                    return false;
                }
            }

            return true;
        });

        $paged = array_slice(array_values($filtered), 0, $limit);

        // تقارير أعطال تطبيق الهاتف (telemetry) — تُعرض في تبويب الخادم للدعم.
        $clientReports = $this->readClientReports();

        return response()->json([
            'status' => 'success',
            'summary' => [
                'total_recorded' => $totalRecorded,
                'total_errors' => $totalErrors,
                'total_slow' => $totalSlow,
                'returned_count' => count($paged),
                'client_reports_count' => count($clientReports),
            ],
            'logs' => $paged,
            'client_reports' => array_slice($clientReports, 0, $limit),
        ]);
    }

    /**
     * مسح سجلات الـ API
     */
    public function clear(): JsonResponse
    {
        $jsonPath = storage_path(self::RECENT_JSON_FILE);
        $logPath = storage_path(self::LOG_FILE);

        if (File::exists($jsonPath)) {
            File::put($jsonPath, json_encode([]));
        }

        if (File::exists($logPath)) {
            File::put($logPath, '');
        }

        $clientPath = storage_path(self::CLIENT_ERRORS_FILE);
        if (File::exists($clientPath)) {
            File::put($clientPath, json_encode([]));
        }

        return response()->json([
            'status' => 'success',
            'message' => 'تم مسح سجلات الـ API بنجاح.',
        ]);
    }

    /**
     * استقبال تقارير الأعطال من تطبيق الهاتف (ErrorReporter) — لأسباب الدعم
     * والتشخيص: رسالة الخطأ، الأثر المختصر، إصدار التطبيق، ومعرّف الجهاز.
     */
    public function storeErrorReports(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reports' => ['required', 'array', 'max:50'],
            'reports.*.message' => ['required', 'string', 'max:2000'],
            'reports.*.stack' => ['nullable', 'string', 'max:8000'],
            'reports.*.context' => ['nullable', 'array'],
            'reports.*.occurred_at' => ['nullable', 'date'],
            'reports.*.app_version' => ['nullable', 'string', 'max:50'],
        ]);

        $existing = $this->readClientReports();
        $deviceUuid = (string) $request->header('X-Device-Id', '');

        foreach ($data['reports'] as $report) {
            $existing[] = [
                'id' => (string) Str::uuid(),
                'received_at' => now()->toIso8601String(),
                'occurred_at' => $report['occurred_at'] ?? now()->toIso8601String(),
                'message' => $report['message'],
                'stack' => $report['stack'] ?? null,
                'context' => $report['context'] ?? null,
                'app_version' => $report['app_version'] ?? null,
                'device_id' => $deviceUuid,
                'user_id' => $request->user()?->id,
            ];
        }

        // سجل دائري مثل بقية السجلات.
        if (count($existing) > self::MAX_CLIENT_REPORTS) {
            $existing = array_slice($existing, count($existing) - self::MAX_CLIENT_REPORTS);
        }

        try {
            File::ensureDirectoryExists(dirname(storage_path(self::CLIENT_ERRORS_FILE)));
            File::put(storage_path(self::CLIENT_ERRORS_FILE), json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } catch (\Throwable) {
            // لا يُفشل الطلب إذا تعذّر حفظ السجل.
        }

        return response()->json([
            'status' => 'success',
            'message' => 'تم استلام تقارير الأعطال.',
            'received_count' => count($data['reports']),
        ]);
    }

    private function readClientReports(): array
    {
        $path = storage_path(self::CLIENT_ERRORS_FILE);
        if (! File::exists($path)) {
            return [];
        }
        $decoded = json_decode(File::get($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
