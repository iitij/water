import 'dart:typed_data';

import '../../../admin_shared/data/admin_api_client.dart';
import '../../domain/entities/settings_entities.dart';
import '../../domain/repositories/settings_cache.dart';
import '../../domain/repositories/settings_repository.dart';
import '../datasources/settings_remote_data_source.dart';
import '../models/settings_models.dart';

class SettingsRepositoryImpl implements SettingsRepository {
  const SettingsRepositoryImpl(this._remote, this._cache);

  final SettingsRemoteDataSource _remote;
  final SettingsCache _cache;

  @override
  Future<ReadingSettings> getReadingSettings() async {
    try {
      final settings = await _remote.getReadingSettings();
      await _cache.merge(SettingsSection.reading, settings.toJson());
      return settings;
    } catch (error) {
      if (!adminAllowsCacheFallback(error)) rethrow;
      final cached = await _cachedSection(SettingsSection.reading);
      if (cached.isEmpty) rethrow;
      return ReadingSettingsModel.fromJson(cached);
    }
  }

  @override
  Future<void> saveReadingSettings(ReadingSettings settings) async {
    final model = _readingModel(settings);
    await _remote.saveReadingSettings(model);
    await _cache.merge(SettingsSection.reading, model.toJson());
  }

  @override
  Future<TemplateSettings> getTemplateSettings(TemplateKind kind) async {
    final section = kind == TemplateKind.invoice
        ? SettingsSection.invoice
        : SettingsSection.receipt;
    try {
      final settings = await _remote.getTemplateSettings(kind);
      await _cache.merge(section, settings.toJson());
      return settings;
    } catch (error) {
      if (!adminAllowsCacheFallback(error)) rethrow;
      final cached = await _cachedSection(section);
      if (cached.isEmpty) rethrow;
      return TemplateSettingsModel.fromJson(kind, cached);
    }
  }

  @override
  Future<void> saveTemplateSettings(TemplateSettings settings) async {
    final model = _templateModel(settings);
    await _remote.saveTemplateSettings(model);
    await _cache.merge(
      settings.kind == TemplateKind.invoice
          ? SettingsSection.invoice
          : SettingsSection.receipt,
      model.toJson(),
    );
  }

  @override
  Future<WhatsAppSettings> getWhatsAppSettings() async {
    try {
      final settings = await _remote.getWhatsAppSettings();
      await _cache.merge(SettingsSection.whatsapp, settings.toCacheJson());
      return settings;
    } catch (error) {
      if (!adminAllowsCacheFallback(error)) rethrow;
      final cached = await _cachedSection(SettingsSection.whatsapp);
      if (cached.isEmpty) rethrow;
      return WhatsAppSettingsModel.fromJson(cached);
    }
  }

  @override
  Future<void> saveWhatsAppSettings(WhatsAppSettings settings) async {
    final model = _whatsappModel(settings);
    await _remote.saveWhatsAppSettings(model);
    await _cache.merge(SettingsSection.whatsapp, model.toCacheJson());
  }

  @override
  Future<String> uploadImage({
    required SettingsSection section,
    required String key,
    required Uint8List bytes,
    required String fileName,
  }) async {
    final path = await _remote.uploadImage(
      section: section,
      key: key,
      bytes: bytes,
      fileName: fileName,
    );
    await _cache.merge(section, {key: path});
    return path;
  }

  @override
  Future<LocalServerStatus> getLocalServerStatus() => _remote.getLocalServerStatus();

  @override
  Future<LocalServerStatus> startLocalServer() => _remote.startLocalServer();

  @override
  Future<LocalServerStatus> stopLocalServer() => _remote.stopLocalServer();

  @override
  Future<LocalServerStatus> logoutLocalServer() => _remote.logoutLocalServer();

  @override
  Future<String> sendWhatsAppTestMessage({
    required String phone,
    required String message,
  }) =>
      _remote.sendWhatsAppTestMessage(phone: phone, message: message);

  @override
  Future<List<GeneralSetting>> getGeneralSettings() => _remote.getGeneralSettings();

  @override
  Future<GeneralSetting> createGeneralSetting(GeneralSetting setting) async {
    final result = await _remote.createGeneralSetting(_generalModel(setting));
    await _cacheGeneral(result);
    return result;
  }

  @override
  Future<GeneralSetting> updateGeneralSetting(GeneralSetting setting) async {
    final result = await _remote.updateGeneralSetting(_generalModel(setting));
    await _cacheGeneral(result);
    return result;
  }

  @override
  Future<void> deleteGeneralSetting(GeneralSetting setting) async {
    await _remote.deleteGeneralSetting(setting.id);
    await _cache.remove(SettingsSection.general, setting.key);
  }

  @override
  Future<void> bulkDeleteGeneralSettings(List<GeneralSetting> settings) async {
    if (settings.isEmpty) return;
    await _remote.bulkDeleteGeneralSettings(
      settings.map((s) => s.id).toList(growable: false),
    );
    for (final setting in settings) {
      await _cache.remove(SettingsSection.general, setting.key);
    }
  }

  Future<void> _cacheGeneral(GeneralSetting setting) => _cache.merge(
        SettingsSection.general,
        {
          setting.key: setting.type == 'image' ? setting.imagePath : setting.value,
        },
      );

  Future<Map<String, Object?>> _cachedSection(SettingsSection section) async {
    final all = await _cache.readAll();
    final keys = switch (section) {
      SettingsSection.reading => const {
          'auto_issue_invoice_on_reading',
          'reading_period',
          'reading_day',
          'grace_period',
          'strict_overdue_on_new_invoice',
        },
      SettingsSection.invoice => TemplateSettings.defaults(TemplateKind.invoice).values.keys.toSet(),
      SettingsSection.receipt => TemplateSettings.defaults(TemplateKind.receipt).values.keys.toSet(),
      SettingsSection.whatsapp => const {
          'whatsapp_enabled',
          'whatsapp_method',
          'auto_send_whatsapp_invoice',
          'auto_send_whatsapp_payment',
          'whatsapp_invoice_message',
          'reading_invoice_message_template',
          'whatsapp_payment_message',
          'payment_receipt_message_template',
          'whatsapp_subscription_message',
          'subscription_invoice_template',
          'whatsapp_tanker_message',
          'tanker_invoice_template',
          'whatsapp_phone_number_id',
          'whatsapp_invoice_template',
          'whatsapp_payment_template',
        },
      SettingsSection.general => <String>{},
    };
    return {
      for (final key in keys)
        if (all.containsKey(key)) key: all[key],
    };
  }

  ReadingSettingsModel _readingModel(ReadingSettings value) => value is ReadingSettingsModel
      ? value
      : ReadingSettingsModel(
          autoIssueInvoiceOnReading: value.autoIssueInvoiceOnReading,
          readingPeriod: value.readingPeriod,
          readingDay: value.readingDay,
          gracePeriod: value.gracePeriod,
          strictOverdueOnNewInvoice: value.strictOverdueOnNewInvoice,
        );

  TemplateSettingsModel _templateModel(TemplateSettings value) =>
      value is TemplateSettingsModel ? value : TemplateSettingsModel(value.kind, value.values);

  WhatsAppSettingsModel _whatsappModel(WhatsAppSettings value) => value is WhatsAppSettingsModel
      ? value
      : WhatsAppSettingsModel(
          enabled: value.enabled,
          method: value.method,
          autoSendInvoice: value.autoSendInvoice,
          autoSendPayment: value.autoSendPayment,
          invoiceMessage: value.invoiceMessage,
          paymentMessage: value.paymentMessage,
          subscriptionMessage: value.subscriptionMessage,
          tankerMessage: value.tankerMessage,
          phoneNumberId: value.phoneNumberId,
          accessToken: value.accessToken,
          invoiceTemplate: value.invoiceTemplate,
          paymentTemplate: value.paymentTemplate,
        );

  GeneralSettingModel _generalModel(GeneralSetting value) => value is GeneralSettingModel
      ? value
      : GeneralSettingModel(
          id: value.id,
          key: value.key,
          label: value.label,
          type: value.type,
          value: value.value,
          imagePath: value.imagePath,
        );
}
