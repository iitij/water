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

/// Prevent another signed-in account from attempting a locally persisted action.
/// Legacy unowned rows may be adopted by an authenticated account, but actions
/// explicitly assigned to a different account must remain untouched until that
/// owner signs in again.
bool canSyncForUser(SyncQueueItem item, String? activeUserId) {
  if (activeUserId == null || activeUserId.isEmpty) return false;
  final ownerId = item.ownerUserId;
  return ownerId == null || ownerId.isEmpty || ownerId == activeUserId;
}
