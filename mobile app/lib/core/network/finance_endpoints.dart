/// ثوابت مسارات الـ API الخاصة بالوحدة المالية (الفواتير/السندات/التعرفة).
///
/// ⚠️ ملاحظة تسليم (Handoff) للمهندس محمد (Dev 1): هذا الملف يُعرّف العقد الذي تستهلكه
/// وحدات `invoices`/`payments`/`tariffs`. عند دمج فرع `feat/mohammed-core-auth-api`
/// تُنقل هذه الثوابت إلى `lib/core/network/api_endpoints.dart` الموحّد (68 مساراً)
/// ويُحوَّل هذا الملف إلى `export` فقط — دون تغيير أسماء الحقول.
///
/// المرجع: `FLUTTER_MOBILE_MASTER_PLAN.md` §2.3 المجموعة 7/8/9 و§8.1.
class FinanceEndpoints {
  FinanceEndpoints._();

  // ─── المجموعة 7: الفواتير ───────────────────────────────────────────────
  static const String invoices = '/invoices';
  static const String invoicesUnpaid = '/invoices/unpaid';
  static const String invoicesSearch = '/invoices/search';
  static const String invoicesBillingPeriods = '/invoices/billing-periods';
  static const String invoicesGenerateMeter = '/invoices/generate-meter';
  static const String invoicesGenerateSubscription = '/invoices/generate-subscription';
  static const String invoicesGenerateTanker = '/invoices/generate-tanker';
  static const String invoicesBulkDelete = '/invoices/bulk-delete';
  static const String invoicesBulkRestore = '/invoices/bulk-restore';
  static const String invoicesBulkForceDelete = '/invoices/bulk-force-delete';

  /// ➕ مسار جديد مطلوب من Dev 1 (مطابق لـ `PaymentService::recalculateInvoices`).
  static const String invoicesBulkRecalculate = '/invoices/bulk-recalculate';

  static String invoice(int id) => '/invoices/$id';
  static String invoiceRestore(int id) => '/invoices/$id/restore';
  static String invoiceForce(int id) => '/invoices/$id/force';
  static String invoicePay(int id) => '/invoices/$id/pay';
  static String invoiceRecalculate(int id) => '/invoices/$id/recalculate';
  static String invoicePdf(int id) => '/invoices/$id/pdf';
  static String invoiceWhatsappLink(int id) => '/invoices/$id/whatsapp-link';
  static String invoiceSendWhatsapp(int id) => '/invoices/$id/send-whatsapp';

  // ─── المجموعة 8: المدفوعات وسندات القبض ─────────────────────────────────
  static const String payments = '/payments';
  static const String paymentsBulkDelete = '/payments/bulk-delete';
  static String payment(int id) => '/payments/$id';

  /// إلغاء سند بدل حذفه (خطة 2.0 — الفصل 5.3).
  static String paymentVoid(int id) => '/payments/$id/void';
  static String paymentPdf(int id) => '/payments/$id/pdf';
  static String paymentWhatsappLink(int id) => '/payments/$id/whatsapp-link';
  static String paymentSendWhatsapp(int id) => '/payments/$id/send-whatsapp';

  /// `GET /api/subscribers/{id}/unpaid-invoices-summary` — للتحصيل التلقائي الأحدث.
  static String subscriberDueSummary(int subscriberId) =>
      '/subscribers/$subscriberId/unpaid-invoices-summary';

  // ─── المجموعة 9: التعرفة ───────────────────────────────────────────────
  static const String tariffs = '/tariffs';
  static String tariff(int id) => '/tariffs/$id';
  static String tariffRestore(int id) => '/tariffs/$id/restore';
  static String tariffForce(int id) => '/tariffs/$id/force';

  // ─── مراجع لازمة لنوافذ الإصدار الثلاثية (ملك Dev 2/4 لكن تُستهلك للقراءة) ──
  static const String squares = '/squares';
  static const String subscribers = '/subscribers';
  static const String meters = '/meters';
  static const String subscriptionFees = '/subscription-fees';
  static const String serviceRequests = '/service-requests';

  // ─── الإعدادات التي تقرأها طبقة الطباعة/الواتساب (الكتابة ملك Dev 5) ────────
  static const String settings = '/settings';
  static const String settingsWhatsapp = '/settings/whatsapp';
}
