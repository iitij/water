import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/features/invoices/domain/accounting/finance_enums.dart';
import 'package:water_project/features/payments/data/models/collect_payment_models.dart';

void main() {
  group('CollectPaymentRequest payloads', () {
    test('invoice payment payload carries the idempotency UUID', () {
      const syncUuid = '6f1c1f1e-8a1e-4c1f-9f7a-1c2d3e4f5a6b';
      final request = CollectPaymentRequest(
        subscriberId: 5,
        invoiceId: 42,
        amount: 1200,
        method: PaymentMethod.cash,
        paymentDate: DateTime(2026, 6, 20),
        notes: ' دفعة ميدانية ',
      );

      final payload = request.toPayPayload(syncUuid: syncUuid);

      expect(payload['amount'], 1200);
      expect(payload['payment_method'], 'cash');
      expect(payload['payment_date'], '2026-06-20');
      expect(payload['sync_uuid'], syncUuid);
      expect(payload['notes'], 'دفعة ميدانية');
      expect(payload.containsKey('reference_number'), isFalse);
    });

    test('account-level payment keeps the isolated meter id', () {
      const syncUuid = '0b6f1b0c-6a9e-4a51-8a2c-1d2e3f4a5b6c';
      final request = CollectPaymentRequest(
        subscriberId: 5,
        meterId: 17,
        amount: 300,
        method: PaymentMethod.bankTransfer,
        referenceNumber: 'TRX-991',
        paymentDate: DateTime(2026, 7, 1),
      );

      final payload = request.toCreatePayload(syncUuid: syncUuid);

      expect(payload['subscriber_id'], 5);
      expect(payload['meter_id'], 17);
      expect(payload['payment_method'], 'bank_transfer');
      expect(payload['reference_number'], 'TRX-991');
      expect(payload['sync_uuid'], syncUuid);
      expect(payload.containsKey('invoice_id'), isFalse);
    });

    test('requires a reference number for bank transfer and check', () {
      const transfer = CollectPaymentRequest(
        subscriberId: 1,
        amount: 10,
        method: PaymentMethod.bankTransfer,
      );
      const check = CollectPaymentRequest(
        subscriberId: 1,
        amount: 10,
        method: PaymentMethod.check,
      );
      const cash = CollectPaymentRequest(
        subscriberId: 1,
        amount: 10,
        method: PaymentMethod.cash,
      );

      expect(transfer.validate(), isNotNull);
      expect(check.validate(), isNotNull);
      expect(cash.validate(), isNull);
    });

    test('rejects non-positive amounts', () {
      final request = CollectPaymentRequest(
        subscriberId: 1,
        amount: 0,
        method: PaymentMethod.cash,
      );
      expect(request.validate(), 'أدخل مبلغاً أكبر من صفر.');
    });
  });
}
