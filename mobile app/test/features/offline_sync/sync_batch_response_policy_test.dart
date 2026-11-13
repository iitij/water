import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/features/offline_sync/domain/sync_batch_response_policy.dart';
import 'package:water_project/features/offline_sync/domain/sync_failure_planner.dart';
import 'package:water_project/features/offline_sync/domain/sync_queue_item.dart';

void main() {
  test('retired meter rejection requires attention instead of infinite retry', () {
    const rejected = {
      'success': false,
      'retryable': false,
      'message': 'هذا العداد من السجل التاريخي وقد تم استبداله أو نزعه.',
    };
    expect(
      () => ensureSyncBatchItemAccepted(rejected),
      throwsA(isA<SyncBatchItemRejected>()
          .having((e) => e.statusCode, 'status code', 422)),
    );
    const error = SyncBatchItemRejected('عداد تم استبداله');
    final plan = planSyncFailure(
      attempts: 1,
      statusCode: error.statusCode,
      serverMessage: error.message,
    );
    expect(plan.status, SyncQueueStatus.needsAttention);
    expect(plan.retryAt, isNull);
    expect(plan.message, error.message);
  });

  test('temporary server error remains retryable', () {
    expect(
      () => ensureSyncBatchItemAccepted({
        'success': false,
        'retryable': true,
        'message': 'خطأ مؤقت',
      }),
      throwsA(isA<StateError>()),
    );
  });

  test('accepted reading is not rejected', () {
    expect(
      () => ensureSyncBatchItemAccepted({'success': true}),
      returnsNormally,
    );
  });
}
