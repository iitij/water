import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import '../domain/reading_filter_preset.dart';

/// مخزن فلاتر القراءات المحفوظة.
abstract interface class ReadingFilterPresetStore {
  Future<List<ReadingFilterPreset>> load();

  Future<void> save(List<ReadingFilterPreset> presets);
}

class SharedPreferencesReadingFilterPresetStore implements ReadingFilterPresetStore {
  SharedPreferencesReadingFilterPresetStore({SharedPreferences? prefs, this.maxPresets = 12})
      : _prefs = prefs;

  /// سقف مقصود: الشرائح تُعرض أفقياً، وأكثر من 12 فلتراً يعني أن التسمية بلا
  /// معنى. عند التجاوز نسقط الأقدم ونُبقي الأحدث.
  static const String storageKey = 'readings.filter.presets';

  final int maxPresets;
  SharedPreferences? _prefs;

  Future<SharedPreferences> get _store async =>
      _prefs ??= await SharedPreferences.getInstance();

  @override
  Future<List<ReadingFilterPreset>> load() async {
    final raw = (await _store).getString(storageKey);
    if (raw == null || raw.trim().isEmpty) return const <ReadingFilterPreset>[];
    try {
      final decoded = jsonDecode(raw);
      if (decoded is! List) return const <ReadingFilterPreset>[];
      return decoded
          .whereType<Map>()
          .map((item) => ReadingFilterPreset.fromJson(Map<String, dynamic>.from(item)))
          .where((preset) => preset.id.isNotEmpty)
          .toList(growable: false);
    } on FormatException {
      // بيانات تالفة لا تُعطّل الشاشة: نبدأ من جديد.
      await (await _store).remove(storageKey);
      return const <ReadingFilterPreset>[];
    }
  }

  @override
  Future<void> save(List<ReadingFilterPreset> presets) async {
    final bounded =
        presets.length <= maxPresets ? presets : presets.sublist(presets.length - maxPresets);
    await (await _store).setString(
      storageKey,
      jsonEncode(bounded.map((preset) => preset.toJson()).toList(growable: false)),
    );
  }
}

/// مخزن في الذاكرة للاختبارات.
class InMemoryReadingFilterPresetStore implements ReadingFilterPresetStore {
  InMemoryReadingFilterPresetStore([List<ReadingFilterPreset> seed = const <ReadingFilterPreset>[]])
      : _presets = List<ReadingFilterPreset>.from(seed);

  List<ReadingFilterPreset> _presets;

  List<ReadingFilterPreset> get snapshot => List<ReadingFilterPreset>.unmodifiable(_presets);

  @override
  Future<List<ReadingFilterPreset>> load() async =>
      List<ReadingFilterPreset>.unmodifiable(_presets);

  @override
  Future<void> save(List<ReadingFilterPreset> presets) async {
    _presets = List<ReadingFilterPreset>.from(presets);
  }
}
