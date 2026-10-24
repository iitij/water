import 'sync_queue_item.dart';

/// ترتيب نفّاذية الطابور: النقد أولاً، ثم الأقدم أولاً داخل كل فئة.
///
/// سبب القاعدة (خطة 2.0 — الفصل 8.3): سند القبض أثره مالي وفوري، والقراءة
/// يمكن أن تنتظر دقائق بلا ضرر. لكن داخل الفئة الواحدة يبقى FIFO، لأن العمليات
/// قد تعتمد على ترتيبها (تعديل قراءة ثم فاتورتها).
abstract final class SyncQueueOrder {
  /// أولوية النوع: الأعلى يُرسل أولاً.
  static int priorityOf(SyncQueueType type) => switch (type) {
        SyncQueueType.payment => 100,
        SyncQueueType.paymentVoid => 90,
        SyncQueueType.cashSessionClose => 80,
        SyncQueueType.reading => 10,
      };

  static bool isMoney(SyncQueueType type) => type != SyncQueueType.reading;

  /// نسخة مرتّبة جاهزة للإرسال. لا تعتمد على حالة الصف (الفلترة مسؤولية المدير).
  static List<SyncQueueItem> sortForSync(Iterable<SyncQueueItem> items) {
    final ordered = items.toList(growable: false);
    ordered.sort((a, b) {
      final byPriority = priorityOf(b.type).compareTo(priorityOf(a.type));
      if (byPriority != 0) return byPriority;
      final byCreated = a.createdAt.compareTo(b.createdAt);
      if (byCreated != 0) return byCreated;
      return a.id.compareTo(b.id);
    });
    return ordered;
  }

  /// هل يسمح للمدير بمحاولة هذا الصف تلقائياً؟
  static bool isAutoRetryable(SyncQueueItem item) =>
      item.status == SyncQueueStatus.queued;
}
