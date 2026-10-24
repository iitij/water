<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class SystemLogController extends Controller
{
    private const RECENT_JSON_FILE = 'logs/api_logs_recent.json';
    private const LOG_FILE = 'logs/api_requests.log';

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
            if (!empty($log['is_error'])) {
                $totalErrors++;
            }
            if (!empty($log['is_slow'])) {
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

                if (!empty($item['error'])) {
                    $errorStr = is_string($item['error']) ? $item['error'] : json_encode($item['error']);
                    $matchError = str_contains(strtolower($errorStr), $search);
                }

                if (!$matchMethod && !$matchPath && !$matchStatus && !$matchError) {
                    return false;
                }
            }

            return true;
        });

        $paged = array_slice(array_values($filtered), 0, $limit);

        return response()->json([
            'status' => 'success',
            'summary' => [
                'total_recorded' => $totalRecorded,
                'total_errors' => $totalErrors,
                'total_slow' => $totalSlow,
                'returned_count' => count($paged),
            ],
            'logs' => $paged,
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

        return response()->json([
            'status' => 'success',
            'message' => 'تم مسح سجلات الـ API بنجاح.',
        ]);
    }
}
