/// قراءة آمنة لاستجابات Laravel.
///
/// Laravel يعيد أعمدة `decimal` كنصوص (`"balance": "0.00"`) وقد يعيد المعرّفات
/// كنص أو رقم، والقوائم إما مصفوفة مباشرة أو مغلّفة بـ `{data: [...]}` (ترقيم).
/// أي `as num` / `as int` مباشر يرمي `TypeError` فيُعرض للمستخدم «حدث خطأ غير متوقع».
library;

double jDouble(Object? v, [double fallback = 0]) {
  if (v == null) return fallback;
  if (v is num) return v.toDouble();
  if (v is bool) return v ? 1 : 0;
  return double.tryParse(v.toString().replaceAll(',', '').trim()) ?? fallback;
}

double? jDoubleOrNull(Object? v) {
  if (v == null || (v is String && v.trim().isEmpty)) return null;
  return jDouble(v);
}

int jInt(Object? v, [int fallback = 0]) {
  if (v == null) return fallback;
  if (v is int) return v;
  if (v is num) return v.toInt();
  if (v is bool) return v ? 1 : 0;
  final s = v.toString().replaceAll(',', '').trim();
  return int.tryParse(s) ?? double.tryParse(s)?.toInt() ?? fallback;
}

int? jIntOrNull(Object? v) {
  if (v == null || (v is String && v.trim().isEmpty)) return null;
  final s = v is num ? v : num.tryParse(v.toString().trim());
  return s?.toInt();
}

String? jStr(Object? v) {
  if (v == null) return null;
  final s = v.toString();
  return s;
}

bool jBool(Object? v, [bool fallback = false]) {
  if (v == null) return fallback;
  if (v is bool) return v;
  if (v is num) return v != 0;
  switch (v.toString().trim().toLowerCase()) {
    case '1':
    case 'true':
    case 'yes':
      return true;
    case '0':
    case 'false':
    case 'no':
    case '':
      return false;
  }
  return fallback;
}

Map<String, dynamic> jMap(Object? v) {
  if (v is Map<String, dynamic>) return v;
  if (v is Map) return Map<String, dynamic>.from(v);
  return <String, dynamic>{};
}

Map<String, dynamic>? jMapOrNull(Object? v) => v is Map ? jMap(v) : null;

/// يقبل `[...]` أو `{data: [...]}` أو `{key: [...]}`.
List<Map<String, dynamic>> jMapList(Object? v, [List<String> keys = const []]) {
  Object? list = v;
  if (v is Map) {
    list = null;
    for (final k in [...keys, 'data', 'items']) {
      final c = v[k];
      if (c is List) {
        list = c;
        break;
      }
      if (c is Map && c['data'] is List) {
        list = c['data'];
        break;
      }
    }
  }
  if (list is! List) return const [];
  return list.whereType<Map>().map(jMap).toList();
}
