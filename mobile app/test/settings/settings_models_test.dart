import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/features/settings/data/models/settings_models.dart';
import 'package:water_project/features/settings/domain/entities/settings_entities.dart';

void main() {
  test('legacy or invalid reading values cannot break dropdowns', () {
    final settings = ReadingSettingsModel.fromJson({
      'reading_period': 'daily',
      'reading_day': '15',
    });
    expect(settings.readingPeriod, 'monthly');
    expect(settings.readingDay, 'last_day');
  });

  test('template defaults include all invoice and receipt switches/labels', () {
    final invoice = TemplateSettingsModel.fromJson(TemplateKind.invoice, const <String, dynamic>{});
    final receipt = TemplateSettingsModel.fromJson(TemplateKind.receipt, const <String, dynamic>{});

    expect(invoice.boolean('inv_show_bill_number'), isTrue);
    expect(invoice.text('inv_lbl_credit_applied'), 'رصيد مخصوم');
    expect(invoice.boolean('inv_show_notes'), isTrue);
    expect(receipt.text('rect_amount_words_suffix'), 'ريال يمني لا غير');
    expect(receipt.boolean('rect_show_amount_in_words'), isTrue);
  });

  test('default operational templates preserve all Laravel variables', () {
    final settings = WhatsAppSettingsModel.fromJson(const <String, dynamic>{});
    expect(settings.invoiceMessage, contains('{meter_code}'));
    expect(settings.invoiceMessage, contains('{unit_price}'));
    expect(settings.paymentMessage, contains('{payment_method}'));
    expect(settings.subscriptionMessage, contains('{collector}'));
  });

  test('WhatsApp model reads legacy aliases and writes the supported template contracts', () {
    final settings = WhatsAppSettingsModel.fromJson({
      'whatsapp_enabled': '1',
      'whatsapp_method': 'local_api',
      'reading_invoice_message_template': 'invoice',
      'payment_receipt_message_template': 'payment',
      'subscription_invoice_template': 'subscription',
      'whatsapp_access_token': 'secret',
    });
    final json = settings.toJson();

    expect(settings.invoiceMessage, 'invoice');
    expect(settings.subscriptionMessage, 'subscription');
    expect(json['whatsapp_invoice_message'], 'invoice');
    expect(json['reading_invoice_message_template'], 'invoice');
    expect(json['whatsapp_payment_message'], 'payment');
    expect(json['subscription_invoice_template'], 'subscription');
    expect(settings.toCacheJson(), isNot(contains('whatsapp_access_token')));
  });

  test('blank or masked access token is omitted instead of erasing the server secret', () {
    final blank = WhatsAppSettingsModel.fromJson(const {'whatsapp_access_token': '   '});
    final masked = WhatsAppSettingsModel.fromJson(const {'whatsapp_access_token': '********'});
    expect(blank.toJson(), isNot(contains('whatsapp_access_token')));
    expect(masked.toJson(), isNot(contains('whatsapp_access_token')));
  });

  test('local server parser handles QR, connected state and last 15 log lines', () {
    final status = LocalServerStatusModel.fromJson({
      'connected': false,
      'qr': 'data:image/png;base64,AAAA',
      'whatsappLogs': List.generate(20, (index) => 'line $index'),
      'queue_logs': 'one\ntwo',
    });

    expect(status.state, LocalServerState.qr);
    expect(status.whatsappLogs, hasLength(15));
    expect(status.whatsappLogs.first, 'line 5');
    expect(status.queueLogs, ['one', 'two']);
  });
}
