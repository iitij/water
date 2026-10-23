import 'dart:convert';

Map<String, dynamic> adminMap(dynamic value) {
  if (value is Map<String, dynamic>) return value;
  if (value is Map) return Map<String, dynamic>.from(value);
  return <String, dynamic>{};
}

/// Unwraps both plain Laravel JSON responses and `{data: ...}` envelopes.
dynamic adminUnwrap(dynamic value) {
  final map = adminMap(value);
  if (map.isEmpty) return value;
  if (map.containsKey('data')) return map['data'];
  return value;
}

Map<String, dynamic> adminDataMap(dynamic value) {
  final unwrapped = adminUnwrap(value);
  final map = adminMap(unwrapped);
  if (map.containsKey('settings')) return adminMap(map['settings']);
  return map;
}

List<dynamic> adminList(dynamic value, [List<String> keys = const []]) {
  final unwrapped = adminUnwrap(value);
  if (unwrapped is List) return unwrapped;
  final map = adminMap(unwrapped);
  for (final key in keys) {
    final candidate = map[key];
    if (candidate is List) return candidate;
    if (candidate is Map && candidate['data'] is List) {
      return candidate['data'] as List<dynamic>;
    }
  }
  if (map['data'] is List) return map['data'] as List<dynamic>;
  if (map['items'] is List) return map['items'] as List<dynamic>;
  return const [];
}

String adminString(dynamic value, [String fallback = '']) {
  if (value == null) return fallback;
  if (value is String) return value;
  return value.toString();
}

String? adminNullableString(dynamic value) {
  if (value == null) return null;
  final result = adminString(value).trim();
  return result.isEmpty ? null : result;
}

int adminInt(dynamic value, [int fallback = 0]) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  return int.tryParse(adminString(value).replaceAll(',', '')) ?? fallback;
}

double adminDouble(dynamic value, [double fallback = 0]) {
  if (value is double) return value;
  if (value is num) return value.toDouble();
  return double.tryParse(adminString(value).replaceAll(',', '')) ?? fallback;
}

double? adminNullableDouble(dynamic value) {
  if (value == null || value == '') return null;
  return adminDouble(value);
}

bool adminBool(dynamic value, [bool fallback = false]) {
  if (value is bool) return value;
  if (value is num) return value != 0;
  switch (adminString(value).trim().toLowerCase()) {
    case '1':
    case 'true':
    case 'yes':
    case 'on':
      return true;
    case '0':
    case 'false':
    case 'no':
    case 'off':
    case '':
      return false;
    default:
      return fallback;
  }
}

DateTime? adminDate(dynamic value) {
  if (value is DateTime) return value;
  final text = adminNullableString(value);
  if (text == null) return null;
  return DateTime.tryParse(text);
}

/// Accepts a data URI or a raw base64 image payload.
String adminBase64Payload(String value) {
  final trimmed = value.trim();
  final comma = trimmed.indexOf(',');
  if (trimmed.startsWith('data:') && comma >= 0) {
    return trimmed.substring(comma + 1);
  }
  return trimmed;
}

bool looksLikeBase64Image(String value) {
  final payload = adminBase64Payload(value);
  if (payload.isEmpty || payload.length % 4 != 0) return false;
  try {
    base64Decode(payload);
    return true;
  } on FormatException {
    return false;
  }
}
