import 'dart:async';
import 'dart:collection';

/// تقرير خطأ داخلي جاهز للعرض في شاشة الدعم أو لمزوّد مراقبة لاحقاً.
///
/// لا يحمل أي اسم مشترك أو رقم هاتف أو مبلغ: الرسائل تُمرَّر عبر [redact]
/// قبل التخزين، وأي مزوّد خارجي يُغلَّف خلف نفس الواجهة.
class ErrorReport {
  const ErrorReport({
    required this.message,
    required this.occurredAt,
    this.context,
    this.statusCode,
    this.endpoint,
    this.fatal = false,
  });

  final String message;
  final DateTime occurredAt;
  final String? context;
  final int? statusCode;
  final String? endpoint;
  final bool fatal;

  Map<String, dynamic> toJson() => <String, dynamic>{
        'message': message,
        'context': context,
        'status_code': statusCode,
        'endpoint': endpoint,
        'fatal': fatal,
        'occurred_at': occurredAt.toUtc().toIso8601String(),
      };

  @override
  String toString() =>
      '[${occurredAt.toIso8601String()}] ${context ?? 'app'}: $message'
      '${statusCode == null ? '' : ' ($statusCode)'}';
}

/// واجهة مزوّد المراقبة. التطبيق يعتمد عليها وحدها حتى يمكن تبديل المزوّد
/// (أو تعطيله كلياً) دون لمس بقية الكود.
abstract interface class ErrorReporter {
  void report(
    Object error, {
    StackTrace? stackTrace,
    String? context,
    int? statusCode,
    String? endpoint,
    bool fatal = false,
  });

  Future<void> flush();

  List<ErrorReport> get recent;
}

/// تقرير يعمل بلا شبكة ولا خدمة خارجية: حلقة محدودة تُعرض في شاشة التشخيص.
/// هذا هو الافتراضي في 2.0؛ ربط مزوّد خارجي يصبح قراراً منفصلاً (الفصل 12.5).
///
/// عند تمرير [uploader] يصبح `flush()` حقيقياً: يرفع التقارير غير المُرسلة
/// إلى الخادم (`POST /system/error-reports`) ويصفّرها عند النجاح — كانت
/// no-op سابقاً فلا يصل أي تقرير عطل للفريق أبداً.
class RecordingErrorReporter implements ErrorReporter {
  RecordingErrorReporter({this.maxEntries = 50, this.uploader});

  /// رفع دفعة تقارير إلى الخادم. يعيد عدد المقبوض أو يرمي عند الفشل.
  final Future<void> Function(List<ErrorReport> reports)? uploader;

  final int maxEntries;
  final Queue<ErrorReport> _entries = Queue<ErrorReport>();
  final List<ErrorReport> _pendingUpload = <ErrorReport>[];
  final StreamController<ErrorReport> _stream =
      StreamController<ErrorReport>.broadcast();

  Stream<ErrorReport> get stream => _stream.stream;

  @override
  List<ErrorReport> get recent => List<ErrorReport>.unmodifiable(_entries);

  @override
  void report(
    Object error, {
    StackTrace? stackTrace,
    String? context,
    int? statusCode,
    String? endpoint,
    bool fatal = false,
  }) {
    final report = ErrorReport(
      message: redact(_messageOf(error)),
      context: context,
      statusCode: statusCode,
      endpoint: endpoint,
      fatal: fatal,
      occurredAt: DateTime.now(),
    );
    _entries.addLast(report);
    while (_entries.length > maxEntries) {
      _entries.removeFirst();
    }
    if (uploader != null) {
      _pendingUpload.add(report);
      while (_pendingUpload.length > maxEntries) {
        _pendingUpload.removeAt(0);
      }
    }
    if (!_stream.isClosed) _stream.add(report);
  }

  @override
  Future<void> flush() async {
    final upload = uploader;
    if (upload == null || _pendingUpload.isEmpty) return;
    final batch = List<ErrorReport>.of(_pendingUpload);
    try {
      await upload(batch);
      _pendingUpload.clear();
    } catch (_) {
      // تعذّر الرفع (أوفلاين غالباً) — تبقى التقارير للمحاولة التالية.
    }
  }

  Future<void> dispose() => _stream.close();
}

/// مُبلّغ صامت للاختبارات أو للبناء الذي لا نريد فيه أي تسجيل.
class NoopErrorReporter implements ErrorReporter {
  const NoopErrorReporter();

  @override
  List<ErrorReport> get recent => const <ErrorReport>[];

  @override
  Future<void> flush() async {}

  @override
  void report(
    Object error, {
    StackTrace? stackTrace,
    String? context,
    int? statusCode,
    String? endpoint,
    bool fatal = false,
  }) {}
}

/// يُخفي الأرقام الطويلة (هواتف، مبالغ، أرقام سندات) من أي رسالة خطأ.
/// الهدف منع تسريب بيانات المشتركين إلى أي سجل أو خدمة خارجية.
String redact(String value) {
  var result = value.replaceAllMapped(
    RegExp(r'\d{5,}'),
    (match) => '#' * match.group(0)!.length,
  );
  result = result.replaceAll(RegExp(r'[\w.+-]+@[\w-]+\.[\w.]+'), '[email]');
  return result.length > 400 ? '${result.substring(0, 400)}…' : result;
}

String _messageOf(Object error) {
  final text = error.toString();
  return text.startsWith('Exception: ') ? text.substring(11) : text;
}
