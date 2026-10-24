import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import '../domain/local_audit_event.dart';

/// مخزن أحداث التدقيق المعلّقة (لم تُرفع بعد).
///
/// بسيط ومقصود: قائمة JSON في `SharedPreferences` بدل جدول Drift، لأن السجل
/// مساعد للتدقيق ولا يجوز أن يفشل فتح قاعدة البيانات بسببه.
abstract interface class AuditEventStore {
  Future<List<LocalAuditEvent>> pending();

  Future<void> save(List<LocalAuditEvent> events);
}

/// تنفيذ الإنتاج: يحتفظ بآخر [maxEvents] حدثاً فقط لمنع تضخّم التخزين على
/// جهاز ميداني قد يبقى بلا شبكة أسبوعاً.
class SharedPreferencesAuditStore implements AuditEventStore {
  SharedPreferencesAuditStore({SharedPreferences? prefs, this.maxEvents = 300})
      : _prefs = prefs;

  static const String storageKey = 'audit.pending.events';

  final int maxEvents;
  SharedPreferences? _prefs;

  Future<SharedPreferences> get _store async =>
      _prefs ??= await SharedPreferences.getInstance();

  @override
  Future<List<LocalAuditEvent>> pending() async {
    final raw = (await _store).getString(storageKey);
    if (raw == null || raw.isEmpty) return <LocalAuditEvent>[];
    try {
      final decoded = jsonDecode(raw);
      if (decoded is! List) return <LocalAuditEvent>[];
      return decoded
          .whereType<Map<Object?, Object?>>()
          .map((row) => LocalAuditEvent.fromJson(Map<String, dynamic>.from(row)))
          .where((event) => event.event.isNotEmpty)
          .toList(growable: true);
    } on FormatException {
      // صف تالف واحد لا يجوز أن يمنع بقية السجل من الرفع.
      return <LocalAuditEvent>[];
    }
  }

  @override
  Future<void> save(List<LocalAuditEvent> events) async {
    final bounded = events.length <= maxEvents
        ? events
        : events.sublist(events.length - maxEvents);
    await (await _store).setString(
      storageKey,
      jsonEncode(bounded.map((event) => event.toJson()).toList(growable: false)),
    );
  }
}

/// تنفيذ في الذاكرة للاختبارات وحدها.
class InMemoryAuditStore implements AuditEventStore {
  InMemoryAuditStore([List<LocalAuditEvent>? initial])
      : _events = List<LocalAuditEvent>.from(initial ?? const <LocalAuditEvent>[]);

  List<LocalAuditEvent> _events;
  int saveCount = 0;

  @override
  Future<List<LocalAuditEvent>> pending() async =>
      List<LocalAuditEvent>.unmodifiable(_events);

  @override
  Future<void> save(List<LocalAuditEvent> events) async {
    saveCount++;
    _events = List<LocalAuditEvent>.from(events);
  }
}
