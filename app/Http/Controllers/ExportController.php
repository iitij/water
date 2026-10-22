<?php

namespace App\Http\Controllers;

use App\Services\DataExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportController extends Controller
{
    public function exportSubscribers(Request $request, DataExportService $exportService): BinaryFileResponse
    {
        $squareId = $request->query('square_id') ? (int)$request->query('square_id') : null;
        $status = $request->query('status') ?: null;

        $filePath = $exportService->exportSubscribers($squareId, $status);
        $fileName = 'المشتركون_' . now()->format('Y-m-d') . '.xlsx';

        return response()->download($filePath, $fileName)->deleteFileAfterSend(true);
    }

    public function exportInvoices(Request $request, DataExportService $exportService): BinaryFileResponse
    {
        $squareId = $request->query('square_id') ? (int)$request->query('square_id') : null;
        $fromDate = $request->query('from_date') ?: null;
        $toDate = $request->query('to_date') ?: null;
        $status = $request->query('status') ?: null;

        $filePath = $exportService->exportInvoices($squareId, $fromDate, $toDate, $status);
        $fileName = 'فواتير_المشتركين_' . now()->format('Y-m-d') . '.xlsx';

        return response()->download($filePath, $fileName)->deleteFileAfterSend(true);
    }

    public function exportReadings(Request $request, DataExportService $exportService): BinaryFileResponse
    {
        $squareId = $request->query('square_id') ? (int)$request->query('square_id') : null;
        $fromDate = $request->query('from_date') ?: null;
        $toDate = $request->query('to_date') ?: null;
        $isInvoiced = null;
        if ($request->has('is_invoiced') && $request->query('is_invoiced') !== '') {
            $isInvoiced = (bool)$request->query('is_invoiced');
        }

        $filePath = $exportService->exportReadings($squareId, $fromDate, $toDate, $isInvoiced);
        $fileName = 'قراءات_العدادات_' . now()->format('Y-m-d') . '.xlsx';

        return response()->download($filePath, $fileName)->deleteFileAfterSend(true);
    }
}
