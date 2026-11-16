import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/features/readings/data/models/reading.dart';

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
      });

      expect(period.lastReading, 100.25);
      expect(period.periodStart, DateTime(2026, 6, 1));
      expect(period.periodEnd, DateTime(2026, 6, 30));
      expect(period.autoIssueInvoice, isTrue);
    });

    test('offline submission payload carries the sync UUID and consumption', () {
      const localId = '6f1c1f1e-8a1e-4c1f-9f7a-1c2d3e4f5a6b';
      final submission = ReadingSubmission(
        meterId: 17,
        readingDate: DateTime(2026, 6, 20),
        periodStart: DateTime(2026, 6, 1),
        periodEnd: DateTime(2026, 6, 30),
        previousReading: 100,
        currentReading: 145.5,
      );

      final payload = submission.toJson(localId: localId);

      expect(payload['sync_uuid'], localId);
      expect(payload['client_action_id'], localId);
      expect(payload['consumption_units'], 45.5);
      expect(payload['reading_date'], '2026-06-20');
      expect(payload['period_start'], '2026-06-01');
    });
  });
}
