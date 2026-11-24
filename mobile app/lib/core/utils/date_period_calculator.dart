/// A real meter consumption interval closes when the next observation occurs.
/// Expected visits (monthly/weekly etc.) do not define the billed interval.
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

/// Mirrors the Laravel ReadingService for offline readings. The observation
/// date is the end of the interval, even when the visit is delayed.
class DatePeriodCalculator {
  const DatePeriodCalculator._();

  static ReadingPeriodResult calculate({
    DateTime? lastReadingPeriodEnd,
    DateTime? lastReadingDate,
    DateTime? subscriptionStartDate,
    DateTime? installedAt,
    String readingPeriod = 'monthly',
    String readingDay = 'last_day',
    DateTime? now,
  }) {
    final observation = _dateOnlyValue(now ?? DateTime.now());
    final baseline = lastReadingDate ?? installedAt ?? subscriptionStartDate;
    if (baseline == null) {
      throw StateError(
        'يلزم تاريخ تركيب العداد أو تاريخ بداية خدمة المشترك لتحديد الفترة الأولى.',
      );
    }
    final from = _dateOnlyValue(baseline);
    if (!observation.isAfter(from)) {
      throw StateError('تاريخ القراءة الحالية يجب أن يلي تاريخ القراءة السابقة.');
    }
    return ReadingPeriodResult(periodStart: from, periodEnd: observation);
  }

  static DateTime _dateOnlyValue(DateTime value) =>
      DateTime(value.year, value.month, value.day);
}
