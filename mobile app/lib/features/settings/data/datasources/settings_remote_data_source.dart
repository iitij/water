import 'dart:typed_data';

import '../../../admin_shared/data/admin_api_client.dart';
import '../../../admin_shared/data/admin_api_endpoints.dart';
import '../../../admin_shared/data/admin_json.dart';
import '../../domain/entities/settings_entities.dart';
import '../models/settings_models.dart';

class SettingsRemoteDataSource {
  const SettingsRemoteDataSource(this._api);

  final AdminApiClient _api;

  String _templatePath(TemplateKind kind) => kind == TemplateKind.invoice
      ? AdminApiEndpoints.invoiceSettings
      : AdminApiEndpoints.receiptSettings;

  Future<ReadingSettingsModel> getReadingSettings() async {
    final response = await _api.get(
      AdminApiEndpoints.readingSettings,
      fallbackPath: AdminApiEndpoints.settings,
    );
    return ReadingSettingsModel.fromJson(response);
  }

  Future<void> saveReadingSettings(ReadingSettingsModel settings) => _api.post(
        AdminApiEndpoints.readingSettings,
        data: settings.toJson(),
        fallbackPath: AdminApiEndpoints.settings,
      );

  Future<TemplateSettingsModel> getTemplateSettings(TemplateKind kind) async {
    final response = await _api.get(
      _templatePath(kind),
      fallbackPath: AdminApiEndpoints.settings,
    );
    return TemplateSettingsModel.fromJson(kind, response);
  }

  Future<void> saveTemplateSettings(TemplateSettingsModel settings) => _api.post(
        _templatePath(settings.kind),
        data: settings.toJson(),
        fallbackPath: AdminApiEndpoints.settings,
      );

  Future<WhatsAppSettingsModel> getWhatsAppSettings() async {
    final response = await _api.get(
      AdminApiEndpoints.whatsappSettings,
      fallbackPath: AdminApiEndpoints.settings,
    );
    return WhatsAppSettingsModel.fromJson(response);
  }

  Future<void> saveWhatsAppSettings(WhatsAppSettingsModel settings) => _api.post(
        AdminApiEndpoints.whatsappSettings,
        data: settings.toJson(),
        fallbackPath: AdminApiEndpoints.settings,
      );

  Future<String> uploadImage({
    required SettingsSection section,
    required String key,
    required Uint8List bytes,
    required String fileName,
  }) async {
    final response = await _api.upload(
      AdminApiEndpoints.uploadSettingImage,
      field: 'image',
      bytes: bytes,
      fileName: fileName,
      fields: {'key': key, 'section': section.name},
    );
    final json = adminDataMap(response);
    final path = adminNullableString(
      json['path'] ?? json['image_path'] ?? json['url'] ?? json[key],
    );
    if (path == null) {
      throw const AdminApiException('لم يُرجع الخادم مسار الصورة بعد رفعها.');
    }
    return path;
  }

  Future<LocalServerStatusModel> getLocalServerStatus() async {
    final response = await _api.get(
      AdminApiEndpoints.whatsappLocalStatus,
      fallbackPath: AdminApiEndpoints.whatsappServerStatus,
    );
    return LocalServerStatusModel.fromJson(response);
  }

  Future<LocalServerStatusModel> startLocalServer() async {
    final response = await _api.post(
      AdminApiEndpoints.whatsappLocalStart,
      fallbackPath: AdminApiEndpoints.whatsappServerStart,
    );
    final json = adminDataMap(response);
    if (!json.containsKey('status') &&
        !json.containsKey('localServerStatus') &&
        !json.containsKey('connected') &&
        !json.containsKey('qr')) {
      return LocalServerStatusModel(
        state: LocalServerState.waiting,
        checkedAt: DateTime.now(),
        message: adminNullableString(json['message']),
      );
    }
    return LocalServerStatusModel.fromJson(json);
  }

  Future<LocalServerStatusModel> stopLocalServer() async {
    final response = await _api.post(
      AdminApiEndpoints.whatsappLocalStop,
      fallbackPath: AdminApiEndpoints.whatsappServerStop,
    );
    final json = adminDataMap(response);
    if (!json.containsKey('status') &&
        !json.containsKey('localServerStatus') &&
        !json.containsKey('connected') &&
        !json.containsKey('qr')) {
      return LocalServerStatusModel(
        state: LocalServerState.offline,
        checkedAt: DateTime.now(),
        message: adminNullableString(json['message']),
      );
    }
    return LocalServerStatusModel.fromJson(json);
  }

  Future<List<GeneralSettingModel>> getGeneralSettings() async {
    final response = await _api.get(AdminApiEndpoints.settingsItems);
    return adminList(response, const ['settings', 'items'])
        .map(GeneralSettingModel.fromJson)
        .toList(growable: false);
  }

  Future<GeneralSettingModel> createGeneralSetting(GeneralSettingModel setting) async {
    final response = await _api.post(
      AdminApiEndpoints.settingsItems,
      data: setting.toJson(),
    );
    final root = adminDataMap(response);
    final json = root.containsKey('setting') ? adminDataMap(root['setting']) : root;
    return json.containsKey('key')
        ? GeneralSettingModel.fromJson(json)
        : setting;
  }

  Future<GeneralSettingModel> updateGeneralSetting(GeneralSettingModel setting) async {
    final response = await _api.put(
      '${AdminApiEndpoints.settingsItems}/${setting.id}',
      data: setting.toJson(),
    );
    final root = adminDataMap(response);
    final json = root.containsKey('setting') ? adminDataMap(root['setting']) : root;
    return json.containsKey('key')
        ? GeneralSettingModel.fromJson(json)
        : setting;
  }

  Future<void> deleteGeneralSetting(int id) =>
      _api.delete('${AdminApiEndpoints.settingsItems}/$id');
}
