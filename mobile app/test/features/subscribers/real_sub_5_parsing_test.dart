import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/fouad/json_read.dart';
import 'package:water_project/features/invoices/data/models/invoice_model.dart';
import 'package:water_project/features/payments/data/models/payment_model.dart';
import 'package:water_project/features/subscribers/data/models/subscriber_model.dart';
import 'package:water_project/features/subscribers/domain/entities/subscriber.dart';

void main() {
  test('parsing subscriber 5 real json works without exceptions', () {
    final file = File('../sub_5_response.json');
    expect(file.existsSync(), isTrue);
    final jsonStr = file.readAsStringSync();
    final j = jsonDecode(jsonStr) as Map<String, dynamic>;

    final summaryJson = jMap(j['financial_summary']);
    final metersJson = jMapList(j['meters']);
    final meters = metersJson.map(MeterBalanceModel.fromJson).toList();

    final subscriber = SubscriberModel.fromJson(j);
    expect(subscriber.id, 5);
    expect(subscriber.name, contains('الذبحاني'));
    expect(subscriber.debitBalance, 0.0);

    expect(meters.length, 1);
    expect(meters.first.meterId, 5);
    expect(meters.first.uniqueCode, '2025060419');
    expect(meters.first.debit, 7200.0);

    final summary = FinancialSummary(
      generalBalance: jDouble(summaryJson['general_balance']),
      totalCredit: jDouble(summaryJson['total_credit_balance']),
      totalDebit: jDouble(summaryJson['total_debit_balance']),
      meterBalances: meters,
    );
    expect(summary.totalDebit, 7200.0);

    final invoicesJson = jMapList(j['invoices']);
    expect(invoicesJson.length, 2);
    for (final inv in invoicesJson) {
      final model = InvoiceModel.fromJson({
        'subscriber_id': subscriber.id,
        'subscriber': {
          'id': subscriber.id,
          'name': subscriber.name,
          'phone': subscriber.phone,
          'square_id': subscriber.squareId,
          if (subscriber.squareName != null)
            'square': {'id': subscriber.squareId, 'name': subscriber.squareName},
        },
        ...inv,
      });
      expect(model.id, greaterThan(0));
      expect(model.totalAmount, greaterThan(0));
    }

    final paymentsJson = jMapList(j['payments']);
    expect(paymentsJson.length, 1);
    for (final p in paymentsJson) {
      final pModel = PaymentModel.fromJson({
        'subscriber_id': subscriber.id,
        'subscriber': {
          'id': subscriber.id,
          'name': subscriber.name,
          'phone': subscriber.phone,
          'square_id': subscriber.squareId,
          if (subscriber.squareName != null)
            'square': {'id': subscriber.squareId, 'name': subscriber.squareName},
        },
        ...p,
      });
      expect(pModel.id, 6);
      expect(pModel.amount, 8400.0);
    }
  });
}
