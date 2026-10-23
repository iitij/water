/// Relative Laravel API paths. `Dio.baseUrl` should already include `/api`.
abstract final class ApiEndpoints {
  static const meters = '/meters';
  static const meterSearch = '/meters/search';
  static String meterPeriod(int id) => '/meters/$id/period';
  static const squares = '/squares';
  static const subscribers = '/subscribers';
  static const readings = '/readings';
  static String reading(int id) => '/readings/$id';
  static const readingBulkInvoice = '/readings/bulk-invoice';
  static const readingBulkIssueInvoices = '/readings/bulk-issue-invoices';
  static const readingCalculateDates = '/readings/calculate-dates';
  static const readingBulkDelete = '/readings/bulk-delete';
  static const readingSyncBatch = '/readings/sync-batch';
  static String invoicePdf(int id) => '/invoices/$id/pdf';
}
