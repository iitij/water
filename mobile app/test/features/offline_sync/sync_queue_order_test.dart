import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/features/offline_sync/domain/sync_queue_item.dart';
import 'package:water_project/features/offline_sync/domain/sync_queue_order.dart';

SyncQueueItem _item({
  required String id,
  required SyncQueueType type,
  required DateTime createdAt,
  SyncQueueStatus status = SyncQueueStatus.queued,
}) =>
    SyncQueueItem(
      id: id,
      type: type,
      payload: const <String, dynamic>{},
      status: status,
      createdAt: createdAt,
    );

void main() {
  group('SyncQueueOrder.priorityOf', () {
    test('money always outranks readings', () {
      expect(
        SyncQueueOrder.priorityOf(SyncQueueType.payment),
        greaterThan(SyncQueueOrder.priorityOf(SyncQueueType.paymentVoid)),
      );
      expect(
        SyncQueueOrder.priorityOf(SyncQueueType.paymentVoid),
        greaterThan(SyncQueueOrder.priorityOf(SyncQueueType.cashSessionClose)),
      );
      expect(
        SyncQueueOrder.priorityOf(SyncQueueType.cashSessionClose),
        greaterThan(SyncQueueOrder.priorityOf(SyncQueueType.reading)),
      );
      expect(SyncQueueOrder.isMoney(SyncQueueType.reading), isFalse);
      expect(SyncQueueOrder.isMoney(SyncQueueType.cashSessionClose), isTrue);
    });
  });

  group('SyncQueueOrder.sortForSync', () {
    test('3 receipts and 10 readings ⇒ the receipts go first', () {
      final base = DateTime.utc(2026, 10, 24, 8);
      final items = <SyncQueueItem>[
        for (var i = 0; i < 10; i++)
          _item(
            id: 'reading-$i',
            type: SyncQueueType.reading,
            createdAt: base.add(Duration(minutes: i)),
          ),
        for (var i = 0; i < 3; i++)
          _item(
            id: 'payment-$i',
            type: SyncQueueType.payment,
            createdAt: base.add(Duration(hours: 1, minutes: i)),
          ),
      ];

      final ordered = SyncQueueOrder.sortForSync(items);

      expect(
        ordered.take(3).map((item) => item.id),
        <String>['payment-0', 'payment-1', 'payment-2'],
      );
      expect(
        ordered.skip(3).map((item) => item.id),
        List<String>.generate(10, (i) => 'reading-$i'),
      );
    });

    test('keeps FIFO inside a class even when dates are equal', () {
      final when = DateTime.utc(2026, 10, 24, 9);
      final ordered = SyncQueueOrder.sortForSync(<SyncQueueItem>[
        _item(id: 'b', type: SyncQueueType.paymentVoid, createdAt: when),
        _item(id: 'a', type: SyncQueueType.paymentVoid, createdAt: when),
      ]);

      expect(ordered.map((item) => item.id), <String>['a', 'b']);
    });

    test('void of a receipt is sent before closing the till', () {
      final base = DateTime.utc(2026, 10, 24);
      final ordered = SyncQueueOrder.sortForSync(<SyncQueueItem>[
        _item(
          id: 'close',
          type: SyncQueueType.cashSessionClose,
          createdAt: base,
        ),
        _item(
          id: 'void',
          type: SyncQueueType.paymentVoid,
          createdAt: base.add(const Duration(minutes: 5)),
        ),
      ]);

      expect(ordered.first.id, 'void');
    });

    test('only queued rows are auto-retryable', () {
      final when = DateTime.utc(2026, 10, 24);
      for (final status in SyncQueueStatus.values) {
        final item = _item(id: 'x', type: SyncQueueType.payment, createdAt: when, status: status);
        expect(
          SyncQueueOrder.isAutoRetryable(item),
          status == SyncQueueStatus.queued,
          reason: 'status $status',
        );
      }
    });
  });
}
