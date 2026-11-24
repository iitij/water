<?php

namespace App\Http\Controllers\Api;

use App\Models\Invoice;
use App\Models\Reading;
use App\Models\Square;
use App\Models\Subscriber;
use App\Services\DataExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportController extends ApiController
{
    public function summary()
    {
        $this->authorizeSystem('View:ExportDataPage');
        return response()->json([
            'totalSubscribers' => Subscriber::count(),
            'totalInvoices' => Invoice::count(),
            'totalReadings' => Reading::count(),
            'totalSquares' => Square::count(),
        ]);
    }

    public function subscribers(Request $request, DataExportService $service): BinaryFileResponse
    {
        $this->authorizeSystem('View:ExportDataPage');
        $data = $request->validate(['square_id' => ['nullable', 'integer', 'exists:squares,id'], 'status' => ['nullable', 'in:active,inactive,suspended']]);
        $file = $service->exportSubscribers(isset($data['square_id']) ? (int) $data['square_id'] : null, $data['status'] ?? null);
        return response()->download($file, 'المشتركون_' . now()->format('Y-m-d') . '.xlsx')->deleteFileAfterSend(true);
    }

    public function invoices(Request $request, DataExportService $service): BinaryFileResponse
    {
        $this->authorizeSystem('View:ExportDataPage');
        $data = $request->validate([
            'square_id' => ['nullable', 'integer', 'exists:squares,id'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'status' => ['nullable', 'in:draft,issued,partially_paid,paid,overdue,cancelled'],
        ]);
        $file = $service->exportInvoices($data['square_id'] ?? null, $data['from_date'] ?? null, $data['to_date'] ?? null, $data['status'] ?? null);
        return response()->download($file, 'فواتير_المشتركين_' . now()->format('Y-m-d') . '.xlsx')->deleteFileAfterSend(true);
    }

    public function readings(Request $request, DataExportService $service): BinaryFileResponse
    {
        $this->authorizeSystem('View:ExportDataPage');
        $data = $request->validate([
            'square_id' => ['nullable', 'integer', 'exists:squares,id'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'is_invoiced' => ['nullable', 'boolean'],
        ]);
        $isInvoiced = array_key_exists('is_invoiced', $data) ? (bool) $data['is_invoiced'] : null;
        $file = $service->exportReadings($data['square_id'] ?? null, $data['from_date'] ?? null, $data['to_date'] ?? null, $isInvoiced);
        return response()->download($file, 'قراءات_العدادات_' . now()->format('Y-m-d') . '.xlsx')->deleteFileAfterSend(true);
    }
}
