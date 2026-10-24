import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/sync/sync_retry_policy.dart';
import 'package:water_project/features/offline_sync/domain/sync_failure_planner.dart';
import 'package:water_project/features/offline_sync/domain/sync_queue_item.dart';

SyncQueueItem _item({int attempts = 1, SyncQueueStatus status = SyncQueueStatus.syncing}) =>
    SyncQueueItem(
      id: 'id-1',
      type: SyncQueueType.payment,
      payload: const <String, dynamic>{'amount': 100},
      status: status,
      createdAt: DateTime.utc(2026, 10, 24, 8),
      attempts: attempts,
      lastError: 'قديم',
    );

void main() {
  final now = DateTime.utc(2026, 10, 24, 9);

  group('planSyncFailure', () {
    test('a time-out keeps the row queued with a cooling period', () {
      final plan = planSyncFailure(attempts: 1, now: now);

      expect(plan.status, SyncQueueStatus.queued);
      expect(plan.retryAt, now.add(const Duration(seconds: 30)));
      expect(plan.errorCode, 'network');
      expect(plan.staysQueued, isTrue);
    });

    test('server errors back off further on every attempt', () {
      final first = planSyncFailure(attempts: 1, statusCode: 503, now: now);
      final second = planSyncFailure(attempts: 2, statusCode: 503, now: now);
      final fourth = planSyncFailure(attempts: 4, statusCode: 503, now: now);

      expect(first.retryAt, now.add(const Duration(seconds: 30)));
      expect(second.retryAt, now.add(const Duration(minutes: 2)));
      expect(fourth.retryAt, now.add(const Duration(minutes: 30)));
    });

    test('a rejected payload needs a human and never auto-retries', () {
      final plan = planSyncFailure(
        attempts: 1,
        statusCode: 422,
        serverMessage: 'المبلغ أكبر من المتبقي على الفاتورة.',
        now: now,
      );

      expect(plan.status, SyncQueueStatus.needsAttention);
      expect(plan.retryAt, isNull);
      expect(plan.message, 'المبلغ أكبر من المتبقي على الفاتورة.');
      expect(plan.needsAttention, isTrue);
    });

    test('rate limiting waits at least the policy floor', () {
      final plan = planSyncFailure(attempts: 1, statusCode: 429, now: now);

      expect(plan.status, SyncQueueStatus.queued);
      expect(
        plan.retryAt!.difference(now),
        greaterThanOrEqualTo(SyncRetryPolicy.rateLimitFloor),
      );
    });

    test('the last allowed attempt is reported as exhausted', () {
      const policy = SyncRetryPolicy();
      final plan = planSyncFailure(
        attempts: policy.maxAttempts,
        statusCode: 500,
        now: now,
        policy: policy,
      );

      expect(plan.status, SyncQueueStatus.failed);
      expect(plan.exhausted, isTrue);
      expect(plan.retryAt, isNull);
      expect(plan.message, contains('${policy.maxAttempts}'));
    });

    test('a 4xx is permanent even on the first attempt', () {
      for (final statusCode in <int>[400, 403, 404, 409]) {
        final plan = planSyncFailure(attempts: 1, statusCode: statusCode, now: now);
        expect(plan.status, SyncQueueStatus.needsAttention, reason: '$statusCode');
        expect(plan.errorCode, 'http_$statusCode');
      }
    });
  });

  group('applyFailurePlan', () {
    test('writes the plan back onto the queue item', () {
      final item = _item(attempts: 2);
      final plan = planSyncFailure(attempts: 2, statusCode: 503, now: now);

      final updated = applyFailurePlan(item, plan);

      expect(updated.status, SyncQueueStatus.queued);
      expect(updated.nextAttemptAt, now.add(const Duration(minutes: 2)));
      expect(updated.lastStatusCode, 503);
      expect(updated.lastError, isNot('قديم'));
      expect(updated.attempts, 2, reason: 'العدد يزيد في المدير قبل الإرسال');
    });

    test('a permanent failure clears any stale cooling period', () {
      final item = _item().copyWith(
        nextAttemptAt: now.add(const Duration(hours: 1)),
        lastStatusCode: 503,
      );

      final updated = applyFailurePlan(
        item,
        planSyncFailure(attempts: 1, statusCode: 422, now: now),
      );

      expect(updated.status, SyncQueueStatus.needsAttention);
      expect(updated.nextAttemptAt, isNull);
      expect(updated.lastStatusCode, 422);
    });
  });
}
