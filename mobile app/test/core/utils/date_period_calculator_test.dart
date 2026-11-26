import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/utils/date_period_calculator.dart';

void main() {
  group('DatePeriodCalculator: readings define periods', () {
    test('last observation date opens next consumption interval', () {
      final interval = DatePeriodCalculator.calculate(
        lastReadingDate: DateTime(2026, 1, 10),
        now: DateTime(2026, 2, 12),
      );
      expect(interval.periodStart, DateTime(2026, 1, 10));
      expect(interval.periodEnd, DateTime(2026, 2, 12));
    });

    test('weekly and monthly expected schedules do not change interval dates', () {
      final monthly = DatePeriodCalculator.calculate(
        lastReadingDate: DateTime(2026, 1, 10),
        readingPeriod: 'monthly',
        now: DateTime(2026, 2, 12),
      );
      final weekly = DatePeriodCalculator.calculate(
        lastReadingDate: DateTime(2026, 1, 10),
        readingPeriod: 'weekly',
        now: DateTime(2026, 2, 12),
      );
      expect(weekly.periodStart, monthly.periodStart);
      expect(weekly.periodEnd, monthly.periodEnd);
    });

    test('the initial meter observation uses the installation date', () {
      final interval = DatePeriodCalculator.calculate(
        installedAt: DateTime(2026, 2, 3),
        subscriptionStartDate: DateTime(2026, 1, 1),
        now: DateTime(2026, 2, 12),
      );
      expect(interval.periodStart, DateTime(2026, 2, 3));
      expect(interval.periodEnd, DateTime(2026, 2, 12));
    });

    test('the service start date is used when installation date is missing', () {
      final interval = DatePeriodCalculator.calculate(
        subscriptionStartDate: DateTime(2026, 1, 1),
        now: DateTime(2026, 1, 12),
      );
      expect(interval.periodStart, DateTime(2026, 1, 1));
    });

    test('a reading cannot close before the previous observation', () {
      expect(() => DatePeriodCalculator.calculate(
        lastReadingDate: DateTime(2026, 2, 12),
        now: DateTime(2026, 2, 11),
      ), throwsStateError);
    });

    test('the first interval requires a real anchor', () {
      expect(() => DatePeriodCalculator.calculate(
        now: DateTime(2026, 2, 12),
      ), throwsStateError);
    });
  });
}
