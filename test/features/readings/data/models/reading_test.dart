import 'package:flutter_test/flutter_test.dart';
import 'package:water_mobile/features/readings/data/models/reading.dart';

void main() {
  group('Reading models', () {
    test('parses a server reading and its nested meter metadata', () {
      final reading = Reading.fromJson({
        'id': 33,
        'meter_id': 17,
        'reading_date': '2026-06-20',
        'actual_reading_date': '2026-06-19',
        'period_start': '2026-06-01',
        'period_end': '2026-06-20',
        'previous_reading': '100',
        'current_reading': '145.5',
        'consumption_units': '45.5',
        'is_invoiced': true,
        'invoice_id': 66,
        'meter': {
          'unique_code': 'WM-17',
          'path_number': 4,
          'subscriber': {
            'name': 'Subscriber One',
            'square_id': 3,
            'square': {'name': 'Square Three'},
          },
        },
      });

      expect(reading.id, 33);
      expect(reading.meterId, 17);
      expect(reading.previousReading, 100);
      expect(reading.currentReading, 145.5);
      expect(reading.consumptionUnits, 45.5);
      expect(reading.isInvoiced, isTrue);
      expect(reading.invoiceId, 66);
      expect(reading.meterCode, 'WM-17');
      expect(reading.pathNumber, 4);
      expect(reading.squareName, 'Square Three');
    });

    test('parses the meter-period response used by the reading form', () {
      final period = MeterPeriodResponse.fromJson({
        'last_reading': '100.25',
        'period_start': '2026-06-01',
        'period_end': '2026-06-30',
        'auto_issue_invoice': true,
        'has_subscription_fee': true,
        'subscription_installment': '50.00',
        'has_tanker_fee': true,
        'tanker_installment': 20,
      });

      expect(period.lastReading, 100.25);
      expect(period.periodStart, DateTime(2026, 6, 1));
      expect(period.periodEnd, DateTime(2026, 6, 30));
      expect(period.autoIssueInvoice, isTrue);
      expect(period.hasSubscriptionFee, isTrue);
      expect(period.subscriptionInstallment, 50);
      expect(period.hasTankerFee, isTrue);
      expect(period.tankerInstallment, 20);
    });
  });
}
