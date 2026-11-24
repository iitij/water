<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuditEventController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CashSessionController;
use App\Http\Controllers\Api\DataQualityController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\ExportController as ApiExportController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\MeterController;
use App\Http\Controllers\Api\MobileDeveloperController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ReadingController;
use App\Http\Controllers\Api\ReadingFaultController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\RouteVisitController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\SquareController;
use App\Http\Controllers\Api\SubscriberController;
use App\Http\Controllers\Api\SystemLogController;
use App\Http\Controllers\Api\TariffController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WhatsAppServerController;
use Illuminate\Support\Facades\Route;

// The only unauthenticated application API route.
Route::post('/login', [AuthController::class, 'login'])->name('api.login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/developer/verify-pin', [MobileDeveloperController::class, 'verifyPin'])->middleware('throttle:5,1');
    Route::get('/developer/config', [MobileDeveloperController::class, 'config']);
    Route::post('/developer/mobile-logs', [MobileDeveloperController::class, 'storeLogs'])->middleware('throttle:10,1');
    // Authentication, current user and profile (legacy aliases remain available).
    Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');
    // تدوير التوكن عند أول 401 (خطة 2.0 — الفصل 9.1).
    Route::post('/refresh', [AuthController::class, 'refresh'])->name('api.refresh');
    Route::get('/me', [AuthController::class, 'me'])->name('api.me');
    Route::get('/user', [AuthController::class, 'user'])->name('api.user');
    Route::put('/profile', [AuthController::class, 'updateProfile'])->name('api.profile.update');
    Route::post('/profile', [AuthController::class, 'updateProfile'])->name('api.profile.update.legacy');

    // Global search across the resources the signed-in user may list.
    Route::get('/search', [SearchController::class, 'index'])->name('api.search');

    // Dashboard and reports.
    Route::get('/admin/dashboard', [AdminController::class, 'dashboard']);
    Route::get('/admin/stats', [AdminController::class, 'stats']);
    Route::get('/admin/widgets/monthly-revenue', [AdminController::class, 'monthlyRevenue']);
    Route::get('/admin/widgets/latest-invoices', [AdminController::class, 'latestInvoices']);
    Route::get('/admin/widgets/overdue-invoices', [AdminController::class, 'overdueInvoices']);
    Route::get('/admin/reports', [AdminController::class, 'reports']);

    // Squares.
    Route::post('/squares/bulk-delete', [SquareController::class, 'bulkDelete']);
    Route::get('/squares/{id}/offline-pack', [SquareController::class, 'offlinePack']);
    Route::apiResource('squares', SquareController::class)->parameters(['squares' => 'id']);

    // Subscribers: custom actions must precede the resource's /{id} route.
    Route::get('/subscribers/{id}/summary', [SubscriberController::class, 'summary']);
    Route::get('/subscribers/{id}/statement', [SubscriberController::class, 'statement']);
    Route::get('/subscribers/{id}/ledger-statement', [\App\Http\Controllers\Api\LedgerStatementController::class, 'show'])->whereNumber('id');
    Route::get('/subscribers/{id}/ledger-statement/xlsx', [\App\Http\Controllers\Api\LedgerStatementController::class, 'xlsx'])->whereNumber('id');
    Route::get('/subscribers/{id}/ledger-statement/pdf', [\App\Http\Controllers\Api\LedgerStatementController::class, 'pdf'])->whereNumber('id');
    Route::get('/subscribers/{id}/transactions', [SubscriberController::class, 'transactions']);
    Route::get('/subscribers/{id}/unpaid-invoices-summary', [SubscriberController::class, 'unpaidInvoicesSummary']);
    Route::get('/subscribers/{id}/allocation-reconciliation', [SubscriberController::class, 'allocationReconciliation'])->whereNumber('id');
    Route::post('/subscribers/{id}/collect-payment', [SubscriberController::class, 'collectPayment']);
    Route::post('/subscribers/bulk-delete', [SubscriberController::class, 'bulkDelete']);
    Route::apiResource('subscribers', SubscriberController::class)->parameters(['subscribers' => 'id']);

    // Meters and field reading support.
    Route::get('/meters/search', [MeterController::class, 'search']);
    Route::post('/meters/validate-path', [MeterController::class, 'validatePath']);
    Route::post('/meters/{id}/replace', [MeterController::class, 'replace']);
    Route::get('/meters/{id}/period', [ReadingController::class, 'getMeterPeriod']);
    Route::post('/meters/bulk-delete', [MeterController::class, 'bulkDelete']);
    Route::apiResource('meters', MeterController::class)->parameters(['meters' => 'id']);

    Route::get('/readings/meters', [MeterController::class, 'search']);
    Route::get('/readings/calculate-dates', [ReadingController::class, 'calculateDates']);
    Route::get('/readings/history/{meter_id}', [ReadingController::class, 'meterHistory']);
    Route::post('/readings/sync-batch', [ReadingController::class, 'syncBatch']);
    Route::post('/readings/bulk-invoice', [ReadingController::class, 'bulkInvoice']);
    Route::post('/readings/bulk-issue-invoices', [ReadingController::class, 'bulkInvoice']);
    Route::post('/readings/bulk-delete', [ReadingController::class, 'bulkDelete']);
    Route::get('/readings', [ReadingController::class, 'index']);
    Route::post('/readings', [ReadingController::class, 'store']);
    Route::put('/readings/{id}', [ReadingController::class, 'update']);
    Route::patch('/readings/{id}', [ReadingController::class, 'update']);
    Route::delete('/readings/{id}', [ReadingController::class, 'destroy']);

    // أعطال القراءة الميدانية (خطة 2.0): كانت محلية على الجهاز فقط — الآن تُرفع للخادم.
    Route::get('/readings/faults', [ReadingFaultController::class, 'index']);
    Route::post('/readings/faults', [ReadingFaultController::class, 'store']);
    Route::post('/readings/faults/sync-batch', [ReadingFaultController::class, 'syncBatch']);

    // زيارات جولة المسار (Route Mode) — ربط تقدّم الجولة بالخادم.
    Route::get('/route-visits', [RouteVisitController::class, 'index']);
    Route::post('/route-visits', [RouteVisitController::class, 'store']);
    Route::post('/route-visits/sync-batch', [RouteVisitController::class, 'syncBatch']);

    // Invoices. Keep static routes ahead of /invoices/{id}.
    Route::get('/invoices/unpaid', [InvoiceController::class, 'unpaid']);
    Route::get('/invoices/search', [InvoiceController::class, 'search']);
    Route::get('/invoices/billing-periods', [InvoiceController::class, 'billingPeriods']);
    Route::post('/invoices/pay', [InvoiceController::class, 'payFromPayload']);
    Route::post('/invoices/generate-meter', [InvoiceController::class, 'generateMeterInvoice']);
    Route::post('/invoices/bulk-recalculate', [InvoiceController::class, 'bulkRecalculate']);
    Route::post('/invoices/bulk-delete', [InvoiceController::class, 'bulkDelete']);
    Route::post('/invoices/bulk-restore', [InvoiceController::class, 'bulkRestore']);
    Route::post('/invoices/bulk-force-delete', [InvoiceController::class, 'bulkForceDelete']);
    Route::post('/invoices/{id}/correct-historical', [InvoiceController::class, 'correctHistorical'])->whereNumber('id');
    Route::post('/invoices/{id}/cancel', [InvoiceController::class, 'cancel']);
    Route::post('/invoices/{id}/restore', [InvoiceController::class, 'restore']);
    Route::delete('/invoices/{id}/force', [InvoiceController::class, 'forceDestroy']);
    Route::post('/invoices/{id}/recalculate', [InvoiceController::class, 'recalculate']);
    Route::post('/invoices/{id}/pay', [InvoiceController::class, 'pay']);
    Route::get('/invoices/{id}/pdf', [InvoiceController::class, 'downloadPdf'])->name('api.invoices.pdf');
    Route::get('/invoices/{id}/whatsapp-link', [InvoiceController::class, 'whatsappLink']);
    Route::post('/invoices/{id}/send-whatsapp', [InvoiceController::class, 'sendWhatsApp']);
    Route::post('/invoices/{id}/whatsapp', [InvoiceController::class, 'sendWhatsApp']);
    Route::apiResource('invoices', InvoiceController::class)->parameters(['invoices' => 'id']);

    // Payments / receipts.
    // إلغاء السند بدل حذفه — يجب أن يسبق مسار /payments/{id}.
    Route::post('/payments/{id}/void', [PaymentController::class, 'void'])->whereNumber('id');
    // اعتماد الإلغاءات الكبيرة (2.0 — الفصل 5.3): القائمة ثم القرار.
    Route::get('/payments/void-requests', [PaymentController::class, 'voidRequests']);
    Route::post('/payments/{id}/void-decision', [PaymentController::class, 'voidDecision'])->whereNumber('id');
    Route::post('/payments/bulk-delete', [PaymentController::class, 'bulkDelete']);
    Route::get('/payments/{id}/pdf', [PaymentController::class, 'downloadPdf'])->name('api.payments.pdf');
    Route::get('/payments/{id}/whatsapp-link', [PaymentController::class, 'whatsappLink']);
    Route::post('/payments/{id}/send-whatsapp', [PaymentController::class, 'sendWhatsApp']);
    Route::post('/payments/{id}/whatsapp', [PaymentController::class, 'sendWhatsApp']);
    Route::post('/payments/{id}/whatsapp-result', [PaymentController::class, 'recordWhatsAppResult'])->whereNumber('id');
    Route::post('/payments/{id}/refund', [PaymentController::class, 'recordRefund'])->whereNumber('id');
    Route::post('/payments/{id}/transfer-account', [PaymentController::class, 'transferAccount'])->whereNumber('id');
    Route::apiResource('payments', PaymentController::class)->parameters(['payments' => 'id']);


    // Tariffs, including soft-delete recovery.
    Route::post('/tariffs/bulk-delete', [TariffController::class, 'bulkDelete']);
    Route::post('/tariffs/bulk-restore', [TariffController::class, 'bulkRestore']);
    Route::post('/tariffs/bulk-force-delete', [TariffController::class, 'bulkForceDelete']);
    Route::post('/tariffs/{id}/restore', [TariffController::class, 'restore']);
    Route::delete('/tariffs/{id}/force', [TariffController::class, 'forceDestroy']);
    Route::apiResource('tariffs', TariffController::class)->parameters(['tariffs' => 'id']);

    // Read-only general ledger.
    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::get('/transactions/{id}', [TransactionController::class, 'show']);

    // Settings studio.
    Route::get('/settings/reading', [SettingController::class, 'reading']);
    Route::post('/settings/reading', [SettingController::class, 'updateReading']);
    Route::get('/settings/invoice', [SettingController::class, 'invoiceSettings']);
    Route::post('/settings/invoice', [SettingController::class, 'updateInvoiceSettings']);
    Route::get('/settings/receipt', [SettingController::class, 'receiptSettings']);
    Route::post('/settings/receipt', [SettingController::class, 'updateReceiptSettings']);
    Route::get('/settings/whatsapp', [SettingController::class, 'whatsappSettings']);
    Route::post('/settings/whatsapp', [SettingController::class, 'updateWhatsAppSettings']);
    Route::post('/settings/upload-logo', [SettingController::class, 'uploadLogo']);
    Route::get('/settings/items', [SettingController::class, 'items']);
    Route::post('/settings/items', [SettingController::class, 'storeItem']);
    Route::post('/settings/items/bulk-delete', [SettingController::class, 'bulkDestroyItems']);
    Route::put('/settings/items/{id}', [SettingController::class, 'updateItem']);
    Route::patch('/settings/items/{id}', [SettingController::class, 'updateItem']);
    Route::delete('/settings/items/{id}', [SettingController::class, 'destroyItem']);
    Route::get('/settings', [SettingController::class, 'index']);
    Route::post('/settings', [SettingController::class, 'update']);

    // Local WhatsApp bridge controls (server-to-server only; never call localhost from Flutter).
    Route::get('/whatsapp-server/status', [WhatsAppServerController::class, 'status']);
    Route::get('/whatsapp-server/qr', [WhatsAppServerController::class, 'qr']);
    Route::post('/whatsapp-server/start', [WhatsAppServerController::class, 'start']);
    Route::post('/whatsapp-server/stop', [WhatsAppServerController::class, 'stop']);
    Route::post('/whatsapp-server/logout', [WhatsAppServerController::class, 'logout']);
    Route::post('/whatsapp-server/test', [WhatsAppServerController::class, 'sendTest']);
    Route::post('/whatsapp-server/send-test', [WhatsAppServerController::class, 'sendTest']);
    // Names used in the earlier API gap report.
    Route::get('/whatsapp/local-status', [WhatsAppServerController::class, 'status']);
    Route::post('/whatsapp/local-start', [WhatsAppServerController::class, 'start']);
    Route::post('/whatsapp/local-stop', [WhatsAppServerController::class, 'stop']);
    // مسارات احتياطية كان يرسلها تطبيق الهاتف في سقوط المسارات الأساسية.
    Route::post('/whatsapp/local-logout', [WhatsAppServerController::class, 'logout']);
    Route::post('/whatsapp/local-send-test', [WhatsAppServerController::class, 'sendTest']);

    // Users and role/permission matrix.
    Route::get('/roles/permissions-matrix', [RoleController::class, 'permissionsMatrix']);
    Route::get('/permissions', [RoleController::class, 'permissionsMatrix']);
    Route::post('/users/bulk-delete', [UserController::class, 'bulkDelete']);
    Route::post('/users/{id}', [UserController::class, 'update'])->whereNumber('id');
    Route::apiResource('users', UserController::class)->parameters(['users' => 'id']);
    Route::post('/roles/bulk-delete', [RoleController::class, 'bulkDelete']);
    Route::apiResource('roles', RoleController::class)->parameters(['roles' => 'id']);

    // صندوق المحصّل: فتح/إغلاق/اعتماد/تقرير (خطة 2.0 — الفصل 5.2).
    Route::post('/late-receipt-reviews', [\App\Http\Controllers\Api\LateReceiptReviewController::class, 'store']);
    Route::get('/cash-sessions/{id}/late-receipt-reviews', [\App\Http\Controllers\Api\LateReceiptReviewController::class, 'index'])->whereNumber('id');
    Route::post('/late-receipt-reviews/{id}/decide', [\App\Http\Controllers\Api\LateReceiptReviewController::class, 'decide'])->whereNumber('id');
    Route::get('/cash-sessions/current', [CashSessionController::class, 'current']);
    Route::get('/cash-sessions/{id}/report', [CashSessionController::class, 'report'])->whereNumber('id');
    Route::get('/cash-sessions/{id}/report/pdf', [CashSessionController::class, 'downloadPdf'])->whereNumber('id');
    Route::post('/cash-sessions/{id}/close', [CashSessionController::class, 'close'])->whereNumber('id');
    Route::post('/cash-sessions/{id}/correct-duplicate', [CashSessionController::class, 'correctDuplicate'])->whereNumber('id');
    Route::post('/cash-sessions/{id}/approve', [CashSessionController::class, 'approve'])->whereNumber('id');
    Route::post('/cash-sessions/{id}/handovers', [CashSessionController::class, 'recordHandover'])->whereNumber('id');
    Route::get('/treasury/accounts', [\App\Http\Controllers\Api\TreasuryController::class, 'index']);
    Route::post('/treasury/accounts', [\App\Http\Controllers\Api\TreasuryController::class, 'store']);
    Route::get('/treasury/accounts/{id}', [\App\Http\Controllers\Api\TreasuryController::class, 'show'])->whereNumber('id');
    Route::post('/treasury/accounts/{id}/postings', [\App\Http\Controllers\Api\TreasuryController::class, 'post'])->whereNumber('id');
    Route::post('/treasury/accounts/{id}/fund-collector', [\App\Http\Controllers\Api\TreasuryController::class, 'fundCollector'])->whereNumber('id');
    Route::apiResource('cash-sessions', CashSessionController::class)
        ->only(['index', 'store', 'show'])
        ->parameters(['cash-sessions' => 'id']);

    // سجل التدقيق: قراءة للمشرف + رفع مجمّع من الأجهزة (خطة 2.0 — الفصل 9.6).
    Route::get('/audit-events', [AuditEventController::class, 'index']);
    Route::post('/audit-events', [AuditEventController::class, 'store']);

    // جودة البيانات: مشكلات قابلة للإصلاح (خطة 2.0 — الفصل 6.1 و10.2).
    Route::get('/data-quality/issues', [DataQualityController::class, 'index']);

    // أجهزة المستخدم وإبطالها (خطة 2.0 — الفصل 9.7).
    Route::get('/devices', [DeviceController::class, 'index']);
    Route::post('/devices', [DeviceController::class, 'store']);
    Route::delete('/devices/{id}', [DeviceController::class, 'destroy'])->whereNumber('id');

    // سجلات الـ API والخادم للتشخيص والدعم
    Route::get('/system/api-logs', [SystemLogController::class, 'index']);
    Route::delete('/system/api-logs', [SystemLogController::class, 'clear']);
    // تقارير أعطال تطبيق الهاتف (ErrorReporter → الخادم).
    Route::post('/system/error-reports', [SystemLogController::class, 'storeErrorReports']);

    // Authenticated XLSX exports.
    Route::get('/exports/summary', [ApiExportController::class, 'summary']);
    Route::get('/export/subscribers', [ApiExportController::class, 'subscribers']);
    Route::get('/export/invoices', [ApiExportController::class, 'invoices']);
    Route::get('/export/readings', [ApiExportController::class, 'readings']);
    Route::get('/exports/subscribers', [ApiExportController::class, 'subscribers']);
    Route::get('/exports/invoices', [ApiExportController::class, 'invoices']);
    Route::get('/exports/readings', [ApiExportController::class, 'readings']);
});
