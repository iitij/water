import 'dart:math' as math;

/// ما يجب فعله بعد فشل عملية في طابور المزامنة.
enum SyncRetryAction {
  /// تُعاد المحاولة لاحقاً بعد `nextAttemptAt`.
  retryLater,

  /// تحتاج تدخلاً بشرياً (بيانات خاطئة، صلاحية، رفض خادم) — لا تُعاد تلقائياً.
  needsAttention,

  /// استُنفدت المحاولات؛ تبقى ظاهرة للمستخدم مع سبب واضح.
  exhausted,
}

class SyncRetryDecision {
  const SyncRetryDecision({
    required this.action,
    required this.attempts,
    this.nextAttemptAt,
    this.errorCode,
    this.message,
  });

  final SyncRetryAction action;
  final int attempts;
  final DateTime? nextAttemptAt;
  final String? errorCode;
  final String? message;

  bool get shouldRetry => action == SyncRetryAction.retryLater;
  bool get needsAttention => action != SyncRetryAction.retryLater;

  @override
  String toString() =>
      'SyncRetryDecision($action, attempts: $attempts, next: $nextAttemptAt, '
      'code: $errorCode)';
}

/// سياسة إعادة المحاولة للطابور الأوفلاين.
///
/// القواعد (خطة الإصدار 2.0 — الفصل 8.2):
/// * أخطاء الشبكة و5xx: إعادة محاولة بتباعد أسّي.
/// * 4xx (عدا 408 و429): لا إعادة محاولة — البيانات أو الصلاحية تحتاج تدخلاً.
/// * 429: إعادة محاولة بعد مهلة أطول.
/// * بعد `maxAttempts` تصبح الحالة «استُنفدت» وتظهر للمستخدم بدل الدوران الصامت.
///
/// القاعدة الأساسية: `attempts` الواردة هي عدد المحاولات **بعد** الفشل الحالي،
/// أي 1 في أول فشل.
class SyncRetryPolicy {
  const SyncRetryPolicy({
    this.maxAttempts = 6,
    this.schedule = defaultSchedule,
  });

  static const List<Duration> defaultSchedule = <Duration>[
    Duration(seconds: 30),
    Duration(minutes: 2),
    Duration(minutes: 8),
    Duration(minutes: 30),
    Duration(hours: 2),
    Duration(hours: 6),
  ];

  /// الحد الأعلى لمحاولة 429 حتى لا نُغرق خادماً يعاني أصلاً.
  static const Duration rateLimitFloor = Duration(minutes: 2);

  final int maxAttempts;
  final List<Duration> schedule;

  SyncRetryDecision decide({
    required int attempts,
    int? statusCode,
    DateTime? now,
    String? serverMessage,
  }) {
    final safeAttempts = math.max(1, attempts);
    final decidedAt = now ?? DateTime.now().toUtc();

    if (_isPermanent(statusCode)) {
      return SyncRetryDecision(
        action: SyncRetryAction.needsAttention,
        attempts: safeAttempts,
        errorCode: 'http_$statusCode',
        message: serverMessage?.trim().isNotEmpty == true
            ? serverMessage!.trim()
            : arabicReasonFor(statusCode),
      );
    }

    if (safeAttempts >= maxAttempts) {
      return SyncRetryDecision(
        action: SyncRetryAction.exhausted,
        attempts: safeAttempts,
        errorCode: statusCode == null ? 'network_exhausted' : 'http_$statusCode',
        message: 'توقفت المحاولات التلقائية بعد $safeAttempts محاولات. '
            'راجع سبب الفشل ثم أعد المحاولة يدوياً.',
      );
    }

    final index = math.min(safeAttempts - 1, schedule.length - 1);
    var delay = schedule[index];
    if (statusCode == 429 && delay < rateLimitFloor) {
      delay = rateLimitFloor;
    }

    return SyncRetryDecision(
      action: SyncRetryAction.retryLater,
      attempts: safeAttempts,
      nextAttemptAt: decidedAt.add(delay),
      errorCode: statusCode == null ? 'network' : 'http_$statusCode',
      message: serverMessage ?? arabicReasonFor(statusCode),
    );
  }

  /// هل الخطأ دائم (لا يُصلحه تكرار المحاولة)؟
  static bool _isPermanent(int? statusCode) {
    if (statusCode == null) return false;
    if (statusCode == 408 || statusCode == 429) return false;
    return statusCode >= 400 && statusCode < 500;
  }

  /// رسالة عربية موحّدة لشاشة المزامنة.
  static String arabicReasonFor(int? statusCode) {
    switch (statusCode) {
      case 400:
        return 'بيانات العملية غير مقبولة من الخادم. راجع السجل قبل إعادة المحاولة.';
      case 401:
        return 'انتهت صلاحية الجلسة أثناء المزامنة.';
      case 403:
        return 'لا تملك صلاحية تنفيذ هذه العملية على الخادم.';
      case 404:
        return 'السجل المرتبط بهذه العملية لم يعد موجوداً على الخادم.';
      case 409:
        return 'تعارض في البيانات: السجل تغيّر على الخادم بعد حفظه على الجهاز.';
      case 422:
        return 'رفض الخادم البيانات المدخلة. صحّحها ثم أعد المحاولة.';
      case 429:
        return 'الخادم يستقبل طلبات كثيرة. ستُعاد المحاولة تلقائياً بعد قليل.';
      default:
        if (statusCode != null && statusCode >= 500) {
          return 'خطأ مؤقت في الخادم. ستبقى العملية محفوظة وتُعاد المحاولة.';
        }
        return 'تعذر الاتصال بالخادم. ستبقى العملية محفوظة على الجهاز.';
    }
  }
}
