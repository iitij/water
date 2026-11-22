import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/utils/date_period_calculator.dart';

void main() {
  group('DatePeriodCalculator: measured observation boundaries', () {
    test('uses baseline and actual reading day, not calendar month end', () {
      final result = DatePeriodCalculator.calculate(
        subscriptionStartDate: DateTime(2026, 1, 5),
        currentReadingDate: DateTime(2026, 2, 8),
        readingPeriod: 'monthly',
        readingDay: 'last_day',
      );
      expect(result.periodStart, DateTime(2026, 1, 5));
      expect(result.periodEnd, DateTime(2026, 2, 8));
    });

    test('uses previous actual observation instead of old period-end hint', () {
      final result = DatePeriodCalculator.calculate(
        lastReadingDate: DateTime(2026, 2, 5),
        lastReadingPeriodEnd: DateTime(2026, 1, 31),
        currentReadingDate: DateTime(2026, 3, 8),
      );
      expect(result.periodStart, DateTime(2026, 2, 5));
      expect(result.periodEnd, DateTime(2026, 3, 8));
    });

    test('uses installation baseline for the first reading', () {
      final result = DatePeriodCalculator.calculate(
        installedAt: DateTime(2026, 1, 12),
        subscriptionStartDate: DateTime(2025, 12, 1),
        currentReadingDate: DateTime(2026, 2, 17),
      );
      expect(result.periodStart, DateTime(2026, 1, 12));
    });

    test('requires a known first observation baseline', () {
      expect(
        () => DatePeriodCalculator.calculate(
          currentReadingDate: DateTime(2026, 2, 17),
        ),
        throwsStateError,
      );
    });

    test('works through year boundaries and leap days', () {
      final result = DatePeriodCalculator.calculate(
        lastReadingDate: DateTime(2023, 12, 30),
        currentReadingDate: DateTime(2024, 2, 29),
      );
      expect(result.periodStartIso, '2023-12-30');
      expect(result.periodEndIso, '2024-02-29');
    });

    test('monthly schedule is only a suggested visit day', () {
      final expected = DatePeriodCalculator.expectedVisitDate(
        DateTime(2025, 2, 1),
        readingDay: '31',
      );
      expect(expected, DateTime(2025, 2, 28));
      final next = DatePeriodCalculator.expectedVisitDate(
        DateTime(2025, 2, 28),
        readingDay: '31',
      );
      expect(next, DateTime(2025, 3, 31));
    });

    test('weekly and half-monthly scheduling never alter actual dates', () {
      expect(
        DatePeriodCalculator.expectedVisitDate(
          DateTime(2026, 5, 4),
          readingPeriod: 'weekly',
        ),
        DateTime(2026, 5, 11),
      );
      expect(
        DatePeriodCalculator.expectedVisitDate(
          DateTime(2026, 5, 4),
          readingPeriod: 'half_monthly',
        ),
        DateTime(2026, 5, 19),
      );
      final interval = DatePeriodCalculator.calculate(
        lastReadingDate: DateTime(2026, 5, 4),
        currentReadingDate: DateTime(2026, 5, 22),
        readingPeriod: 'weekly',
      );
      expect(interval.periodEnd, DateTime(2026, 5, 22));
    });
  });
}
