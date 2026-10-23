<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class WhatsAppServerController extends ApiController
{
    private function serverUrl(): string
    {
        return rtrim((string) config('services.whatsapp_local.url', env('WHATSAPP_SERVER_URL', 'http://127.0.0.1:3000')), '/');
    }

    public function status()
    {
        $this->authorizeSystem('View:WhatsAppSettings');
        $status = 'offline';
        $qr = null;
        try {
            $response = Http::timeout(2)->get($this->serverUrl() . '/api/status');
            if ($response->successful()) {
                $data = $response->json();
                $qr = $data['qr'] ?? null;
                $status = ! empty($data['connected']) ? 'connected' : ($qr ? 'qr' : 'waiting');
            }
        } catch (\Throwable) {
            // The local WhatsApp service is optional and may not be running.
        }
        return response()->json([
            'status' => $status,
            'connected' => $status === 'connected',
            'qr' => $qr,
            'whatsapp_logs' => $this->tailLog(storage_path('logs/whatsapp.log')),
            'queue_logs' => $this->tailLog(storage_path('logs/queue.log')),
        ]);
    }

    public function qr()
    {
        $this->authorizeSystem('View:WhatsAppSettings');
        $response = $this->proxyGet('/api/status');
        if ($response instanceof \Illuminate\Http\JsonResponse) return $response;
        return response()->json(['qr' => $response->json('qr'), 'connected' => (bool) $response->json('connected')]);
    }

    public function start()
    {
        $this->authorizeSystemWrite('WhatsAppSettings');
        $script = base_path('whatsapp-server/server.js');
        if (! is_file($script)) {
            return response()->json(['message' => 'مجلد خادم الواتساب المحلي غير موجود في هذا التثبيت.'], 503);
        }
        $process = new Process(['node', $script], base_path());
        $process->disableOutput();
        $process->start();
        $pid = $process->getPid();
        if ($pid) Storage::put('whatsapp-server.pid', (string) $pid);
        return response()->json(['message' => 'بدأ تشغيل خادم الواتساب.', 'pid' => $pid], 202);
    }

    public function stop()
    {
        $this->authorizeSystemWrite('WhatsAppSettings');
        $pidFile = storage_path('app/whatsapp-server.pid');
        if (! is_file($pidFile)) {
            return response()->json(['message' => 'لا يوجد معرّف عملية مسجل لخادم الواتساب.'], 409);
        }
        $pid = trim((string) file_get_contents($pidFile));
        if (! ctype_digit($pid) || (int) $pid < 1) {
            @unlink($pidFile);
            return response()->json(['message' => 'تعذر التحقق من معرّف عملية الخادم.'], 409);
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $stop = new Process(['taskkill', '/F', '/PID', $pid]);
        } else {
            $stop = new Process(['kill', '-TERM', $pid]);
        }
        $stop->run();
        @unlink($pidFile);
        if (! $stop->isSuccessful()) {
            return response()->json(['message' => 'تعذر إيقاف خادم الواتساب.', 'details' => trim($stop->getErrorOutput())], 503);
        }
        return response()->json(['message' => 'تم إيقاف خادم الواتساب.']);
    }

    public function logout()
    {
        $this->authorizeSystemWrite('WhatsAppSettings');
        return $this->proxyPost('/api/logout', []);
    }

    public function sendTest(Request $request)
    {
        $this->authorizeSystemWrite('WhatsAppSettings');
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'message' => ['required', 'string', 'max:2000'],
        ]);
        try {
            $response = Http::timeout(20)->post($this->serverUrl() . '/api/send', $data);
            return response()->json(['success' => $response->successful(), 'response' => $response->json() ?? $response->body()], $response->status());
        } catch (\Throwable $exception) {
            return response()->json(['message' => 'تعذر الاتصال بخادم الواتساب المحلي.', 'error' => $exception->getMessage()], 503);
        }
    }

    private function proxyGet(string $path)
    {
        try {
            $response = Http::timeout(3)->get($this->serverUrl() . $path);
            return response()->json($response->json() ?? ['body' => $response->body()], $response->status());
        } catch (\Throwable) {
            return response()->json(['message' => 'خادم الواتساب المحلي غير متصل.'], 503);
        }
    }

    private function proxyPost(string $path, array $payload)
    {
        try {
            $response = Http::timeout(5)->post($this->serverUrl() . $path, $payload);
            return response()->json($response->json() ?? ['body' => $response->body()], $response->status());
        } catch (\Throwable) {
            return response()->json(['message' => 'خادم الواتساب المحلي غير متصل.'], 503);
        }
    }

    private function tailLog(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) return '';
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        return $lines === false ? '' : implode("\n", array_slice($lines, -15));
    }
}
