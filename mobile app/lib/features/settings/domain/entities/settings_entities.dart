import 'dart:collection';

import 'package:equatable/equatable.dart';

import '../settings_defaults.dart';

enum SettingsSection { reading, invoice, receipt, whatsapp, general }

enum TemplateKind { invoice, receipt }

enum LocalServerState { offline, waiting, qr, connected }

class ReadingSettings extends Equatable {
  const ReadingSettings({
    required this.autoIssueInvoiceOnReading,
    required this.readingPeriod,
    required this.readingDay,
    required this.gracePeriod,
    required this.strictOverdueOnNewInvoice,
  });

  factory ReadingSettings.defaults() => const ReadingSettings(
        autoIssueInvoiceOnReading: false,
        readingPeriod: 'monthly',
        readingDay: 'last_day',
        gracePeriod: 14,
        strictOverdueOnNewInvoice: false,
      );

  final bool autoIssueInvoiceOnReading;
  final String readingPeriod;
  final String readingDay;
  final int gracePeriod;
  final bool strictOverdueOnNewInvoice;

  ReadingSettings copyWith({
    bool? autoIssueInvoiceOnReading,
    String? readingPeriod,
    String? readingDay,
    int? gracePeriod,
    bool? strictOverdueOnNewInvoice,
  }) {
    return ReadingSettings(
      autoIssueInvoiceOnReading:
          autoIssueInvoiceOnReading ?? this.autoIssueInvoiceOnReading,
      readingPeriod: readingPeriod ?? this.readingPeriod,
      readingDay: readingDay ?? this.readingDay,
      gracePeriod: gracePeriod ?? this.gracePeriod,
      strictOverdueOnNewInvoice:
          strictOverdueOnNewInvoice ?? this.strictOverdueOnNewInvoice,
    );
  }

  @override
  List<Object> get props => [
        autoIssueInvoiceOnReading,
        readingPeriod,
        readingDay,
        gracePeriod,
        strictOverdueOnNewInvoice,
      ];
}

class TemplateSettings extends Equatable {
  TemplateSettings(this.kind, Map<String, Object?> values)
      : values = UnmodifiableMapView(Map<String, Object?>.from(values));

  factory TemplateSettings.defaults(TemplateKind kind) => TemplateSettings(
        kind,
        kind == TemplateKind.invoice
            ? SettingsDefaults.invoice
            : SettingsDefaults.receipt,
      );

  final TemplateKind kind;
  final Map<String, Object?> values;

  String text(String key) {
    final value = values[key];
    return value == null ? '' : value.toString();
  }

  bool boolean(String key) {
    final value = values[key];
    if (value is bool) return value;
    if (value is num) return value != 0;
    return switch (value?.toString().toLowerCase()) {
      '1' || 'true' || 'yes' || 'on' => true,
      _ => false,
    };
  }

  TemplateSettings copyWith(String key, Object? value) =>
      TemplateSettings(kind, {...values, key: value});

  TemplateSettings merge(Map<String, Object?> updates) =>
      TemplateSettings(kind, {...values, ...updates});

  @override
  List<Object> get props => [kind, values];
}

class WhatsAppSettings extends Equatable {
  const WhatsAppSettings({
    required this.enabled,
    this.tokenConfigured = false,
    required this.method,
    required this.autoSendInvoice,
    required this.autoSendPayment,
    required this.invoiceMessage,
    required this.paymentMessage,
    required this.subscriptionMessage,
    required this.phoneNumberId,
    required this.accessToken,
    required this.invoiceTemplate,
    required this.paymentTemplate,
  });

  factory WhatsAppSettings.defaults() => const WhatsAppSettings(
        enabled: false,
        method: 'click_to_chat',
        autoSendInvoice: false,
        autoSendPayment: false,
        invoiceMessage: SettingsDefaults.invoiceMessage,
        paymentMessage: SettingsDefaults.paymentMessage,
        subscriptionMessage: SettingsDefaults.subscriptionMessage,
        phoneNumberId: '',
        accessToken: '',
        invoiceTemplate: 'new_invoice_template',
        paymentTemplate: 'payment_receipt_template',
      );

  final bool enabled;

  /// هل يوجد رمز واتساب محفوظ في الخادم؟ الخادم لا يعيد الرمز نفسه أبداً
  /// (يُشطف من SECRET_KEYS ويعيد whatsapp_access_token_configured بدلاً منه).
  final bool tokenConfigured;
  final String method;
  final bool autoSendInvoice;
  final bool autoSendPayment;
  final String invoiceMessage;
  final String paymentMessage;
  final String subscriptionMessage;
  final String phoneNumberId;
  final String accessToken;
  final String invoiceTemplate;
  final String paymentTemplate;

  bool get usesAutomaticSending => method == 'meta_api' || method == 'local_api';

  WhatsAppSettings copyWith({
    bool? enabled,
    bool? tokenConfigured,
    String? method,
    bool? autoSendInvoice,
    bool? autoSendPayment,
    String? invoiceMessage,
    String? paymentMessage,
    String? subscriptionMessage,
    String? phoneNumberId,
    String? accessToken,
    String? invoiceTemplate,
    String? paymentTemplate,
  }) {
    return WhatsAppSettings(
      enabled: enabled ?? this.enabled,
      tokenConfigured: tokenConfigured ?? this.tokenConfigured,
      method: method ?? this.method,
      autoSendInvoice: autoSendInvoice ?? this.autoSendInvoice,
      autoSendPayment: autoSendPayment ?? this.autoSendPayment,
      invoiceMessage: invoiceMessage ?? this.invoiceMessage,
      paymentMessage: paymentMessage ?? this.paymentMessage,
      subscriptionMessage: subscriptionMessage ?? this.subscriptionMessage,
      phoneNumberId: phoneNumberId ?? this.phoneNumberId,
      accessToken: accessToken ?? this.accessToken,
      invoiceTemplate: invoiceTemplate ?? this.invoiceTemplate,
      paymentTemplate: paymentTemplate ?? this.paymentTemplate,
    );
  }

  @override
  List<Object> get props => [
        enabled,
        method,
        tokenConfigured,
        autoSendInvoice,
        autoSendPayment,
        invoiceMessage,
        paymentMessage,
        subscriptionMessage,
        phoneNumberId,
        accessToken,
        invoiceTemplate,
        paymentTemplate,
      ];
}

class LocalServerStatus extends Equatable {
  const LocalServerStatus({
    required this.state,
    required this.checkedAt,
    this.qrCode,
    this.whatsappLogs = const [],
    this.queueLogs = const [],
    this.message,
  });

  final LocalServerState state;
  final String? qrCode;
  final List<String> whatsappLogs;
  final List<String> queueLogs;
  final DateTime checkedAt;
  final String? message;

  @override
  List<Object?> get props => [state, qrCode, whatsappLogs, queueLogs, checkedAt, message];
}

class GeneralSetting extends Equatable {
  const GeneralSetting({
    required this.id,
    required this.key,
    required this.label,
    required this.type,
    this.value = '',
    this.imagePath,
    this.createdAt,
    this.updatedAt,
  });

  final int id;
  final String key;
  final String label;
  final String type;
  final String value;
  final String? imagePath;
  final DateTime? createdAt, updatedAt;

  @override
  List<Object?> get props => [id, key, label, type, value, imagePath, createdAt, updatedAt];
}

class SettingsCacheEvent extends Equatable {
  SettingsCacheEvent({
    required this.section,
    required Map<String, Object?> values,
    required this.changedKeys,
    required this.changedAt,
  }) : values = UnmodifiableMapView(Map<String, Object?>.from(values));

  final SettingsSection section;
  final Map<String, Object?> values;
  final Set<String> changedKeys;
  final DateTime changedAt;

  @override
  List<Object> get props => [section, values, changedKeys, changedAt];
}
