/// أدوات تحليل JSON من استجابات Laravel.
///
/// ضرورية هنا لأن حقول `decimal:2` في `Invoice`/`Payment`/`Tariff` تُسلسَل كنص
/// (`"1500.00"`) بينما تُسلسَل أحياناً كرقم حسب مصدر الاستجابة (Scout/Array cast).
class JsonUtils {
  JsonUtils._();

  static double asDouble(Object? value, {double fallback = 0}) {
    if (value == null) return fallback;
    if (value is num) return value.toDouble();
    if (value is String) return double.tryParse(value.replaceAll(',', '')) ?? fallback;
    return fallback;
  }

  static double? asDoubleOrNull(Object? value) {
    if (value == null) return null;
    if (value is num) return value.toDouble();
    if (value is String) return double.tryParse(value.replaceAll(',', ''));
    return null;
  }

  static int? asIntOrNull(Object? value) {
    if (value == null) return null;
    if (value is num) return value.toInt();
    if (value is String) return int.tryParse(value) ?? double.tryParse(value)?.toInt();
    return null;
  }

  static int asInt(Object? value, {int fallback = 0}) =>
      asIntOrNull(value) ?? fallback;

  static bool asBool(Object? value) =>
      value == true || value == 1 || value == '1' || value == 'true';

  static String asString(Object? value, {String fallback = ''}) {
    if (value == null) return fallback;
    if (value is String) return value;
    return value.toString();
  }

  static String? asStringOrNull(Object? value) {
    if (value == null) return null;
    final text = value is String ? value : value.toString();
    final trimmed = text.trim();
    return trimmed.isEmpty ? null : trimmed;
  }

  /// يقبل `2026-06-08`، `2026-06-08 14:22:01`، أو ISO8601.
  static DateTime? asDate(Object? value) {
    if (value == null) return null;
    if (value is DateTime) return value;
    final text = value.toString().trim();
    if (text.isEmpty) return null;
    return DateTime.tryParse(text.replaceFirst(' ', 'T'));
  }

  static DateTime asDateOrNow(Object? value) => asDate(value) ?? DateTime.now();

  static List<Map<String, dynamic>> asMapList(Object? value) {
    if (value is List) {
      return value
          .whereType<Map<dynamic, dynamic>>()
          .map((e) => e.cast<String, dynamic>())
          .toList();
    }
    return const <Map<String, dynamic>>[];
  }

  static Map<String, dynamic>? asMap(Object? value) {
    if (value is Map) return value.cast<String, dynamic>();
    return null;
  }

  /// يفهم كلاً من القائمة الخام ونتيجة `ResourceCollection` (`{data: [...]}`).
  static List<Map<String, dynamic>> unwrapList(Object? response) {
    if (response is List) {
      return response
          .whereType<Map<dynamic, dynamic>>()
          .map((e) => e.cast<String, dynamic>())
          .toList();
    }
    if (response is Map) {
      final data = response['data'];
      if (data is List) {
        return data
            .whereType<Map<dynamic, dynamic>>()
            .map((e) => e.cast<String, dynamic>())
            .toList();
      }
    }
    return const <Map<String, dynamic>>[];
  }

  static int? totalFromMeta(Map<String, dynamic> response) {
    final meta = response['meta'];
    if (meta is Map) return asIntOrNull(meta['total']);
    return null;
  }
}

/// تنسيق التواريخ المعتمد في كل شاشات الوحدة المالية (مطابق لـ `->date('Y-m-d')`).
class DateFormat {
  DateFormat._();

  static String day(DateTime? date) {
    if (date == null) return '-';
    final y = date.year.toString().padLeft(4, '0');
    final m = date.month.toString().padLeft(2, '0');
    final d = date.day.toString().padLeft(2, '0');
    return '$y-$m-$d';
  }

  static String dateTime(DateTime? date) {
    if (date == null) return '-';
    final h = date.hour.toString().padLeft(2, '0');
    final mi = date.minute.toString().padLeft(2, '0');
    return '${day(date)} $h:$mi';
  }

  static String range(DateTime? from, DateTime? to) {
    if (from == null && to == null) return '-';
    return '${day(from)} ← ${day(to)}';
  }

  /// يحوّل `YYYY-MM-DD` إلى كائن تاريخ لإرساله إلى Laravel.
  static String query(DateTime? date) => day(date);
}
