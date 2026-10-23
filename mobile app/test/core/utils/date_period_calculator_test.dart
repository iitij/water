import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/utils/date_period_calculator.dart';

void main() {
  group('DatePeriodCalculator', () {
    test('uses the last day of month for the monthly last-day cycle', () {
      final result = DatePeriodCalculator.calculate(
        subscriptionStartDate: DateTime(2026, 2, 8),
        readingPeriod: 'monthly',
        readingDay: 'last_day',
        now: DateTime(2026, 2, 23),
      );

      expect(result.periodStart, DateTime(2026, 2, 8));
      expect(result.periodEnd, DateTime(2026, 2, 28));
    });

    test('starts the next period one day after a saved period end', () {
      final result = DatePeriodCalculator.calculate(
        lastReadingPeriodEnd: DateTime(2026, 1, 31),
        readingPeriod: 'monthly',
        readingDay: '20',
      );

      expect(result.periodStart, DateTime(2026, 2, 1));
      expect(result.periodEnd, DateTime(2026, 2, 20));
    });

    test('uses the next month when the monthly target day has passed', () {
      final result = DatePeriodCalculator.calculate(
        subscriptionStartDate: DateTime(2026, 1, 21),
        readingPeriod: 'monthly',
        readingDay: '20',
      );

      expect(result.periodEnd, DateTime(2026, 2, 20));
    });

    test('uses the end of the previous month for legacy readings', () {
      final result = DatePeriodCalculator.calculate(
        lastReadingDate: DateTime(2024, 2, 12),
        readingPeriod: 'weekly',
      );

      expect(result.periodStart, DateTime(2024, 3, 1));
      expect(result.periodEnd, DateTime(2024, 3, 7));
    });

    test('calculates half-month periods at day 15 and month end', () {
      final firstHalf = DatePeriodCalculator.calculate(
        subscriptionStartDate: DateTime(2026, 5, 2),
        readingPeriod: 'half_monthly',
      );
      final secondHalf = DatePeriodCalculator.calculate(
        subscriptionStartDate: DateTime(2026, 5, 16),
        readingPeriod: 'half_monthly',
      );

      expect(firstHalf.periodEnd, DateTime(2026, 5, 15));
      expect(secondHalf.periodEnd, DateTime(2026, 5, 31));
    });

    test('calculates ten-day periods and weeks', () {
      final tenDay = DatePeriodCalculator.calculate(
        subscriptionStartDate: DateTime(2026, 5, 11),
        readingPeriod: 'ten_days',
      );
      final lastTenDay = DatePeriodCalculator.calculate(
        subscriptionStartDate: DateTime(2026, 5, 21),
        readingPeriod: 'ten_days',
      );
      final weekly = DatePeriodCalculator.calculate(
        subscriptionStartDate: DateTime(2026, 5, 4),
        readingPeriod: 'weekly',
      );

      expect(tenDay.periodEnd, DateTime(2026, 5, 20));
      expect(lastTenDay.periodEnd, DateTime(2026, 5, 31));
      expect(weekly.periodEnd, DateTime(2026, 5, 10));
    });

    test('clamps target days to the actual month length', () {
      final result = DatePeriodCalculator.calculate(
        subscriptionStartDate: DateTime(2025, 2, 1),
        readingPeriod: 'monthly',
        readingDay: '31',
      );

      expect(result.periodEnd, DateTime(2025, 2, 28));
    });
  });
}
