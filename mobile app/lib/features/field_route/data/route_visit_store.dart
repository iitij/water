import 'package:shared_preferences/shared_preferences.dart';

/// حفظ «أي عدادات أُنجزت» في جولة اليوم لكل مربع.
///
/// التخزين محلي بحت لأن الجولة قد تستمر بلا شبكة؛ والمفتاح يحمل التاريخ حتى
/// تبدأ جولة الغد فارغة تلقائياً بلا حاجة إلى مهمة تنظيف.

/// مفتاح زيارات محفوظة (مربع × يوم) — تنتجه المزامنة لتعداد ما على الجهاز.
class RouteVisitKey {
  const RouteVisitKey({required this.squareId, required this.day});

  final int squareId;
  final DateTime day;
}

abstract interface class RouteVisitStore {
  Future<Set<int>> visited({required int squareId, required DateTime day});

  Future<void> save({
    required int squareId,
    required DateTime day,
    required Set<int> meterIds,
  });

  /// كل مفاتيح (مربع، يوم) المحفوظة على الجهاز — تستخدمها المزامنة
  /// لرفع تقدّم الجولات إلى الخادم.
  Future<List<RouteVisitKey>> keys();
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

  @override
  Future<List<RouteVisitKey>> keys() async {
    final store = await _store;
    final result = <RouteVisitKey>[];
    for (final key in store.getKeys()) {
      final parsed = _parseKey(key);
      if (parsed != null) result.add(parsed);
    }
    return result;
  }

  /// `route.visits.2026-10-24.7` → RouteVisitKey(7, 2026-10-24)
  static RouteVisitKey? _parseKey(String key) {
    final match = RegExp(r'^route\.visits\.(\d{4}-\d{2}-\d{2})\.(\d+)$').firstMatch(key);
    if (match == null) return null;
    final day = DateTime.tryParse(match.group(1)!);
    final squareId = int.tryParse(match.group(2)!);
    if (day == null || squareId == null) return null;
    return RouteVisitKey(squareId: squareId, day: day);
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

  @override
  Future<List<RouteVisitKey>> keys() async {
    final result = <RouteVisitKey>[];
    for (final key in _data.keys) {
      final parsed = SharedPreferencesRouteVisitStore._parseKey(key);
      if (parsed != null) result.add(parsed);
    }
    return result;
  }
}
