import 'dart:typed_data';

import '../entities/settings_entities.dart';

abstract class SettingsRepository {
  Future<ReadingSettings> getReadingSettings();
  Future<void> saveReadingSettings(ReadingSettings settings);

  Future<TemplateSettings> getTemplateSettings(TemplateKind kind);
  Future<void> saveTemplateSettings(TemplateSettings settings);

  Future<WhatsAppSettings> getWhatsAppSettings();
  Future<void> saveWhatsAppSettings(WhatsAppSettings settings);

  Future<String> uploadImage({
    required SettingsSection section,
    required String key,
    required Uint8List bytes,
    required String fileName,
  });

  Future<LocalServerStatus> getLocalServerStatus();
  Future<LocalServerStatus> startLocalServer();
  Future<LocalServerStatus> stopLocalServer();
  Future<LocalServerStatus> logoutLocalServer();
  Future<String> sendWhatsAppTestMessage({
    required String phone,
    required String message,
  });

  Future<List<GeneralSetting>> getGeneralSettings();
  Future<GeneralSetting> createGeneralSetting(GeneralSetting setting);
  Future<GeneralSetting> updateGeneralSetting(GeneralSetting setting);
  Future<void> deleteGeneralSetting(GeneralSetting setting);
  Future<void> bulkDeleteGeneralSettings(List<GeneralSetting> settings);
}
