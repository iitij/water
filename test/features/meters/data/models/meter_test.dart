import 'package:flutter_test/flutter_test.dart';
import 'package:water_mobile/features/meters/data/models/meter.dart';

void main() {
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
