/// A billing/reading period calculated locally when the API is unavailable.
class ReadingPeriodResult {
  const ReadingPeriodResult({
    required this.periodStart,
    required this.periodEnd,
  });

  final DateTime periodStart;
  final DateTime periodEnd;

  String get periodStartIso => _dateOnly(periodStart);
  String get periodEndIso => _dateOnly(periodEnd);

  static String _dateOnly(DateTime value) =>
      '${value.year.toString().padLeft(4, '0')}-'
      '${value.month.toString().padLeft(2, '0')}-'
      '${value.day.toString().padLeft(2, '0')}';
}

/// Mirrors `App\\Services\\ReadingService` for offline field reading.
///
/// `now` is injectable to keep the default period deterministic in tests.
class DatePeriodCalculator {
  const DatePeriodCalculator._();

  static ReadingPeriodResult calculate({
    DateTime? lastReadingPeriodEnd,
    DateTime? lastReadingDate,
    DateTime? subscriptionStartDate,
    String readingPeriod = 'monthly',
    String readingDay = 'last_day',
    DateTime? now,
  }) {
    final today = _dateOnlyValue(now ?? DateTime.now());
    late final DateTime periodStart;

    if (lastReadingPeriodEnd != null) {
      periodStart = _addCalendarDays(_dateOnlyValue(lastReadingPeriodEnd), 1);
    } else if (lastReadingDate != null) {
      final readingDate = _dateOnlyValue(lastReadingDate);
      periodStart = _addCalendarDays(_endOfMonth(readingDate), 1);
    } else if (subscriptionStartDate != null) {
      periodStart = _dateOnlyValue(subscriptionStartDate);
    } else {
      periodStart = DateTime(today.year, today.month, 1);
    }

    return ReadingPeriodResult(
      periodStart: periodStart,
      periodEnd: _calculateNextPeriodEnd(
        periodStart,
        readingPeriod,
        readingDay,
      ),
    );
  }

  static DateTime _calculateNextPeriodEnd(
    DateTime start,
    String period,
    String readingDay,
  ) {
    final endOfCurrentMonth = _endOfMonth(start);

    switch (period) {
      case 'monthly':
        if (readingDay == 'last_day') return endOfCurrentMonth;
        final parsedDay = int.tryParse(readingDay);
        final targetDay = (parsedDay ?? 28).clamp(1, 31).toInt();
        if (start.day <= targetDay) {
          return _dateInMonth(start.year, start.month, targetDay);
        }
        final nextMonth = DateTime(start.year, start.month + 1, 1);
        return _dateInMonth(nextMonth.year, nextMonth.month, targetDay);

      case 'half_monthly':
        return start.day <= 15 ? DateTime(start.year, start.month, 15) : endOfCurrentMonth;

      case 'ten_days':
        if (start.day <= 10) return DateTime(start.year, start.month, 10);
        if (start.day <= 20) return DateTime(start.year, start.month, 20);
        return endOfCurrentMonth;

      case 'weekly':
        return _addCalendarDays(start, 6);

      default:
        return endOfCurrentMonth;
    }
  }

  static DateTime _dateInMonth(int year, int month, int day) {
    final lastDay = _endOfMonth(DateTime(year, month, 1)).day;
    return DateTime(year, month, day.clamp(1, lastDay).toInt());
  }

  static DateTime _endOfMonth(DateTime value) =>
      DateTime(value.year, value.month + 1, 0);

  static DateTime _dateOnlyValue(DateTime value) =>
      DateTime(value.year, value.month, value.day);

  static DateTime _addCalendarDays(DateTime value, int days) =>
      DateTime(value.year, value.month, value.day + days);
}
