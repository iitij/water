import 'package:shared_preferences/shared_preferences.dart';

/// حفظ «أي عدادات أُنجزت» في جولة اليوم لكل مربع.
///
/// التخزين محلي بحت لأن الجولة قد تستمر بلا شبكة؛ والمفتاح يحمل التاريخ حتى
/// تبدأ جولة الغد فارغة تلقائياً بلا حاجة إلى مهمة تنظيف.
abstract interface class RouteVisitStore {
  Future<Set<int>> visited({required int squareId, required DateTime day});

  Future<void> save({
    required int squareId,
    required DateTime day,
    required Set<int> meterIds,
  });
}

class SharedPreferencesRouteVisitStore implements RouteVisitStore {
  SharedPreferencesRouteVisitStore({SharedPreferences? prefs}) : _prefs = prefs;

  SharedPreferences? _prefs;

  Future<SharedPreferences> get _store async =>
      _prefs ??= await SharedPreferences.getInstance();

  /// `route.visits.2026-10-24.7`
  static String keyFor(int squareId, DateTime day) {
    final date = '${day.year.toString().padLeft(4, '0')}-'
        '${day.month.toString().padLeft(2, '0')}-'
        '${day.day.toString().padLeft(2, '0')}';
    return 'route.visits.$date.$squareId';
  }

  @override
  Future<Set<int>> visited({required int squareId, required DateTime day}) async {
    final raw = (await _store).getStringList(keyFor(squareId, day)) ?? const <String>[];
    return raw.map(int.tryParse).whereType<int>().toSet();
  }

  @override
  Future<void> save({
    required int squareId,
    required DateTime day,
    required Set<int> meterIds,
  }) async {
    await (await _store).setStringList(
      keyFor(squareId, day),
      meterIds.map((id) => id.toString()).toList(growable: false),
    );
  }
}

/// تنفيذ في الذاكرة للاختبارات.
class InMemoryRouteVisitStore implements RouteVisitStore {
  final Map<String, Set<int>> _data = <String, Set<int>>{};

  @override
  Future<Set<int>> visited({required int squareId, required DateTime day}) async =>
      Set<int>.from(_data[SharedPreferencesRouteVisitStore.keyFor(squareId, day)] ?? const <int>{});

  @override
  Future<void> save({
    required int squareId,
    required DateTime day,
    required Set<int> meterIds,
  }) async {
    _data[SharedPreferencesRouteVisitStore.keyFor(squareId, day)] = Set<int>.from(meterIds);
  }
}
