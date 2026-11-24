import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/settings/finance_config.dart';
import 'package:water_project/features/invoices/data/models/invoice_model.dart';
import 'package:water_project/features/invoices/presentation/pages/invoice_detail_screen.dart';
import 'package:water_project/features/invoices/presentation/whatsapp/invoice_whatsapp_builder.dart';
import 'package:water_project/features/invoices/presentation/thermal/invoice_thermal_ticket.dart';

Map<String, dynamic> payload() => {
  'id': 42,
  'subscriber_id': 1,
  'invoice_type': 'meter',
  'bill_number': 'INV-42',
  'consumption_fee': '8400.00',
  'total_amount': '8400.00',
  'tariff_lines': [
    {'reading_id': 1, 'tariff_id': 11, 'tariff_name': 'January',
      'period_start': '2026-01-01', 'period_end': '2026-02-01',
      'units': '8.00', 'unit_price': '600.00', 'amount': '4800.00'},
    {'reading_id': 2, 'tariff_id': 12, 'tariff_name': 'February',
      'period_start': '2026-02-01', 'period_end': '2026-03-01',
      'units': '4.50', 'unit_price': '800.00', 'amount': '3600.00'},
  ],
};

void main() {
  test('historical evidence survives JSON and copy and has no average price', () {
    final invoice = InvoiceModel.fromJson(payload());
    expect(invoice.tariffLines!.length, 2);
    expect(invoice.consumptionUnits, 12.5);
    expect(invoice.impliedUnitPrice, isNull);
    expect(InvoiceModel.fromJson(invoice.toJson()).tariffLines, invoice.tariffLines);
    expect(invoice.copyWith(amountPaid: 100).tariffLines, invoice.tariffLines);
    const config = FinanceConfig(invoiceTemplate: '{unit_price} {consumption_units}');
    expect(InvoiceWhatsAppBuilder.renderMessage(config, invoice),
      contains('تعرفات متعددة حسب فترات الاستهلاك'));
  });

  test('absent evidence differs from an actual zero price', () {
    final raw = payload();
    raw.remove('tariff_lines');
    expect(InvoiceModel.fromJson(raw).tariffLines, isNull);
    raw['tariff_lines'] = [];
    final empty = InvoiceModel.fromJson(raw);
    expect(empty.tariffLines, isEmpty);
    expect(empty.impliedUnitPrice, isNull);
    raw['tariff_lines'] = [
      {'reading_id': 1, 'tariff_id': 1, 'tariff_name': 'Zero',
        'period_start': '2026-01-01', 'period_end': '2026-02-01',
        'units': 8, 'unit_price': 0, 'amount': 0},
    ];
    expect(InvoiceModel.fromJson(raw).impliedUnitPrice, 0);
  });

  test('thermal receipt prints actual historical prices and no synthetic average', () {
    final invoice = InvoiceModel.fromJson(payload());
    final text = buildInvoiceThermalTicket(invoice).lines.map((line) => line.text).join('\n');
    expect(text, contains('2026-01-01'));
    expect(text, contains('2026-03-01'));
    expect(text, contains('600'));
    expect(text, contains('800'));
    expect(text, isNot(contains('672')));
    final legacy = payload()..remove('tariff_lines');
    final legacyText = buildInvoiceThermalTicket(InvoiceModel.fromJson(legacy)).lines.map((line) => line.text).join('\n');
    expect(legacyText, contains('غير موثقة'));
  });

  testWidgets('pricing card shows both periods in a narrow RTL view', (tester) async {
    await tester.binding.setSurfaceSize(const Size(320, 800));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    await tester.pumpWidget(MaterialApp(home: Directionality(
      textDirection: TextDirection.rtl,
      child: Scaffold(body: SingleChildScrollView(
        child: InvoiceTariffLinesCard(invoice: InvoiceModel.fromJson(payload())),
      )),
    )));
    expect(find.textContaining('January'), findsOneWidget);
    expect(find.textContaining('February'), findsOneWidget);
    expect(find.textContaining('2026-01-01'), findsOneWidget);
    expect(find.textContaining('2026-03-01'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}
