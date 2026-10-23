import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import '../error/failures.dart';
import '../network/api_client.dart';
import '../network/finance_endpoints.dart';

/// إعدادات الدورة المالية والواتساب التي تحتاجها طبقة Dev 3.
///
/// المصدر: `GET /api/settings` (موجود فعلياً في [`SettingController@index`]) +
/// `GET /api/settings/whatsapp` (يضيفه Dev 1/Dev 5). القيم الافتراضية منسوخة
/// حرفياً من Laravel حتى يعمل التطبيق أوفلاين وتطابق الرسائل تماماً.
class FinanceConfig {
  const FinanceConfig({
    this.whatsappEnabled = false,
    this.whatsappMethod = 'click_to_chat',
    this.gracePeriodDays = 14,
    this.strictOverdueOnNewInvoice = false,
    this.projectName = 'مشروع مياه الوشاح',
    this.projectPhone = '1234567890',
    this.invoiceTemplate = defaultInvoiceTemplate,
    this.paymentTemplate = defaultPaymentTemplate,
    this.subscriptionInvoiceTemplate = defaultSubscriptionTemplate,
    this.tankerInvoiceTemplate = defaultTankerTemplate,
    this.receiptMessageTemplate = defaultPaymentTemplate,
    this.thermalPaperWidthMm = 58,
    this.thermalPrintLogo = false,
    this.autoSendWhatsappInvoice = false,
    this.autoSendWhatsappPayment = false,
  });

  final bool whatsappEnabled;

  /// `click_to_chat` | `meta_api` | `local_api` — الخطة §1.3 القاعدة 6.
  final String whatsappMethod;
  final int gracePeriodDays;
  final bool strictOverdueOnNewInvoice;
  final String projectName;
  final String projectPhone;

  /// قوالب رسائل واتساب (نفس مفاتيح `Setting`).
  final String invoiceTemplate;
  final String paymentTemplate;
  final String subscriptionInvoiceTemplate;
  final String tankerInvoiceTemplate;
  final String receiptMessageTemplate;

  /// إعدادات الطابعة الحرارية المحلية.
  final int thermalPaperWidthMm;
  final bool thermalPrintLogo;
  final bool autoSendWhatsappInvoice;
  final bool autoSendWhatsappPayment;

  bool get isClickToChat => whatsappMethod == 'click_to_chat';
  bool get isApiSend => whatsappMethod == 'meta_api' || whatsappMethod == 'local_api';

  static const String defaultInvoiceTemplate =
      'فاتورة استهلاك مياه\n'
      'الأخ:  {subscriber_name}،\n'
      'تم إصدار فاتورتك رقم (#{bill_number})\n'
      'للفترة :\n'
      'من {period_start} إلى {period_end}.\n'
      '\n'
      'تفاصيل القراءة والاستهلاك:\n'
      '• رقم العداد: {meter_code}\n'
      '• القراءة السابقة: {previous_reading}\n'
      '• القراءة الحالية: {current_reading}\n'
      '• كمية الاستهلاك: {consumption_units} وحدة\n'
      '(سعر الوحدة: {unit_price}) ريال\n'
      '\n'
      'تفاصيل الرسوم والحساب:\n'
      '• قيمة الاستهلاك: {consumption_fee} ريال\n'
      '{if:subscription_fee}• رسوم الاشتراك: {subscription_fee} ريال{/if:subscription_fee}\n'
      '{if:tanker_fee}• رسوم الوايت: {tanker_fee} ريال{/if:tanker_fee}\n'
      '{if:previous_balance}• متأخرات سابق: {previous_balance} ريال{/if:previous_balance}\n'
      '{if:credit_applied}• رصيد مخصوم: {credit_applied} ريال{/if:credit_applied}\n'
      '------------------------\n'
      'الإجمالي المستحق: {total_amount} ريال\n'
      '------------------------\n'
      'المحصل: {collector}\n'
      '\n'
      'لمعاينة وتحميل الفاتورة الرسمية (PDF):\n'
      '{pdf_url}\n'
      '\n'
      'شكراً لدفعكم في الموعد المحدد';

  static const String defaultPaymentTemplate =
      'سند قبض\n'
      'الأخ: {subscriber_name}،\n'
      'تم استلام مبلغ {amount} ريال بنجاح.\n'
      'رقم السند: {receipt_number}\n'
      'تاريخ الدفع: {date}\n'
      'طريقة الدفع: {payment_method}\n'
      '\n'
      'المتبقي من الفاتورة: {invoice_remaining} ريال\n'
      '{if:credit_balance}الرصيد الدائن: {credit_balance} ريال{/if:credit_balance}\n'
      '\n'
      'المحصل: {collector}\n'
      '\n'
      'للاطلاع على السند وتحميله (PDF):\n'
      '{pdf_url}\n'
      '\n'
      'شكراً لتعاملكم معنا';

  static const String defaultSubscriptionTemplate =
      'فاتورة رسوم اشتراك\n'
      'الأخ: {subscriber_name}،\n'
      'تم إصدار فاتورة رسوم اشتراك جديدة رقم (#{bill_number}).\n'
      '\n'
      'قيمة الرسوم: {subscription_fee} ريال\n'
      '{if:previous_balance}• رصيد متأخرات سابق: {previous_balance} ريال{/if:previous_balance}\n'
      '------------------------\n'
      'الإجمالي المستحق: {total_amount} ريال\n'
      '------------------------\n'
      'المحصل: {collector}\n'
      '\n'
      'للاطلاع على الفاتورة وتحميلها (PDF):\n'
      '{pdf_url}\n'
      '\n'
      'شكراً لتعاملكم معنا';

  static const String defaultTankerTemplate =
      'فاتورة رسوم وايت\n'
      'الأخ :{subscriber_name}،\n'
      'تم إصدار فاتورة رسوم وايت جديدة رقم (#{bill_number}).\n'
      '\n'
      'قيمة الرسوم: {tanker_fee} ريال\n'
      '{if:previous_balance}• رصيد متأخرات سابق: {previous_balance} ريال{/if:previous_balance}\n'
      '------------------------\n'
      'الإجمالي المستحق: {total_amount} ريال\n'
      '------------------------\n'
      'المحصل: {collector}\n'
      '\n'
      'للاطلاع على الفاتورة وتحميلها (PDF):\n'
      '{pdf_url}\n'
      '\n'
      'شكراً لتعاملكم معنا';

  FinanceConfig copyWith({
    bool? whatsappEnabled,
    String? whatsappMethod,
    int? gracePeriodDays,
    bool? strictOverdueOnNewInvoice,
    String? projectName,
    String? projectPhone,
    String? invoiceTemplate,
    String? paymentTemplate,
    int? thermalPaperWidthMm,
    bool? thermalPrintLogo,
    bool? autoSendWhatsappInvoice,
    bool? autoSendWhatsappPayment,
  }) =>
      FinanceConfig(
        whatsappEnabled: whatsappEnabled ?? this.whatsappEnabled,
        whatsappMethod: whatsappMethod ?? this.whatsappMethod,
        gracePeriodDays: gracePeriodDays ?? this.gracePeriodDays,
        strictOverdueOnNewInvoice:
            strictOverdueOnNewInvoice ?? this.strictOverdueOnNewInvoice,
        projectName: projectName ?? this.projectName,
        projectPhone: projectPhone ?? this.projectPhone,
        invoiceTemplate: invoiceTemplate ?? this.invoiceTemplate,
        paymentTemplate: paymentTemplate ?? this.paymentTemplate,
        subscriptionInvoiceTemplate: subscriptionInvoiceTemplate,
        tankerInvoiceTemplate: tankerInvoiceTemplate,
        receiptMessageTemplate: receiptMessageTemplate,
        thermalPaperWidthMm: thermalPaperWidthMm ?? this.thermalPaperWidthMm,
        thermalPrintLogo: thermalPrintLogo ?? this.thermalPrintLogo,
        autoSendWhatsappInvoice:
            autoSendWhatsappInvoice ?? this.autoSendWhatsappInvoice,
        autoSendWhatsappPayment:
            autoSendWhatsappPayment ?? this.autoSendWhatsappPayment,
      );

  static FinanceConfig fromJson(Map<String, dynamic> json) {
    final grace = int.tryParse('${json['grace_period'] ?? ''}');
    return FinanceConfig(
      whatsappEnabled: _bool(json['whatsapp_enabled']),
      whatsappMethod: (json['whatsapp_method'] as String?) ?? 'click_to_chat',
      gracePeriodDays: grace == null ? 14 : grace,
      strictOverdueOnNewInvoice: _bool(json['strict_overdue_on_new_invoice']),
      projectName: (json['invoice_project_name_ar'] as String?) ??
          'مشروع مياه الوشاح',
      projectPhone: (json['invoice_phone_1'] as String?) ?? '1234567890',
      invoiceTemplate: (json['reading_invoice_message_template'] as String?) ??
          defaultInvoiceTemplate,
      paymentTemplate: (json['payment_receipt_message_template'] as String?) ??
          defaultPaymentTemplate,
      subscriptionInvoiceTemplate:
          (json['subscription_invoice_template'] as String?) ??
              defaultSubscriptionTemplate,
      tankerInvoiceTemplate:
          (json['tanker_invoice_template'] as String?) ?? defaultTankerTemplate,
      receiptMessageTemplate:
          (json['payment_receipt_message_template'] as String?) ??
              defaultPaymentTemplate,
      thermalPaperWidthMm:
          int.tryParse('${json['thermal_paper_width'] ?? ''}') ?? 58,
      thermalPrintLogo: _bool(json['thermal_print_logo']),
      autoSendWhatsappInvoice: _bool(json['auto_send_whatsapp_invoice']),
      autoSendWhatsappPayment: _bool(json['auto_send_whatsapp_payment']),
    );
  }

  static bool _bool(Object? value) =>
      value == true || value == '1' || value == 1 || value == 'true';
}

/// مستودع الإعدادات: جلب + كاش محلي (Stale-While-Revalidate) ليعمل المحصل أوفلاين.
/// الكتابة ملك Dev 5 (`SCR-23`)، وهنا القراءة فقط.
class FinanceConfigRepository {
  FinanceConfigRepository({required DioClient client, required SharedPreferences prefs})
      : _client = client,
        _prefs = prefs;

  static const String _cacheKey = 'finance.config.cache';

  final DioClient _client;
  final SharedPreferences _prefs;
  FinanceConfig? _memory;

  FinanceConfig get cached => _memory ?? _readCache();

  /// يعيد الكاش فوراً ثم يحدّثه من الخادم في الخلفية.
  Future<FinanceConfig> refresh({bool force = false}) async {
    if (_memory != null && !force) return _memory!;
    try {
      final base = await _client.getRaw(FinanceEndpoints.settings);
      Map<String, dynamic> merged = <String, dynamic>{};
      if (base is Map) merged.addAll(base.cast<String, dynamic>());

      // `GET /api/settings/whatsapp` يضيفه Dev 1 — عند غيابه نكمل بالافتراضيات.
      try {
        final wa = await _client.getRaw(FinanceEndpoints.settingsWhatsapp);
        if (wa is Map) merged.addAll(wa.cast<String, dynamic>());
      } on AppFailure {
        // تجاهل: الإعدادات غير مكتملة على الخادم.
      }

      final config = FinanceConfig.fromJson(merged);
      _memory = config;
      await _prefs.setString(_cacheKey, jsonEncode(merged));
      return config;
    } on AppFailure {
      // أوفلاين: الكاش المحلي أو الافتراضيات.
      return _readCache();
    }
  }

  FinanceConfig _readCache() {
    if (_memory != null) return _memory!;
    final raw = _prefs.getString(_cacheKey);
    if (raw == null) return const FinanceConfig();
    try {
      final decoded = jsonDecode(raw);
      if (decoded is Map) {
        _memory = FinanceConfig.fromJson(decoded.cast<String, dynamic>());
        return _memory!;
      }
    } catch (_) {
      // كاش تالف → افتراضيات.
    }
    return const FinanceConfig();
  }

  void invalidate() => _memory = null;
}
