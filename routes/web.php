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
})->name('invoices.print')->middleware('web');

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
})->name('payments.print')->middleware('web');

// Export Routes (Protected by Filament Web Session)
Route::middleware(['web', \Filament\Http\Middleware\Authenticate::class])->prefix('admin/export')->group(function () {
    Route::get('/subscribers', [ExportController::class, 'exportSubscribers'])->name('admin.export.subscribers');
    Route::get('/invoices', [ExportController::class, 'exportInvoices'])->name('admin.export.invoices');
    Route::get('/readings', [ExportController::class, 'exportReadings'])->name('admin.export.readings');
});
