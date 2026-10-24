/// API paths used by Dev 5 modules.
///
/// The detailed specification in chapter 2 is the primary contract. Fallbacks
/// cover the transitional paths listed in appendix 8.1 and are only attempted
/// when the server responds with 404 or 405.
abstract final class AdminApiEndpoints {
  static const dashboard = '/admin/dashboard';
  static const reports = '/admin/reports';

  static const readingSettings = '/settings/reading';
  static const invoiceSettings = '/settings/invoice';
  static const receiptSettings = '/settings/receipt';
  static const whatsappSettings = '/settings/whatsapp';
  static const settings = '/settings';
  static const settingsItems = '/settings/items';
  static const settingsItemsBulkDelete = '/settings/items/bulk-delete';
  static const uploadSettingImage = '/settings/upload-logo';

  static const whatsappLocalStatus = '/whatsapp/local-status';
  static const whatsappLocalStart = '/whatsapp/local-start';
  static const whatsappLocalStop = '/whatsapp/local-stop';
  static const whatsappLocalLogout = '/whatsapp/local-logout';
  static const whatsappLocalSendTest = '/whatsapp/local-send-test';
  static const whatsappServerStatus = '/whatsapp-server/status';
  static const whatsappServerStart = '/whatsapp-server/start';
  static const whatsappServerStop = '/whatsapp-server/stop';
  static const whatsappServerLogout = '/whatsapp-server/logout';
  static const whatsappServerSendTest = '/whatsapp-server/send-test';

  static const dataQualityIssues = '/data-quality/issues';

  static const devices = '/devices';
  static String device(int id) => '/devices/$id';

  static const users = '/users';
  static const usersBulkDelete = '/users/bulk-delete';
  static const roles = '/roles';
  static const rolesBulkDelete = '/roles/bulk-delete';
  static const permissionsMatrix = '/roles/permissions-matrix';
  static const permissions = '/permissions';

  static const squares = '/squares';
  static const exportsSummary = '/exports/summary';
  static const exportSubscribers = '/exports/subscribers';
  static const exportInvoices = '/exports/invoices';
  static const exportReadings = '/exports/readings';
  static const legacyExportSubscribers = '/export/subscribers';
  static const legacyExportInvoices = '/export/invoices';
  static const legacyExportReadings = '/export/readings';
}
