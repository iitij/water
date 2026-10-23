import 'dart:async';

import 'package:water_project/features/settings/domain/entities/settings_entities.dart';
import 'package:water_project/features/settings/domain/repositories/settings_cache.dart';

class MemorySettingsCache implements SettingsCache {
  final values = <String, Object?>{};
  final _controller = StreamController<SettingsCacheEvent>.broadcast(sync: true);

  @override
  Stream<SettingsCacheEvent> get changes => _controller.stream;

  @override
  Future<Object?> read(String key) async => values[key];

  @override
  Future<Map<String, Object?>> readAll() async => Map.unmodifiable(values);

  @override
  Future<void> merge(SettingsSection section, Map<String, Object?> updates) async {
    final safe = Map<String, Object?>.from(updates)..remove('whatsapp_access_token');
    values.addAll(safe);
    _controller.add(
      SettingsCacheEvent(
        section: section,
        values: values,
        changedKeys: safe.keys.toSet(),
        changedAt: DateTime.now(),
      ),
    );
  }

  @override
  Future<void> remove(SettingsSection section, String key) async {
    values.remove(key);
  }

  @override
  Future<void> dispose() => _controller.close();
}
