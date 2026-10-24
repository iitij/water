import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/features/offline_sync/data/drift_sync_queue_repository.dart';
import 'package:water_project/features/offline_sync/domain/sync_queue_item.dart';

void main() {
  group('wire action types', () {
    test('keeps the legacy reading/payment contract unchanged', () {
      expect(
        DriftSyncQueueRepository.wireActionType(
          SyncQueueType.reading,
          <String, dynamic>{},
        ),
        'reading.create',
      );
      expect(
        DriftSyncQueueRepository.wireActionType(
          SyncQueueType.payment,
          <String, dynamic>{'invoice_id': 12},
        ),
        'invoice.pay',
      );
      expect(
        DriftSyncQueueRepository.wireActionType(
          SyncQueueType.payment,
          <String, dynamic>{},
        ),
        'payment.create',
      );
    });

    test('names the 2.0 actions', () {
      expect(
        DriftSyncQueueRepository.wireActionType(
          SyncQueueType.paymentVoid,
          <String, dynamic>{},
        ),
        'payment.void',
      );
      expect(
        DriftSyncQueueRepository.wireActionType(
          SyncQueueType.cashSessionClose,
          <String, dynamic>{},
        ),
        'cash_session.close',
      );
    });

    test('round-trips every type through the parser', () {
      for (final type in SyncQueueType.values) {
        final wire = DriftSyncQueueRepository.wireActionType(type, <String, dynamic>{});
        expect(
          DriftSyncQueueRepository.parseActionType(wire),
          type,
          reason: '$wire must map back to $type',
        );
      }
    });

    test('a void is never mistaken for a collection', () {
      expect(
        DriftSyncQueueRepository.parseActionType('payment.void'),
        SyncQueueType.paymentVoid,
      );
      expect(
        DriftSyncQueueRepository.parseActionType('cash_session.close'),
        SyncQueueType.cashSessionClose,
      );
      expect(
        DriftSyncQueueRepository.parseActionType('payment.create'),
        SyncQueueType.payment,
      );
    });

    test('unknown and corrupt types fall back to a reading', () {
      for (final wire in <String>['', 'unknown', 'reading.create.v9']) {
        expect(DriftSyncQueueRepository.parseActionType(wire), SyncQueueType.reading);
      }
    });
  });

  group('wire states', () {
    test('maps statuses to the SQLite contract', () {
      expect(DriftSyncQueueRepository.wireState(SyncQueueStatus.queued), 'pending');
      expect(DriftSyncQueueRepository.wireState(SyncQueueStatus.syncing), 'syncing');
      expect(DriftSyncQueueRepository.wireState(SyncQueueStatus.failed), 'failed');
    });
  });

  group('retry metadata on queue items', () {
    test('survives JSON round-trip', () {
      final item = SyncQueueItem(
        id: 'a',
        type: SyncQueueType.paymentVoid,
        payload: const <String, dynamic>{'payment_id': 3, 'reason': 'سند على مشترك خطأ'},
        status: SyncQueueStatus.queued,
        createdAt: DateTime.utc(2026, 10, 24, 8),
        attempts: 2,
        lastError: 'خطأ مؤقت في الخادم.',
        nextAttemptAt: DateTime.utc(2026, 10, 24, 8, 8),
        lastStatusCode: 503,
      );

      final restored = SyncQueueItem.fromJson(item.toJson());

      expect(restored.nextAttemptAt, DateTime.utc(2026, 10, 24, 8, 8));
      expect(restored.lastStatusCode, 503);
      expect(restored.type, SyncQueueType.paymentVoid);
    });

    test('isReadyAt respects the cooling period', () {
      final item = SyncQueueItem(
        id: 'b',
        type: SyncQueueType.payment,
        payload: const <String, dynamic>{},
        status: SyncQueueStatus.queued,
        createdAt: DateTime.utc(2026, 10, 24),
        attempts: 1,
        nextAttemptAt: DateTime.utc(2026, 10, 24, 12),
      );

      expect(item.isReadyAt(DateTime.utc(2026, 10, 24, 11)), isFalse);
      expect(item.isReadyAt(DateTime.utc(2026, 10, 24, 12)), isTrue);
      expect(item.isReadyAt(DateTime.utc(2026, 10, 24, 13)), isTrue);
    });

    test('copyWith can clear the cooling period and the status code', () {
      final item = SyncQueueItem(
        id: 'c',
        type: SyncQueueType.payment,
        payload: const <String, dynamic>{},
        status: SyncQueueStatus.failed,
        createdAt: DateTime.utc(2026, 10, 24),
        attempts: 6,
        nextAttemptAt: DateTime.utc(2026, 10, 25),
        lastStatusCode: 422,
      );

      final retried = item.copyWith(
        status: SyncQueueStatus.queued,
        clearError: true,
        clearNextAttempt: true,
        clearStatusCode: true,
      );

      expect(retried.nextAttemptAt, isNull);
      expect(retried.lastStatusCode, isNull);
      expect(retried.lastError, isNull);
      expect(retried.status, SyncQueueStatus.queued);
    });
  });
}
