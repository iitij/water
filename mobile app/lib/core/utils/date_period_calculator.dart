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

  /// Consumption period: (previous observation date, current observation date].
  /// Legacy scheduled dates are a last-resort offline-cache fallback only.
  static ReadingPeriodResult calculate({
    DateTime? currentReadingDate,
    DateTime? lastReadingPeriodEnd,
    DateTime? lastReadingDate,
    DateTime? installedAt,
    DateTime? subscriptionStartDate,
    String readingPeriod = 'monthly',
    String readingDay = 'last_day',
    DateTime? now,
  }) {
    final baseline = lastReadingDate ?? lastReadingPeriodEnd ??
        installedAt ?? subscriptionStartDate;
    if (baseline == null) {
      throw StateError('لا يوجد تاريخ مرجعي للعداد. حدّد تاريخ التركيب أو بداية الخدمة قبل إدخال القراءة.');
    }
    return ReadingPeriodResult(
      periodStart: _dateOnlyValue(baseline),
      periodEnd: _dateOnlyValue(currentReadingDate ?? now ?? DateTime.now()),
    );
  }

  /// A separate scheduling hint: it never sets the billing period end.
  static DateTime expectedVisitDate(
    DateTime previous, {
    String readingPeriod = 'monthly',
    String readingDay = 'last_day',
  }) => _calculateNextPeriodEnd(
    _dateOnlyValue(previous), readingPeriod, readingDay,
  );

  static DateTime _calculateNextPeriodEnd(
    DateTime start,
    String period,
    String readingDay,
  ) {
    final endOfCurrentMonth = _endOfMonth(start);

    switch (period) {
      case 'monthly':
        final parsedDay = int.tryParse(readingDay);
        final targetDay = readingDay == 'last_day'
            ? endOfCurrentMonth.day
            : (parsedDay ?? 28).clamp(1, 31).toInt();
        final candidate = _dateInMonth(start.year, start.month, targetDay);
        if (candidate.isAfter(start)) return candidate;
        final nextMonth = DateTime(start.year, start.month + 1, 1);
        return readingDay == 'last_day'
            ? _endOfMonth(nextMonth)
            : _dateInMonth(nextMonth.year, nextMonth.month, targetDay);

      case 'half_monthly':
        return _addCalendarDays(start, 15);

      case 'ten_days':
        return _addCalendarDays(start, 10);

      case 'weekly':
        return _addCalendarDays(start, 7);

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
