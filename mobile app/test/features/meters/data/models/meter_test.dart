import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/features/meters/data/models/meter.dart';

void main() {
  group('SubscriberOption.fromJson', () {
    test('recognizes a current meter while preserving historical meters', () {
      final subscriber = SubscriberOption.fromJson({
        'id': 12,
        'name': 'Subscriber',
        'meters_count': 2,
        'current_meter_id': 19,
        'meters': [
          {'id': 18, 'removed_at': '2026-10-01'},
          {'id': 19, 'removed_at': null},
        ],
      });
      expect(subscriber.currentMeterId, 19);
      expect(subscriber.hasCurrentMeter, isTrue);
    });

    test('derives the current meter from raw detail without picking old one', () {
      final subscriber = SubscriberOption.fromJson({
        'id': 13,
        'name': 'Subscriber',
        'meters': [
          {'id': 17, 'removed_at': '2026-10-01'},
          {'id': 25, 'removed_at': null},
        ],
      });
      expect(subscriber.currentMeterId, 25);
    });

    test('does not confuse retired-only history for current assignment', () {
      final subscriber = SubscriberOption.fromJson({
        'id': 14,
        'name': 'Subscriber',
        'meters_count': 1,
        'meters': [
          {'id': 17, 'removed_at': '2026-10-01'},
        ],
      });
      expect(subscriber.hasCurrentMeter, isFalse);
      expect(subscriber.currentMeterId, isNull);
    });
  });

  group('Meter.isCurrent', () {
    test('inactive meter still occupies subscriber slot until removed', () {
      final meter = Meter.fromJson({
        'id': 77,
        'unique_code': 'INACTIVE-CURRENT',
        'status': 'inactive',
        'removed_at': null,
      });
      expect(meter.isActive, isFalse);
      expect(meter.isCurrent, isTrue);
    });

    test('removed meter remains history but not operationally current', () {
      final meter = Meter.fromJson({
        'id': 78,
        'unique_code': 'OLD-METER',
        'status': 'inactive',
        'removed_at': '2026-10-01',
      });
      expect(meter.isCurrent, isFalse);
    });
  });

  group('Meter.fromJson', () {
    test('reads meter, square, tenant, balance, and related last reading', () {
      final meter = Meter.fromJson({
        'id': 17,
        'unique_code': 'WM-17',
        'path_number': '4',
        'balance': '-125.50',
        'tenant_name': 'Tenant One',
        'tenant_phone': '771234567',
        'last_reading': {'current_reading': '250.5'},
        'subscriber': {
          'id': 9,
          'name': 'Subscriber One',
          'square': {'id': 3, 'name': 'Square Three'},
          'subscription_start_date': '2026-01-10',
        },
      });

      expect(meter.id, 17);
      expect(meter.uniqueCode, 'WM-17');
      expect(meter.pathNumber, 4);
      expect(meter.squareId, 3);
      expect(meter.squareName, 'Square Three');
      expect(meter.subscriberId, 9);
      expect(meter.subscriberName, 'Subscriber One');
      expect(meter.subscriptionStartDate, DateTime(2026, 1, 10));
      expect(meter.balance, -125.5);
      expect(meter.lastReading, 250.5);
      expect(meter.tenantName, 'Tenant One');
    });
  });
}
