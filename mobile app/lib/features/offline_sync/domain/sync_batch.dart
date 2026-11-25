import 'sync_queue_item.dart';

/// قواعد اختيار ما يُرسل في دفعة المزامنة — دالة نقية مستخرجة من
/// `SyncQueueManager` لتُختبر وحدها.
///
/// ثلاث حالات لا تُرسَل تلقائياً:
/// * `syncing` — محاولة جارية بالفعل.
/// * `failed` — فشل نهائي (أو استُنفدت المحاولات)؛ يُعيدها المستخدم يدوياً.
/// * `needsAttention` — رفض 4xx (بيانات/صلاحية) لا يُصلحه التكرار.
///
/// ويُضاف عليها احترام مهلة التهدئة (`nextAttemptAt`) في لحظة `now`.
bool isSyncEligible(SyncQueueItem item, DateTime now) {
  if (item.status == SyncQueueStatus.syncing) return false;
  if (item.status == SyncQueueStatus.failed) return false;
  if (item.status == SyncQueueStatus.needsAttention) return false;
  return item.isReadyAt(now);
}

/// العناصر المؤهّلة الآن بنفس ترتيب القائمة المعطاة (الترتيب مسؤولية المتصل:
/// `SyncQueueOrder.sortForSync`).
List<SyncQueueItem> eligibleForSync(List<SyncQueueItem> items, DateTime now) =>
    items.where((item) => isSyncEligible(item, now)).toList(growable: false);

/// Unknown ownership is quarantined. Never infer it from the active session.
bool canSyncForUser(SyncQueueItem item, String? activeUserId, {String? server}) {
  if (activeUserId == null || activeUserId.isEmpty) return false;
  if (item.ownerUserId != activeUserId) return false;
  return server == null || (server.isNotEmpty && item.ownerServer == server);
}
