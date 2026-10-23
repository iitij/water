/// إعدادات التطبيق العامة (النواة — Dev 1). نسخة Dev 3 العقدية: تُستبدل عند الدمج.
///
/// المرجع: `FLUTTER_MOBILE_MASTER_PLAN.md` §3.1 (`core/config/app_config.dart`).
class AppConfig {
  const AppConfig({
    this.baseUrl = 'http://192.168.1.10:8000/api',
    this.pdfBaseUrl,
    this.connectTimeout = const Duration(seconds: 20),
    this.receiveTimeout = const Duration(seconds: 40),
    this.downloadTimeout = const Duration(seconds: 120),
    this.isOfflineEnabled = true,
  });

  /// أساس الـ API (Laravel + Sanctum) — يتوافق مع `routes/api.php` بدون بادئة `/api`
  /// لأن `bootstrap/app.php` يضيف البادئة تلقائياً.
  final String baseUrl;

  /// أساس روابط الطباعة إن اختلف (لنسخ الروابط في المتصفح).
  final String? pdfBaseUrl;

  final Duration connectTimeout;
  final Duration receiveTimeout;
  final Duration downloadTimeout;
  final bool isOfflineEnabled;

  /// مسار واتساب المحلي (Node/unofficial API) — الخطة §1.3 القاعدة 6.
  static const String localWhatsappSendUrl = 'http://localhost:3000/api/send';

  /// ملحق رابط PDF في لوحة Filament (`/invoices/{id}/print`, `/payments/{id}/print`)
  /// — يُستخدم فقط عند تعذر مسار الـ API.
  static String fallbackInvoicePrintPath(int id) => '/invoices/$id/print';
  static String fallbackReceiptPrintPath(int id) => '/payments/$id/print';
}
