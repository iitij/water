<?php

use Illuminate\Support\Facades\Route;
use App\Models\Invoice;
use App\Http\Controllers\ExportController;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\Enums\Unit;

Route::get('/', function () {
    return redirect('/admin');
});

Route::get('/invoices/{invoice}/print', function (Invoice $invoice) {
    $invoice->load(['subscriber.square', 'items']);

    return Pdf::view('pdf.invoice', ['invoice' => $invoice])
        ->withBrowsershot(function ($browsershot) {
            if (PHP_OS_FAMILY !== 'Windows') {
                $browsershot->setNodeBinary("/usr/bin/node")
                            ->setNpmBinary("/usr/bin/npm")
                            ->setChromePath("/usr/bin/chromium");
            }
            $browsershot->noSandbox();
        })
        ->margins(0, 0, 0, 0, Unit::Millimeter)
        ->format('A5')
        ->landscape()
        ->name('invoice-' . $invoice->bill_number . '.pdf')
        ->inline();
})->name('invoices.print')->middleware(['web', \Filament\Http\Middleware\Authenticate::class, 'can:view,invoice']);

Route::get('/payments/{payment}/print', function (\App\Models\Payment $payment) {
    $payment->load(['subscriber.square', 'invoice']);

    return Pdf::view('pdf.receipt', ['payment' => $payment])
        ->withBrowsershot(function ($browsershot) {
            if (PHP_OS_FAMILY !== 'Windows') {
                $browsershot->setNodeBinary("/usr/bin/node")
                            ->setNpmBinary("/usr/bin/npm")
                            ->setChromePath("/usr/bin/chromium");
            }
            $browsershot->noSandbox();
        })
        ->margins(0, 0, 0, 0, Unit::Millimeter)
        ->format('A5')
        ->landscape()
        ->name('receipt-' . $payment->receipt_number . '.pdf')
        ->inline();
})->name('payments.print')->middleware(['web', \Filament\Http\Middleware\Authenticate::class, 'can:view,payment']);

// Export Routes (Protected by Filament Web Session)
Route::middleware(['web', \Filament\Http\Middleware\Authenticate::class])->prefix('admin/export')->group(function () {
    Route::get('/subscribers', [ExportController::class, 'exportSubscribers'])->name('admin.export.subscribers')->can('viewAny', \App\Models\Subscriber::class);
    Route::get('/invoices', [ExportController::class, 'exportInvoices'])->name('admin.export.invoices')->can('viewAny', \App\Models\Invoice::class);
    Route::get('/readings', [ExportController::class, 'exportReadings'])->name('admin.export.readings')->can('viewAny', \App\Models\Reading::class);
});

// Official ledger export uses the same projection and subscriber policy as the API.
Route::get('/subscribers/{subscriber}/ledger-statement/{format}', [\App\Http\Controllers\WebLedgerStatementController::class, 'download'])
    ->whereIn('format', ['xlsx', 'pdf'])
    ->middleware(['web', \Filament\Http\Middleware\Authenticate::class, 'can:view,subscriber'])
    ->name('subscribers.ledger-statement.download');
