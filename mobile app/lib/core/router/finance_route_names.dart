/// أسماء مسارات الوحدة المالية — تُضاف إلى `core/router/route_names.dart` عند الدمج.
class FinanceRouteNames {
  FinanceRouteNames._();

  /// `SCR-13` المحصل الميداني السريع (يُفتح مباشرة لمستخدم دور `collector`).
  static const String quickCollector = '/collector';

  /// `SCR-14` قائمة الفواتير الشاملة.
  static const String invoices = '/invoices';

  /// `SCR-15` تفاصيل الفاتورة.
  static const String invoiceDetail = '/invoices/:id';

  /// `SCR-16` نوافذ الإصدار (تُفتح كـ sheet أو مسار مستقل للتابلت).
  static const String invoiceGenerateMeter = '/invoices/generate/meter';
  static const String invoiceGenerateSubscription = '/invoices/generate/subscription';

  /// إنشاء/تعديل يدوي.
  static const String invoiceIssue = '/issue-invoice';
  static const String invoiceCreate = '/invoices/create';
  static const String invoiceEdit = '/invoices/:id/edit';

  /// `SCR-17` سجل سندات القبض.
  static const String payments = '/payments';

  /// `SCR-18` سند قبض جديد/تعديل.
  static const String paymentCreate = '/payments/create';
  static const String paymentEdit = '/payments/:id/edit';

  /// `SCR-21` شرائح التعرفة.
  static const String tariffs = '/tariffs';
}
