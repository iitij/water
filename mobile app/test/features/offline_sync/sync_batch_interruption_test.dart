import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/features/offline_sync/domain/sync_batch.dart';
import 'package:water_project/features/offline_sync/domain/sync_failure_planner.dart';
import 'package:water_project/features/offline_sync/domain/sync_queue_item.dart';
import 'package:water_project/features/offline_sync/domain/sync_queue_order.dart';

/// S2-06 — انقطاع منتصف دفعة.
///
/// السيناريو الحقيقي في الميدان: المحصّل يسجّل 3 عمليات أوفلاين (سندان وقراءة)،
/// تُرسل الأولى بنجاح، ثم تنقطع الشبكة عند الثانية. المطلوب:
/// 1. لا تُفقد العملية التي انقطع عندها الإرسال ولا التي بعدها.
/// 2. العملية الفاشلة تنتظر تهدئة، **ولا تُعاد فوراً** في المحاولة التالية.
/// 3. ترتيب النقد يبقى محفوظاً بعد انقضاء التهدئة.
/// 4. 4xx لا يُعاد تلقائياً أبداً ويُرفع للتدخل البشري.
void main() {
  final t0 = DateTime.utc(2026, 10, 24, 9);

  SyncQueueItem item(
    String id, {
    required SyncQueueType type,
    required Duration offset,
    SyncQueueStatus status = SyncQueueStatus.queued,
    int attempts = 0,
    DateTime? nextAttemptAt,
    int? lastStatusCode,
  }) =>
      SyncQueueItem(
        id: id,
        type: type,
        payload: <String, dynamic>{'local_id': id},
        status: status,
        createdAt: t0.add(offset),
        attempts: attempts,
        nextAttemptAt: nextAttemptAt,
        lastStatusCode: lastStatusCode,
      );

  /// يقابل حلقة `SyncQueueManager.syncPending` لكن بلا شبكة: يرسل ما هو مؤهّل،
  /// ويوقف الدفعة عند أول فشل كما يفعل المدير فعلياً.
  ({List<String> sent, List<SyncQueueItem> pending}) runBatch(
    List<SyncQueueItem> queue,
    DateTime now, {
    required String failWith,
    int? statusCode,
    String? serverMessage,
  }) {
    final ordered = SyncQueueOrder.sortForSync(queue).toList(growable: true);
    final sent = <String>[];

    for (final candidate in eligibleForSync(ordered, now)) {
      if (candidate.id == failWith) {
        final index = ordered.indexWhere((entry) => entry.id == candidate.id);
        final failed = applyFailurePlan(
          candidate.copyWith(attempts: candidate.attempts + 1),
          planSyncFailure(
            attempts: candidate.attempts + 1,
            statusCode: statusCode,
            serverMessage: serverMessage,
            now: now,
          ),
        );
        ordered[index] = failed;
        break; // الانقطاع يوقف الدفعة: ما بعدها يبقى كما هو.
      }
      sent.add(candidate.id);
      ordered.removeWhere((entry) => entry.id == candidate.id);
    }

    return (sent: sent, pending: ordered);
  }

  test('السند الأول يُرسل، والثاني يفشل بانقطاع فيبقى في الطابور بتهدئة', () {
    final queue = <SyncQueueItem>[
      item('pay-1', type: SyncQueueType.payment, offset: Duration.zero),
      item('pay-2', type: SyncQueueType.payment, offset: const Duration(seconds: 1)),
      item('read-1', type: SyncQueueType.reading, offset: const Duration(seconds: 2)),
    ];

    final result = runBatch(
      queue,
      t0.add(const Duration(minutes: 1)),
      failWith: 'pay-2',
    );

    expect(result.sent, <String>['pay-1']);
    expect(result.pending.map((e) => e.id), containsAll(<String>['pay-2', 'read-1']));
  });

  test('العملية التي انقطع عندها لا تُعاد في المحاولة التالية قبل انقضاء التهدئة', () {
    final queue = <SyncQueueItem>[
      item('pay-2', type: SyncQueueType.payment, offset: Duration.zero),
      item('read-1', type: SyncQueueType.reading, offset: const Duration(seconds: 1)),
    ];
    final now = t0.add(const Duration(minutes: 1));

    final first = runBatch(queue, now, failWith: 'pay-2');
    final failed = first.pending.firstWhere((e) => e.id == 'pay-2');

    expect(failed.status, SyncQueueStatus.queued);
    expect(failed.nextAttemptAt, isNotNull);
    expect(failed.lastError, contains('الاتصال'));
    expect(
      eligibleForSync(first.pending, now).map((e) => e.id),
      isNot(contains('pay-2')),
      reason: 'التهدئة وُضعت لتوقف الحلقة، لا لتعطيل بقية الطابور',
    );
    // القراءة التي بعدها ما زالت مؤهّلة (لم تُستهلك محاولتها).
    expect(eligibleForSync(first.pending, now).map((e) => e.id), <String>['read-1']);
  });

  test('بعد انقضاء التهدئة يعود العنصر بنفس أولويته النقدية قبل القراءة', () {
    final now = t0.add(const Duration(minutes: 1));
    final first = runBatch(
      <SyncQueueItem>[
        item('pay-2', type: SyncQueueType.payment, offset: Duration.zero),
        item('read-1', type: SyncQueueType.reading, offset: const Duration(seconds: 5)),
      ],
      now,
      failWith: 'pay-2',
    );

    final failedAt = first.pending.firstWhere((e) => e.id == 'pay-2').nextAttemptAt!;
    final later = failedAt.add(const Duration(seconds: 1));

    final eligible = eligibleForSync(
      SyncQueueOrder.sortForSync(first.pending),
      later,
    );

    expect(eligible.map((e) => e.id), <String>['pay-2', 'read-1']);
  });

  test('رفض 4xx لا يُعاد تلقائياً ويُرفع للتدخل البشري', () {
    final now = t0.add(const Duration(minutes: 1));
    final result = runBatch(
      <SyncQueueItem>[
        item('pay-1', type: SyncQueueType.payment, offset: Duration.zero),
        item('pay-2', type: SyncQueueType.payment, offset: const Duration(seconds: 1)),
      ],
      now,
      failWith: 'pay-2',
      statusCode: 422,
      serverMessage: 'المبلغ يتجاوز المتبقي على الفاتورة',
    );

    final rejected = result.pending.firstWhere((e) => e.id == 'pay-2');
    expect(rejected.status, SyncQueueStatus.needsAttention);
    expect(rejected.nextAttemptAt, isNull);
    expect(rejected.lastStatusCode, 422);
    expect(eligibleForSync(result.pending, now), isEmpty);
    expect(
      eligibleForSync(result.pending, now.add(const Duration(days: 1))),
      isEmpty,
      reason: 'الخطأ الدائم لا يُصلحه الوقت — يحتاج تدخلاً بشرياً',
    );
  });

  test('استنفاد المحاولات ينتهي بحالة فشل نهائي لا بحلقة لا نهائية', () {
    var current = item('pay-9', type: SyncQueueType.payment, offset: Duration.zero);
    var now = t0;

    for (var attempt = 1; attempt <= 8; attempt++) {
      final failed = applyFailurePlan(
        current.copyWith(attempts: attempt, status: SyncQueueStatus.syncing),
        planSyncFailure(attempts: attempt, now: now),
      );
      current = failed;
      now = now.add(const Duration(hours: 7));
      if (current.status == SyncQueueStatus.failed) break;
    }

    expect(current.status, SyncQueueStatus.failed);
    expect(eligibleForSync(<SyncQueueItem>[current], now), isEmpty);
  });
}
