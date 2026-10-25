import 'package:url_launcher/url_launcher.dart';

import '../error/failures.dart';
import '../finance/finance_api_client.dart';
import '../settings/finance_config.dart';
import '../utils/phone_formatter.dart';

/// معالج قوالب رسائل الواتساب — يطابق منطق `WhatsAppService::getInvoiceMessageUrl`
/// (`app/Services/WhatsAppService.php#L60-L186`): استبدال المتغيرات ثم معالجة
/// البلوكات الشرطية `{if:key}...{/if:key}` حسب قيمة كل متغير (`> 0`).
///
/// المرجع الهندسي: الخطة §7.1-3.
class WhatsAppTemplateProcessor {
  WhatsAppTemplateProcessor._();

  /// تطابق `WhatsAppService::formatPhoneNumber` عبر `PhoneFormatter`.
  static String? formatYemeniPhone(String? phone) =>
      PhoneFormatter.toWhatsappDigits(phone);

  /// 1) استبدال `{key}` بالقيم. 2) تفكيك البلوكات الشرطية. 3) trim.
  static String render({
    required String template,
    required Map<String, String> variables,
    Map<String, num> conditionalValues = const <String, num>{},
  }) {
    var result = template;

    variables.forEach((key, value) {
      result = result.replaceAll('{$key}', value);
    });

    conditionalValues.forEach((key, value) {
      final pattern =
          RegExp('\\{if:$key\\}(.*?)\\{/if:$key\\}', dotAll: true);
      if (value > 0) {
        result = result.replaceAllMapped(pattern, (m) => m.group(1) ?? '');
      } else {
        result = result.replaceAll(pattern, '');
      }
    });

    // إزالة أسطر فارغة مكررة نتجت عن بلوكات شرطية محذوفة.
    result = result.replaceAll(RegExp(r'\n{3,}'), '\n\n');
    return result.trim();
  }
}

/// النتيجة المنطقية لعملية الإرسال.
enum WhatsAppSendOutcome { launched, sentByApi, noPhone, disabled, failed }

class WhatsAppSendResult {
  const WhatsAppSendResult(this.outcome, {this.url, this.message, this.error});

  final WhatsAppSendOutcome outcome;
  final String? url;
  final String? message;
  final String? error;

  bool get isSuccess =>
      outcome == WhatsAppSendOutcome.launched ||
      outcome == WhatsAppSendOutcome.sentByApi;

  bool get ok => isSuccess;
}

/// payload محايد تبنيه طبقة الميزات (فواتير/سندات) ثم تُنفّذه النواة.
class WhatsAppPayload {
  const WhatsAppPayload({
    required this.kind,
    required this.recordId,
    required this.phone,
    required this.message,
    required this.linkPath,
    required this.sendPath,
    this.attachmentName,
  });

  /// `invoice` أو `payment` (يؤثر في تسمية الأزرار فقط).
  final String kind;
  final int recordId;

  /// رقم المستلم الخام (هاتف المستأجر له الأولوية — الخطة §7.1/§4.4).
  final String? phone;
  final String message;

  /// `GET /api/{kind}/{id}/whatsapp-link` — الرابط المحسوب في الخادم (الأدق).
  final String linkPath;

  /// `POST /api/{kind}/{id}/send-whatsapp` — للإرسال عبر meta_api/local_api.
  final String sendPath;
  final String? attachmentName;
}

/// محرّك إرسال الواتساب الثلاثي الوضع (`click_to_chat` / `meta_api` / `local_api`).
class WhatsAppLauncherService {
  const WhatsAppLauncherService({
    required FinanceApiClient client,
    required FinanceConfigRepository configRepository,
  })  : _client = client,
        _config = configRepository;

  final FinanceApiClient _client;
  final FinanceConfigRepository _config;

  /// يبني رابط `wa.me` محلياً من القالب المخزّن، وإن تعذّر يستخدم رابط الخادم.
  Future<WhatsAppSendResult> send(WhatsAppPayload payload) async {
    final config = await _config.refresh();
    if (!config.whatsappEnabled) {
      // حتى لو عُطّل الإشعار من الخادم، نبقى نسمح بالرابط اليدوي للمحصل؟
      // قرار Dev 3: زر الواتساب يظهر فقط عند التفعيل، وهنا حماية إضافية.
      return const WhatsAppSendResult(WhatsAppSendOutcome.disabled);
    }

    if (config.isApiSend) return _sendViaApi(payload);

    final phone = WhatsAppTemplateProcessor.formatYemeniPhone(payload.phone);
    if (phone == null) {
      return const WhatsAppSendResult(WhatsAppSendOutcome.noPhone);
    }

    var url = 'https://wa.me/$phone?text=${Uri.encodeQueryComponent(payload.message)}';

    // تفضيل رابط الخادم إن توفّر (يستطيع حساب القراءات والتفاصيل بدقة).
    try {
      final remote = await _client.getRaw(payload.linkPath);
      if (remote is Map && remote['url'] is String) {
        url = remote['url'] as String;
      }
    } on AppFailure {
      // أوفلاين → الرابط المحلي المولّد من نفس القالب.
    }

    final launched = await _launch(url);
    return launched
        ? WhatsAppSendResult(
            WhatsAppSendOutcome.launched,
            url: url,
            message: payload.message,
          )
        : WhatsAppSendResult(
            WhatsAppSendOutcome.noPhone,
            url: url,
            message: payload.message,
            error: 'تعذّر فتح تطبيق واتساب على هذا الجهاز.',
          );
  }

  /// إرسال سريع موحّد للمدخلات المباشرة
  Future<WhatsAppSendResult> dispatch({
    required String? recipientPhone,
    required String message,
    String? serverEndpoint,
    Map<String, dynamic>? payload,
  }) async {
    final endpoint = serverEndpoint ?? '';
    // وضع النقر إلى المحادثة يجلب الرابط من الخادم عبر مسار `whatsapp-link`
    // (GET) بينما الإرسال المباشر عبر API يستخدم `send-whatsapp` (POST) —
    // سابقاً كان الـ GET يُرسل على مسار POST فقط فيفشل بصمت ويُستخدم رابط محلي.
    final linkPath = endpoint.endsWith('/send-whatsapp')
        ? endpoint.replaceFirst('/send-whatsapp', '/whatsapp-link')
        : endpoint;
    return send(WhatsAppPayload(
      kind: 'message',
      recordId: (payload?['invoice_id'] as int?) ?? (payload?['reading_id'] as int?) ?? 0,
      phone: recipientPhone,
      message: message,
      linkPath: linkPath,
      sendPath: endpoint,
    ));
  }

  /// نص الرسالة فقط (لمعاينته قبل الإرسال أو نسخه).
  Future<String> previewMessage(WhatsAppPayload payload) async => payload.message;

  /// رابط `wa.me` محسوب محلياً (يُستخدم لنسخ الرابط أو المعاينة).
  String? buildLocalLink(WhatsAppPayload payload) {
    final phone = WhatsAppTemplateProcessor.formatYemeniPhone(payload.phone);
    if (phone == null) return null;
    return 'https://wa.me/$phone?text=${Uri.encodeQueryComponent(payload.message)}';
  }

  Future<WhatsAppSendResult> _sendViaApi(WhatsAppPayload payload) async {
    try {
      await _client.post(payload.sendPath);
      return const WhatsAppSendResult(WhatsAppSendOutcome.sentByApi);
    } on AppFailure catch (failure) {
      return WhatsAppSendResult(
        WhatsAppSendOutcome.failed,
        error: failure.message,
      );
    }
  }

  Future<bool> _launch(String url) async {
    final uri = Uri.tryParse(url);
    if (uri == null) return false;
    try {
      return await launchUrl(uri, mode: LaunchMode.externalApplication);
    } catch (_) {
      return false;
    }
  }
}
