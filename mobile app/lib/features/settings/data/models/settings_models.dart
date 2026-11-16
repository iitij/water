import '../../../admin_shared/data/admin_json.dart';
import '../../domain/entities/settings_entities.dart';

class ReadingSettingsModel extends ReadingSettings {
  const ReadingSettingsModel({
    required super.autoIssueInvoiceOnReading,
    required super.readingPeriod,
    required super.readingDay,
    required super.gracePeriod,
    required super.strictOverdueOnNewInvoice,
  });

  factory ReadingSettingsModel.fromJson(dynamic value) {
    final json = adminDataMap(value);
    const periods = {'weekly', 'ten_days', 'half_monthly', 'monthly'};
    final rawPeriod = adminString(json['reading_period'], 'monthly');
    final rawDay = adminString(json['reading_day'], 'last_day');
    final numericDay = int.tryParse(rawDay);
    final validDay = rawDay == 'last_day' ||
        (numericDay != null && numericDay >= 20 && numericDay <= 28);
    return ReadingSettingsModel(
      autoIssueInvoiceOnReading: adminBool(json['auto_issue_invoice_on_reading']),
      readingPeriod: periods.contains(rawPeriod) ? rawPeriod : 'monthly',
      readingDay: validDay ? rawDay : 'last_day',
      gracePeriod: adminInt(json['grace_period'], 14).clamp(0, 365).toInt(),
      strictOverdueOnNewInvoice: adminBool(json['strict_overdue_on_new_invoice']),
    );
  }

  Map<String, dynamic> toJson() => {
        'auto_issue_invoice_on_reading': autoIssueInvoiceOnReading ? '1' : '0',
        'reading_period': readingPeriod,
        'reading_day': readingDay,
        'grace_period': gracePeriod,
        'strict_overdue_on_new_invoice': strictOverdueOnNewInvoice ? '1' : '0',
      };
}

class TemplateSettingsModel extends TemplateSettings {
  TemplateSettingsModel(super.kind, super.values);

  factory TemplateSettingsModel.fromJson(TemplateKind kind, dynamic value) {
    final json = adminDataMap(value);
    final defaults = TemplateSettings.defaults(kind).values;
    final merged = <String, Object?>{
      ...defaults,
      for (final key in defaults.keys)
        if (json.containsKey(key)) key: json[key],
    };
    return TemplateSettingsModel(kind, merged);
  }

  Map<String, dynamic> toJson() => {
        for (final entry in values.entries)
          entry.key: entry.value is bool
              ? (entry.value as bool)
                  ? '1'
                  : '0'
              : entry.value,
      };
}

class WhatsAppSettingsModel extends WhatsAppSettings {
  const WhatsAppSettingsModel({
    required super.enabled,
    super.tokenConfigured = false,
    required super.method,
    required super.autoSendInvoice,
    required super.autoSendPayment,
    required super.invoiceMessage,
    required super.paymentMessage,
    required super.phoneNumberId,
    required super.accessToken,
    required super.invoiceTemplate,
    required super.paymentTemplate,
  });

  factory WhatsAppSettingsModel.fromJson(dynamic value) {
    final json = adminDataMap(value);
    final defaults = WhatsAppSettings.defaults();
    final rawMethod = adminString(json['whatsapp_method'], 'click_to_chat');
    final rawToken = adminString(json['whatsapp_access_token']).trim();
    const maskedTokens = {'********', '••••••••', '[redacted]', 'redacted'};
    const methods = {'click_to_chat', 'meta_api', 'local_api'};
    return WhatsAppSettingsModel(
      enabled: adminBool(json['whatsapp_enabled']),
      // الخادم يشطب الرمز الحساس ويرسل مؤشراً بأنه محفوظ.
      tokenConfigured: adminBool(json['whatsapp_access_token_configured']) ||
          (rawToken.isNotEmpty && !maskedTokens.contains(rawToken.toLowerCase())),
      method: methods.contains(rawMethod) ? rawMethod : 'click_to_chat',
      autoSendInvoice: adminBool(json['auto_send_whatsapp_invoice']),
      autoSendPayment: adminBool(json['auto_send_whatsapp_payment']),
      invoiceMessage: adminString(
        json['whatsapp_invoice_message'] ?? json['reading_invoice_message_template'],
        defaults.invoiceMessage,
      ),
      paymentMessage: adminString(
        json['whatsapp_payment_message'] ?? json['payment_receipt_message_template'],
        defaults.paymentMessage,
      ),
      phoneNumberId: adminString(json['whatsapp_phone_number_id']),
      accessToken: maskedTokens.contains(rawToken.toLowerCase()) ? '' : rawToken,
      invoiceTemplate: adminString(
        json['whatsapp_invoice_template'],
        'new_invoice_template',
      ),
      paymentTemplate: adminString(
        json['whatsapp_payment_template'],
        'payment_receipt_template',
      ),
    );
  }

  /// Writes both the Filament keys and the current mobile API aliases.
  Map<String, dynamic> toJson() => {
        'whatsapp_enabled': enabled,
        'whatsapp_method': method,
        'auto_send_whatsapp_invoice': autoSendInvoice,
        'auto_send_whatsapp_payment': autoSendPayment,
        'whatsapp_invoice_message': invoiceMessage,
        'reading_invoice_message_template': invoiceMessage,
        'whatsapp_payment_message': paymentMessage,
        'payment_receipt_message_template': paymentMessage,
        'whatsapp_phone_number_id': phoneNumberId,
        // Blank means "leave the existing server secret unchanged".
        if (accessToken.trim().isNotEmpty) 'whatsapp_access_token': accessToken.trim(),
        'whatsapp_invoice_template': invoiceTemplate,
        'whatsapp_payment_template': paymentTemplate,
      };

  Map<String, Object?> toCacheJson() => Map<String, Object?>.from(toJson())
    ..remove('whatsapp_access_token');
}

class LocalServerStatusModel extends LocalServerStatus {
  const LocalServerStatusModel({
    required super.state,
    required super.checkedAt,
    super.qrCode,
    super.whatsappLogs = const [],
    super.queueLogs = const [],
    super.message,
  });

  factory LocalServerStatusModel.fromJson(dynamic value) {
    final json = adminDataMap(value);
    final qr = adminNullableString(
      json['qr'] ?? json['localServerQr'] ?? json['local_server_qr'],
    );
    final explicit = adminNullableString(
      json['status'] ?? json['localServerStatus'] ?? json['local_server_status'],
    );
    final state = switch (explicit) {
      'connected' => LocalServerState.connected,
      'qr' => LocalServerState.qr,
      'waiting' => LocalServerState.waiting,
      'offline' => LocalServerState.offline,
      _ when adminBool(json['connected']) => LocalServerState.connected,
      _ when qr != null => LocalServerState.qr,
      _ => LocalServerState.offline,
    };
    final logs = adminMap(json['logs']);
    return LocalServerStatusModel(
      state: state,
      qrCode: state == LocalServerState.connected ? null : qr,
      whatsappLogs: _logLines(
        json['whatsappLogs'] ?? json['whatsapp_logs'] ?? logs['whatsapp'],
      ),
      queueLogs: _logLines(json['queueLogs'] ?? json['queue_logs'] ?? logs['queue']),
      checkedAt: DateTime.now(),
      message: adminNullableString(json['message']),
    );
  }

  static List<String> _logLines(dynamic value) {
    final lines = value is List
        ? value.map(adminString).toList()
        : adminString(value, 'لا توجد بيانات بعد...').split(RegExp(r'\r?\n'));
    final nonEmpty = lines.where((line) => line.trim().isNotEmpty).toList();
    return List.unmodifiable(nonEmpty.length <= 15 ? nonEmpty : nonEmpty.sublist(nonEmpty.length - 15));
  }
}

class GeneralSettingModel extends GeneralSetting {
  const GeneralSettingModel({
    required super.id,
    required super.key,
    required super.label,
    required super.type,
    super.value,
    super.imagePath,
    super.createdAt,
    super.updatedAt,
  });

  factory GeneralSettingModel.fromJson(dynamic value) {
    final json = adminDataMap(value);
    final rawType = adminString(json['type'], 'text');
    return GeneralSettingModel(
      id: adminInt(json['id']),
      key: adminString(json['key']),
      label: adminString(json['label']),
      type: rawType == 'image' ? 'image' : 'text',
      value: adminString(json['value']),
      imagePath: adminNullableString(json['image_path']),
      createdAt: adminDate(json['created_at']),
      updatedAt: adminDate(json['updated_at']),
    );
  }

  Map<String, dynamic> toJson() => {
        'key': key,
        'label': label,
        'type': type,
        'value': value,
        'image_path': imagePath,
      };
}
