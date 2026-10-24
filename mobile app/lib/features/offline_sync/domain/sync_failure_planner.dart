import '../../../core/sync/sync_retry_policy.dart';
import 'sync_queue_item.dart';

/// قرار ما بعد فشل محاولة إرسال — دالة نقية بلا شبكة ولا مؤقتات.
///
/// الفصل بين «السياسة» (`SyncRetryPolicy`) و«الحالة المخزَّنة» هنا يسمح باختبار
/// كل الحالات بلا انتظار، وهو شرط بطاقتي S2-05 وS2-06 في الخطة.
class SyncFailurePlan {
  const SyncFailurePlan({
    required this.status,
    required this.message,
    required this.errorCode,
    this.retryAt,
    this.statusCode,
  });

  final SyncQueueStatus status;
  final String message;
  final String errorCode;
  final DateTime? retryAt;
  final int? statusCode;

  bool get staysQueued => status == SyncQueueStatus.queued;
  bool get needsAttention => status == SyncQueueStatus.needsAttention;
  bool get exhausted => status == SyncQueueStatus.failed;
}

/// يحوّل فشلاً واحداً إلى حالة صف جاهزة للحفظ.
///
/// [attempts] عدد المحاولات **بعد** الفشل الحالي.
SyncFailurePlan planSyncFailure({
  required int attempts,
  int? statusCode,
  String? serverMessage,
  DateTime? now,
  SyncRetryPolicy policy = const SyncRetryPolicy(),
}) {
  final decision = policy.decide(
    attempts: attempts,
    statusCode: statusCode,
    now: now,
    serverMessage: serverMessage,
  );

  final status = switch (decision.action) {
    SyncRetryAction.retryLater => SyncQueueStatus.queued,
    SyncRetryAction.needsAttention => SyncQueueStatus.needsAttention,
    SyncRetryAction.exhausted => SyncQueueStatus.failed,
  };

  return SyncFailurePlan(
    status: status,
    message: decision.message ?? SyncRetryPolicy.arabicReasonFor(statusCode),
    errorCode: decision.errorCode ?? 'unknown',
    retryAt: decision.nextAttemptAt,
    statusCode: statusCode,
  );
}

/// الصف بعد تطبيق القرار — يُحفظ كما هو في قاعدة البيانات.
SyncQueueItem applyFailurePlan(SyncQueueItem item, SyncFailurePlan plan) {
  return item.copyWith(
    status: plan.status,
    lastError: plan.message,
    lastStatusCode: plan.statusCode,
    clearStatusCode: plan.statusCode == null,
    nextAttemptAt: plan.retryAt,
    clearNextAttempt: plan.retryAt == null,
  );
}
