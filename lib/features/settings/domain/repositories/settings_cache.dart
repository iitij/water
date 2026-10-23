import '../entities/settings_entities.dart';

/// Shared settings bus consumed by readings, invoicing and WhatsApp modules.
/// Every successful SCR-23 save publishes a new snapshot before the UI reports
/// success. Sensitive API credentials are intentionally not part of this cache.
abstract class SettingsCache {
  Future<Map<String, Object?>> readAll();

  Future<Object?> read(String key);

  Stream<SettingsCacheEvent> get changes;

  Future<void> merge(SettingsSection section, Map<String, Object?> values);

  Future<void> remove(SettingsSection section, String key);

  Future<void> dispose();
}
