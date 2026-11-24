import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/features/payments/data/models/payment_model.dart';

void main() {
  test('receipt allocation snapshot retains exact minor units through cache', () {
    final payment = PaymentModel.fromJson({
      'id': 1, 'subscriber_id': 2, 'receipt_number': 'REC-1', 'amount': 750.25, 'payment_method': 'cash',
      'allocation_snapshot': {
        'available': true, 'subscriber_id': 2, 'revision': 5,
        'allocated_minor': 60000, 'unallocated_minor': 15025,
        'rows': [
          {'payment_id': 1, 'invoice_id': 3, 'bill_number': 'INV-3', 'amount_minor': 60000},
          {'payment_id': 1, 'invoice_id': null, 'amount_minor': 15025},
        ],
      },
    });
    final restored = PaymentModel.fromJson(payment.toJson());
    expect(restored.allocationSnapshot, payment.allocationSnapshot);
    expect(restored.allocationSnapshot!.rows.last.invoiceId, isNull);
    expect(restored.allocationSnapshot!.rows.last.amountMinor, 15025);
    expect(PaymentModel.fromJson({'id': 1}).allocationSnapshot, isNull);
  });
}
