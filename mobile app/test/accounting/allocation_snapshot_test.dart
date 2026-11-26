import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/features/payments/data/models/allocation_snapshot.dart';
import 'package:water_project/features/transactions/domain/entities/ledger_transaction.dart';
import 'package:water_project/features/payments/data/models/payment_model.dart';

void main() {
  test('receipt allocation snapshot retains exact minor units through cache', () {
    final payment = PaymentModel.fromJson({
      'id': 1, 'subscriber_id': 2, 'receipt_number': 'REC-1', 'amount': 750.25, 'payment_method': 'cash',
      'allocation_snapshot': {
        'available': true, 'integrity_valid': true, 'subscriber_id': 2, 'revision': 5,
        'allocated_minor': 60000, 'unallocated_minor': 15025,
        'rows': [
          {'payment_id': 1, 'invoice_id': 3, 'bill_number': 'INV-3', 'amount_minor': 60000},
          {'payment_id': 1, 'invoice_id': null, 'amount_minor': 15025},
        ],
      },
    });
    final restored = PaymentModel.fromJson(payment.toJson());
    expect(restored.allocationSnapshot, payment.allocationSnapshot);
    expect(restored.allocationSnapshot!.integrityValid, isTrue);
    expect(restored.allocationSnapshot!.rows.last.invoiceId, isNull);
    expect(restored.allocationSnapshot!.rows.last.amountMinor, 15025);
    expect(PaymentModel.fromJson({'id': 1}).allocationSnapshot, isNull);
  });
  test('held credit preserves a distinct noncash source through serialization', () {
    final snapshot = AllocationSnapshot.fromJson({
      'available': true, 'integrity_valid': true, 'subscriber_id': 2,
      'revision': 6, 'allocated_minor': 25, 'unallocated_minor': 0,
      'rows': [
        {'held_cash_credit_id': 8, 'invoice_id': 3, 'amount_minor': 25},
      ],
    });
    final restored = AllocationSnapshot.fromJson(snapshot.toJson());
    expect(restored, snapshot);
    expect(restored.rows.single.heldCashCreditId, 8);
    expect(restored.rows.single.paymentId, isNull);
    expect(restored.rows.single.accountTransferId, isNull);
    expect(const LedgerTransaction(id: 1, type: 'held_cash_credit', amount: 0.25).typeLabel,
      'تسوية فائض محتجز (دائن غير نقدي)');
  });
}
