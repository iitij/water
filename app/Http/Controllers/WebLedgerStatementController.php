<?php

namespace App\Http\Controllers;

use App\Models\Subscriber;
use App\Services\LedgerStatementExportService;
use App\Services\LedgerStatementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Spatie\LaravelPdf\Facades\Pdf;

final class WebLedgerStatementController extends Controller
{
    public function download(Request $request, Subscriber $subscriber, string $format, LedgerStatementService $service, LedgerStatementExportService $export)
    {
        Gate::authorize('view', $subscriber);
        if ($format === 'xlsx') {
            Gate::authorize('View:ExportDataPage');
        }
        $period = $request->validate([
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date', 'before_or_equal:today'],
        ]);
        $statement = $service->statement($subscriber, $period['from_date'], $period['to_date']);
        if ($format === 'xlsx') {
            return response()->download($export->xlsx($statement), 'ledger-statement-'.$subscriber->id.'.xlsx',
                ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend(true);
        }
        abort_unless($format === 'pdf', 404);
        return Pdf::view('pdf.ledger_statement', ['statement' => $statement])
            ->withBrowsershot(fn ($browsershot) => $browsershot->noSandbox())
            ->format('A4')->name('ledger-statement-'.$subscriber->id.'.pdf')->download();
    }
}
