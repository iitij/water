<?php

namespace App\Http\Controllers\Api;

use App\Models\Subscriber;
use App\Services\LedgerStatementExportService;
use App\Services\LedgerStatementService;
use Illuminate\Http\Request;
use Spatie\LaravelPdf\Facades\Pdf;

final class LedgerStatementController extends ApiController
{
    private function data(Request $request, int $id, LedgerStatementService $service): array
    {
        $this->authorizeApi(Subscriber::class, 'view');
        $period = $request->validate([
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date', 'before_or_equal:today'],
            'statement_hash' => ['nullable', 'regex:/^[a-f0-9]{64}$/'],
        ]);
        $statement = $service->statement(Subscriber::findOrFail($id), $period['from_date'], $period['to_date']);
        $service->requireMatchingSnapshot($statement, $period['statement_hash'] ?? null);
        return $statement;
    }

    public function show(Request $request, int $id, LedgerStatementService $service)
    {
        return response()->json($this->data($request, $id, $service));
    }

    public function xlsx(Request $request, int $id, LedgerStatementService $service, LedgerStatementExportService $export)
    {
        $this->authorizeSystem('View:ExportDataPage');
        $statement = $this->data($request, $id, $service);
        return response()->download($export->xlsx($statement), 'ledger-statement-'.$id.'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend(true);
    }

    public function pdf(Request $request, int $id, LedgerStatementService $service)
    {
        $statement = $this->data($request, $id, $service);
        return Pdf::view('pdf.ledger_statement', ['statement' => $statement])
            ->withBrowsershot(fn ($browsershot) => $browsershot->noSandbox())
            ->format('A4')->name('ledger-statement-'.$id.'.pdf')->download();
    }
}
