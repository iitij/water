/// Arabic date and greeting helpers.
///
/// The app ships without `intl` locale data for Arabic, so dates are composed
/// from fixed tables instead of `DateFormat('ar')` (which would throw unless
/// locale data were initialised at runtime).
abstract final class ArabicDate {
  const ArabicDate._();

  static const List<String> _weekdays = <String>[
    'الاثنين',
    'الثلاثاء',
    'الأربعاء',
    'الخميس',
    'الجمعة',
    'السبت',
    'الأحد',
  ];

  static const List<String> _months = <String>[
    'يناير',
    'فبراير',
    'مارس',
    'أبريل',
    'مايو',
    'يونيو',
    'يوليو',
    'أغسطس',
    'سبتمبر',
    'أكتوبر',
    'نوفمبر',
    'ديسمبر',
  ];

  static String weekday(DateTime value) => _weekdays[(value.weekday - 1).clamp(0, 6)];

  static String month(DateTime value) => _months[(value.month - 1).clamp(0, 11)];

  /// e.g. «الخميس 23 أكتوبر 2026».
  static String full(DateTime value) =>
      '${weekday(value)} ${value.day} ${month(value)} ${value.year}';

  /// e.g. «23 أكتوبر».
  static String short(DateTime value) => '${value.day} ${month(value)}';

  /// Time-of-day greeting.
  static String greeting([DateTime? now]) {
    final hour = (now ?? DateTime.now()).hour;
    if (hour < 12) return 'صباح الخير';
    if (hour < 17) return 'مساء النور';
    return 'مساء الخير';
  }

  static String partOfDay([DateTime? now]) {
    final hour = (now ?? DateTime.now()).hour;
    if (hour < 12) return 'صباح اليوم';
    if (hour < 17) return 'بعد الظهر';
    return 'مساء اليوم';
  }

  /// First name only, for compact greetings.
  static String firstName(String fullName) {
    final trimmed = fullName.trim();
    if (trimmed.isEmpty) return 'زميلنا';
    final parts = trimmed.split(RegExp(r'\s+'));
    return parts.first;
  }
}
