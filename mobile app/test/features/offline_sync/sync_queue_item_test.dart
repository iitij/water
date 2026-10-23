import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/features/offline_sync/domain/sync_queue_item.dart';

void main() {
  group('SyncQueueItem', () {
    test('round-trips a reading action through JSON', () {
      const id = '6f1c1f1e-8a1e-4c1f-9f7a-1c2d3e4f5a6b';
      final item = SyncQueueItem(
        id: id,
        type: SyncQueueType.reading,
        payload: <String, dynamic>{
          'meter_id': 12,
          'current_reading': 145.5,
          'sync_uuid': id,
        },
        status: SyncQueueStatus.queued,
        createdAt: DateTime.utc(2026, 6, 20, 10, 30),
        ownerUserId: '7',
        attempts: 2,
      );

      final decoded = SyncQueueItem.fromJson(item.toJson());

      expect(decoded.id, id);
      expect(decoded.type, SyncQueueType.reading);
      expect(decoded.status, SyncQueueStatus.queued);
      expect(decoded.payload['meter_id'], 12);
      expect(decoded.ownerUserId, '7');
      expect(decoded.attempts, 2);
      expect(decoded.createdAt.toUtc(), DateTime.utc(2026, 6, 20, 10, 30));
    });

    test('falls back to safe defaults for malformed rows', () {
      final decoded = SyncQueueItem.fromJson(<String, dynamic>{
        'id': 'unknown',
        'type': 'not-a-type',
        'status': 'not-a-status',
        'attempts': 'oops',
      });

      expect(decoded.type, SyncQueueType.reading);
      expect(decoded.status, SyncQueueStatus.queued);
      expect(decoded.attempts, 0);
      expect(decoded.payload, isEmpty);
    });

    test('copyWith clears the last error only when requested', () {
      final item = SyncQueueItem(
        id: 'a',
        type: SyncQueueType.payment,
        payload: const <String, dynamic>{'amount': 100},
        status: SyncQueueStatus.failed,
        createdAt: DateTime.utc(2026, 1, 1),
        attempts: 3,
        lastError: 'network',
      );

      final retried = item.copyWith(
        status: SyncQueueStatus.queued,
        clearError: true,
      );
      expect(retried.status, SyncQueueStatus.queued);
      expect(retried.lastError, isNull);
      expect(retried.attempts, 3);
      expect(item.lastError, 'network');
    });

    test('encodes and decodes a list payload', () {
      final items = <SyncQueueItem>[
        SyncQueueItem(
          id: 'a',
          type: SyncQueueType.reading,
          payload: const <String, dynamic>{'meter_id': 1},
          status: SyncQueueStatus.queued,
          createdAt: DateTime.utc(2026, 1, 1),
        ),
        SyncQueueItem(
          id: 'b',
          type: SyncQueueType.payment,
          payload: const <String, dynamic>{'invoice_id': 9},
          status: SyncQueueStatus.failed,
          createdAt: DateTime.utc(2026, 1, 2),
          lastError: 'rejected',
        ),
      ];

      final raw = SyncQueueItem.encodeList(items);
      final restored = SyncQueueItem.decodeList(raw);

      expect(restored.length, 2);
      expect(restored.first.id, 'a');
      expect(restored.last.type, SyncQueueType.payment);
      expect(restored.last.lastError, 'rejected');
    });
  });
}
