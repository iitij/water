/// Relative API paths. Keep paths slash-free so Dio preserves `/api/` in baseUrl.
class ApiEndpoints {
  const ApiEndpoints._();

  // Authentication and current user.
  static const login = 'login';
  static const refresh = 'refresh';
  static const logout = 'logout';
  static const me = 'me';
  static const legacyUser = 'user';
  static const profile = 'profile';

  // Global search across subscribers, invoices, meters, payments and squares.
  static const globalSearch = 'search';

  // Dashboard and reports.
  static const adminDashboard = 'admin/dashboard';
  static const adminStats = 'admin/stats';
  static const monthlyRevenue = 'admin/widgets/monthly-revenue';
  static const latestInvoices = 'admin/widgets/latest-invoices';
  static const overdueInvoices = 'admin/widgets/overdue-invoices';
  static const adminReports = 'admin/reports';

  // Squares and subscribers.
  static const squares = 'squares';
  static const bulkDeleteSquares = 'squares/bulk-delete';
  static String square(int id) => 'squares/$id';
  static String squareOfflinePack(int id) => 'squares/$id/offline-pack';

  static const subscribers = 'subscribers';
  static const bulkDeleteSubscribers = 'subscribers/bulk-delete';
  static String subscriber(int id) => 'subscribers/$id';
  static String subscriberSummary(int id) => 'subscribers/$id/summary';
  static String subscriberStatement(int id) => 'subscribers/$id/statement';
  static String subscriberTransactions(int id) => 'subscribers/$id/transactions';
  static String subscriberUnpaidInvoiceSummary(int id) => 'subscribers/$id/unpaid-invoices-summary';
  static String subscriberCollectPayment(int id) => 'subscribers/$id/collect-payment';

  // Meters and readings.
  static const meters = 'meters';
  static const meterSearch = 'meters/search';
  static const meterValidatePath = 'meters/validate-path';
  static const bulkDeleteMeters = 'meters/bulk-delete';
  static String meter(int id) => 'meters/$id';
  static String meterPeriod(int id) => 'meters/$id/period';

  static const readings = 'readings';
  static const readingMeters = 'readings/meters';
  static const readingCalculateDates = 'readings/calculate-dates';
  static const readingSyncBatch = 'readings/sync-batch';
  static const readingBulkInvoice = 'readings/bulk-invoice';
  static const readingBulkIssueInvoices = 'readings/bulk-issue-invoices';
  static const readingBulkDelete = 'readings/bulk-delete';
  static String reading(int id) => 'readings/$id';
  static String meterReadingHistory(int meterId) => 'readings/history/$meterId';

  // Invoices and payment collection.
  static const invoices = 'invoices';
  static const unpaidInvoices = 'invoices/unpaid';
  static const invoiceSearch = 'invoices/search';
  static const invoiceBillingPeriods = 'invoices/billing-periods';
  static const collectInvoicePayment = 'invoices/pay';
  static const generateMeterInvoice = 'invoices/generate-meter';
  static const bulkRecalculateInvoices = 'invoices/bulk-recalculate';
  static const bulkDeleteInvoices = 'invoices/bulk-delete';
  static String invoice(int id) => 'invoices/$id';
  static String restoreInvoice(int id) => 'invoices/$id/restore';
  static String recalculateInvoice(int id) => 'invoices/$id/recalculate';
  static String payInvoice(int id) => 'invoices/$id/pay';
  static String invoicePdf(int id) => 'invoices/$id/pdf';
  static String invoiceWhatsAppLink(int id) => 'invoices/$id/whatsapp-link';
  static String sendInvoiceWhatsApp(int id) => 'invoices/$id/send-whatsapp';

  // Payments.
  static const payments = 'payments';
  static const bulkDeletePayments = 'payments/bulk-delete';
  static String payment(int id) => 'payments/$id';
  static String paymentPdf(int id) => 'payments/$id/pdf';
  static String paymentWhatsAppLink(int id) => 'payments/$id/whatsapp-link';
  static String sendPaymentWhatsApp(int id) => 'payments/$id/send-whatsapp';
  static String voidPayment(int id) => 'payments/$id/void';

  // Collector cash sessions (صندوق المحصّل) and the audit trail.
  static const cashSessions = 'cash-sessions';
  static const currentCashSession = 'cash-sessions/current';
  static String cashSession(int id) => 'cash-sessions/$id';
  static String cashSessionReport(int id) => 'cash-sessions/$id/report';
  static String closeCashSession(int id) => 'cash-sessions/$id/close';
  static String approveCashSession(int id) => 'cash-sessions/$id/approve';
  static const auditEvents = 'audit-events';

  // Registered devices; deleting a device revokes its access.
  static const devices = 'devices';
  static String device(int id) => 'devices/$id';


  // Tariffs and general ledger.
  static const tariffs = 'tariffs';
  static const bulkDeleteTariffs = 'tariffs/bulk-delete';
  static String tariff(int id) => 'tariffs/$id';
  static String restoreTariff(int id) => 'tariffs/$id/restore';
  static String forceDeleteTariff(int id) => 'tariffs/$id/force';
  static const bulkRestoreTariffs = 'tariffs/bulk-restore';
  static const bulkForceDeleteTariffs = 'tariffs/bulk-force-delete';
  static const transactions = 'transactions';
  static String transaction(int id) => 'transactions/$id';

  // Settings and local WhatsApp bridge controls.
  static const settings = 'settings';
  static const readingSettings = 'settings/reading';
  static const invoiceSettings = 'settings/invoice';
  static const receiptSettings = 'settings/receipt';
  static const whatsAppSettings = 'settings/whatsapp';
  static const settingsUploadLogo = 'settings/upload-logo';
  static const settingItems = 'settings/items';
  static String settingItem(int id) => 'settings/items/$id';
  static const whatsAppServerStatus = 'whatsapp-server/status';
  static const whatsAppServerQr = 'whatsapp-server/qr';
  static const whatsAppServerStart = 'whatsapp-server/start';
  static const whatsAppServerStop = 'whatsapp-server/stop';
  static const whatsAppServerLogout = 'whatsapp-server/logout';
  static const whatsAppServerTest = 'whatsapp-server/test';
  static const legacyWhatsAppStatus = 'whatsapp/local-status';
  static const legacyWhatsAppStart = 'whatsapp/local-start';
  static const legacyWhatsAppStop = 'whatsapp/local-stop';

  // Users, roles, permissions, and exports.
  static const users = 'users';
  static const bulkDeleteUsers = 'users/bulk-delete';
  static String user(int id) => 'users/$id';
  static const roles = 'roles';
  static const bulkDeleteRoles = 'roles/bulk-delete';
  static const permissions = 'permissions';
  static const permissionsMatrix = 'roles/permissions-matrix';
  static String role(int id) => 'roles/$id';
  static const exportSummary = 'exports/summary';
  static const exportSubscribers = 'exports/subscribers';
  static const exportInvoices = 'exports/invoices';
  static const exportReadings = 'exports/readings';
}
