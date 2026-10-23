import 'dart:async';
import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import '../../domain/entities/settings_entities.dart';
import '../../domain/repositories/settings_cache.dart';

class SharedPreferencesSettingsCache implements SettingsCache {
  SharedPreferencesSettingsCache() {
    _initialization = _restore();
  }

  static const _storageKey = 'dev5.settings.cache.v1';
  static const _sensitiveKeys = <String>{
    'whatsapp_access_token',
    'access_token',
  };

  final _controller = StreamController<SettingsCacheEvent>.broadcast(sync: true);
  final _values = <String, Object?>{};
  late final Future<void> _initialization;

  /// Awaits the constructor-started restore. Safe to call more than once.
  Future<void> rehydrate() => _initialization;

  Future<void> _restore() async {
    final preferences = await SharedPreferences.getInstance();
    final raw = preferences.getString(_storageKey);
    if (raw == null) return;
    try {
      final decoded = jsonDecode(raw);
      if (decoded is Map) {
        _values.addAll(
          decoded.map((key, value) => MapEntry(key.toString(), value)),
        );
        _values.removeWhere((key, value) => _sensitiveKeys.contains(key));
      }
    } on FormatException {
      await preferences.remove(_storageKey);
    }
  }

  @override
  Future<Map<String, Object?>> readAll() async {
    await _initialization;
    return Map<String, Object?>.unmodifiable(_values);
  }

  @override
  Future<Object?> read(String key) async {
    await _initialization;
    return _values[key];
  }

  @override
  Stream<SettingsCacheEvent> get changes => _controller.stream;

  @override
  Future<void> merge(
    SettingsSection section,
    Map<String, Object?> values,
  ) async {
    await _initialization;
    final safeValues = Map<String, Object?>.from(values)
      ..removeWhere((key, value) => _sensitiveKeys.contains(key));
    if (safeValues.isEmpty) return;
    _values.addAll(safeValues);
    await _persist();
    if (!_controller.isClosed) {
      _controller.add(
        SettingsCacheEvent(
          section: section,
          values: Map<String, Object?>.unmodifiable(_values),
          changedKeys: Set<String>.unmodifiable(safeValues.keys),
          changedAt: DateTime.now(),
        ),
      );
    }
  }

  @override
  Future<void> remove(SettingsSection section, String key) async {
    await _initialization;
    if (!_values.containsKey(key)) return;
    _values.remove(key);
    await _persist();
    if (!_controller.isClosed) {
      _controller.add(
        SettingsCacheEvent(
          section: section,
          values: Map<String, Object?>.unmodifiable(_values),
          changedKeys: {key},
          changedAt: DateTime.now(),
        ),
      );
    }
  }

  Future<void> _persist() async {
    final preferences = await SharedPreferences.getInstance();
    await preferences.setString(_storageKey, jsonEncode(_values));
  }

  @override
  Future<void> dispose() => _controller.close();
}
