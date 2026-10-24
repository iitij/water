import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/sync/sync_retry_policy.dart';

void main() {
  const policy = SyncRetryPolicy();
  final now = DateTime.utc(2026, 10, 24, 9);

  group('SyncRetryPolicy', () {
    test('retries network failures with an exponential back-off', () {
      final first = policy.decide(attempts: 1, now: now);
      final second = policy.decide(attempts: 2, now: now);
      final third = policy.decide(attempts: 3, now: now);

      expect(first.shouldRetry, isTrue);
      expect(second.shouldRetry, isTrue);
      expect(first.nextAttemptAt, now.add(const Duration(seconds: 30)));
      expect(second.nextAttemptAt, now.add(const Duration(minutes: 2)));
      expect(third.nextAttemptAt, now.add(const Duration(minutes: 8)));
      expect(first.errorCode, 'network');
    });

    test('treats validation and permission errors as permanent', () {
      for (final status in <int>[400, 401, 403, 404, 409, 422]) {
        final decision = policy.decide(attempts: 1, statusCode: status, now: now);
        expect(decision.action, SyncRetryAction.needsAttention,
            reason: 'status $status must not be retried');
        expect(decision.errorCode, 'http_$status');
        expect(decision.nextAttemptAt, isNull);
      }
    });

    test('keeps retrying timeouts and rate limits', () {
      final timeout = policy.decide(attempts: 2, statusCode: 408, now: now);
      final limited = policy.decide(attempts: 1, statusCode: 429, now: now);

      expect(timeout.shouldRetry, isTrue);
      expect(limited.shouldRetry, isTrue);
      expect(
        limited.nextAttemptAt!.difference(now),
        greaterThanOrEqualTo(SyncRetryPolicy.rateLimitFloor),
      );
    });

    test('reports exhaustion instead of looping silently', () {
      final decision = policy.decide(
        attempts: policy.maxAttempts,
        statusCode: 503,
        now: now,
      );

      expect(decision.action, SyncRetryAction.exhausted);
      expect(decision.message, contains('${policy.maxAttempts}'));
      expect(decision.nextAttemptAt, isNull);
    });

    test('never crashes on attempts below one', () {
      final decision = policy.decide(attempts: 0, now: now);
      expect(decision.attempts, 1);
      expect(decision.shouldRetry, isTrue);
    });

    test('prefers the server message when it is available', () {
      final decision = policy.decide(
        attempts: 1,
        statusCode: 422,
        serverMessage: 'العداد المحدد لا يتبع هذا المشترك.',
        now: now,
      );
      expect(decision.message, 'العداد المحدد لا يتبع هذا المشترك.');
    });

    test('arabicReasonFor covers the status classes the app can meet', () {
      expect(SyncRetryPolicy.arabicReasonFor(409), contains('تعارض'));
      expect(SyncRetryPolicy.arabicReasonFor(503), contains('الخادم'));
      expect(SyncRetryPolicy.arabicReasonFor(null), contains('الاتصال'));
    });
  });
}
