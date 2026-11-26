import 'package:shared_preferences/shared_preferences.dart';

import '../../../core/field_operations/field_session_scope.dart';

import '../domain/reading_fault.dart';

/// سجل تعذّر القراءة على الجهاز.
///
/// مفتاح لكل يوم: `reading.faults.2026-10-24` وقيمة قائمة نصوص مفصولة بـ`|`. اخترنا يوماً
/// لكل مفتاح حتى لا ينمو ملف واحد بلا حد، وحتى يُقرأ تقرير الجولة بيوم واحد
/// فقط بلا فلترة مكلفة.
abstract interface class ReadingFaultLog {
  /// كل تعذّرات يوم معيّن (الأحدث أولاً).
  Future<List<ReadingFault>> forDay(DateTime day);

  /// تسجيل/تحديث تعذّر لمتر واحد في اليوم.
  Future<void> record(ReadingFault fault);

  /// حذف تعذّر (لو تبيّن أن القراءة تمّت لاحقاً).
  Future<void> clear({required int meterId, required DateTime day});
}

class SharedPreferencesReadingFaultLog implements ReadingFaultLog {
  SharedPreferencesReadingFaultLog({SharedPreferences? prefs, this.scope}) : _prefs = prefs;

  final FieldSessionScope? scope;
  String _ownedKey(DateTime day) => '${scope?.prefix ?? ''}${keyFor(day)}';

  Future<List<DateTime>> days() async {
    scope?.ensureCurrent();
    final store = await _store;
    scope?.ensureCurrent();
    final prefix = '${scope?.prefix ?? ''}reading.faults.';
    final result = <DateTime>[];
    for (final key in store.getKeys()) {
      if (!key.startsWith(prefix)) continue;
      final suffix = key.substring(prefix.length);
      final day = DateTime.tryParse(suffix);
      if (day != null && key == _ownedKey(day)) result.add(day);
    }
    return result..sort();
  }

  SharedPreferences? _prefs;

  Future<SharedPreferences> get _store async =>
      _prefs ??= await SharedPreferences.getInstance();

  /// `reading.faults.2026-10-24`
  static String keyFor(DateTime day) {
    final month = day.month.toString().padLeft(2, '0');
    final date = day.day.toString().padLeft(2, '0');
    return 'reading.faults.${day.year.toString().padLeft(4, '0')}-$month-$date';
  }

  @override
  Future<List<ReadingFault>> forDay(DateTime day) async {
    scope?.ensureCurrent();
    final store = await _store;
    scope?.ensureCurrent();
    final raw = store.getStringList(_ownedKey(day)) ?? const <String>[];
    final faults = <ReadingFault>[];
    for (final entry in raw) {
      final parsed = _decode(entry);
      if (parsed != null) faults.add(parsed);
    }
    faults.sort((a, b) => b.recordedAt.compareTo(a.recordedAt));
    return faults;
  }

  @override
  Future<void> record(ReadingFault fault) async {
    final day = fault.day;
    final existing = await forDay(day);
    final merged = <ReadingFault>[
      fault,
      ...existing.where((item) => item.meterId != fault.meterId),
    ];
    final store = await _store;
    scope?.ensureCurrent();
    final saved = await store.setStringList(
      _ownedKey(day),
      merged.map(_encode).toList(growable: false),
    );
    if (!saved) throw StateError('Unable to persist reading fault.');
  }

  @override
  Future<void> clear({required int meterId, required DateTime day}) async {
    final existing = await forDay(day);
    final kept = existing.where((item) => item.meterId != meterId).toList();
    final store = await _store;
    scope?.ensureCurrent();
    final saved = await store.setStringList(
      _ownedKey(day),
      kept.map(_encode).toList(growable: false),
    );
    if (!saved) throw StateError('Unable to persist reading fault.');
  }

  static String _encode(ReadingFault fault) {
    // صيغة بسيطة: `meterId|code|recordedAt|meterCode|subscriberName|squareId|note`
    // أخفّ من JSON وكل حقولها نصية/عددية، ونترك `note` آخراً لأنه الوحيد الذي قد
    // يحتوي على `|` فنستبدله بـ`/` عند الكتابة.
    return <String>[
      fault.meterId.toString(),
      fault.reason.code,
      fault.recordedAt.toIso8601String(),
      fault.meterCode ?? '',
      fault.subscriberName ?? '',
      fault.squareId?.toString() ?? '',
      (fault.note ?? '').replaceAll('|', '/'),
    ].join('|');
  }

  static ReadingFault? _decode(String raw) {
    final parts = raw.split('|');
    if (parts.length < 7) return null;
    final meterId = int.tryParse(parts[0]);
    final recordedAt = DateTime.tryParse(parts[2]);
    if (meterId == null || recordedAt == null) return null;
    return ReadingFault(
      meterId: meterId,
      reason: ReadingFaultReasonX.fromCode(parts[1]),
      recordedAt: recordedAt,
      meterCode: parts[3].isEmpty ? null : parts[3],
      subscriberName: parts[4].isEmpty ? null : parts[4],
      squareId: int.tryParse(parts[5]),
      note: parts[6].isEmpty ? null : parts[6],
    );
  }
}

/// نسخة في الذاكرة للاختبارات وللمعاينة بلا SharedPreferences.
class InMemoryReadingFaultLog implements ReadingFaultLog {
  InMemoryReadingFaultLog([List<ReadingFault> seed = const <ReadingFault>[]]) {
    for (final fault in seed) {
      _put(fault);
    }
  }

  final Map<String, List<ReadingFault>> _data = <String, List<ReadingFault>>{};

  void _put(ReadingFault fault) {
    final key = SharedPreferencesReadingFaultLog.keyFor(fault.day);
    final current = _data[key] ?? <ReadingFault>[];
    _data[key] = <ReadingFault>[fault, ...current.where((item) => item.meterId != fault.meterId)];
  }

  @override
  Future<List<ReadingFault>> forDay(DateTime day) async =>
      List<ReadingFault>.unmodifiable(_data[SharedPreferencesReadingFaultLog.keyFor(day)] ?? const <ReadingFault>[]);

  @override
  Future<void> record(ReadingFault fault) async => _put(fault);

  @override
  Future<void> clear({required int meterId, required DateTime day}) async {
    final key = SharedPreferencesReadingFaultLog.keyFor(day);
    _data[key] = (_data[key] ?? <ReadingFault>[]).where((item) => item.meterId != meterId).toList();
  }
}
