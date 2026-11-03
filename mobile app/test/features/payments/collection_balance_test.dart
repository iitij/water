import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/features/invoices/data/datasources/references_remote_datasource.dart';
import 'package:water_project/features/invoices/data/models/invoice_model.dart';
import 'package:water_project/features/invoices/domain/accounting/finance_enums.dart';
import 'package:water_project/features/payments/domain/repositories/payments_repository.dart';
import 'package:water_project/features/payments/presentation/cubit/collect_payment_cubit.dart';
import 'package:water_project/features/payments/presentation/cubit/quick_collector_state.dart';

class _Payments extends Mock implements PaymentsRepository {}

class _References extends Mock implements ReferencesRemoteDataSource {}

const _partial = InvoiceModel(
  id: 1,
  subscriberId: 2,
  meterId: 3,
  invoiceType: InvoiceType.meter,
  billNumber: 'INV-1',
  totalAmount: 160800,
  amountPaid: 155800,
  serverTrueRemaining: 5000,
  isLatestInvoice: true,
  status: InvoiceStatus.partiallyPaid,
);

void main() {
  test(
    'collectible balance uses live debt and never repeats credit or paid amounts',
    () {
      expect(_partial.payableAmount, 5000);
      const fallback = InvoiceModel(
        id: 2,
        subscriberId: 2,
        invoiceType: InvoiceType.meter,
        billNumber: 'INV-2',
        totalAmount: 6000,
        amountPaid: 4000,
        creditApplied: 1000,
        previousBalance: 9000,
      );
      expect(fallback.payableAmount, 2000);
      expect(
        _partial
            .copyWith(isLatestInvoice: false, serverTrueRemaining: 77000)
            .payableAmount,
        5000,
      );
      expect(_partial.copyWith(serverTrueRemaining: -20).payableAmount, 0);
      expect(
        _partial.copyWith(status: InvoiceStatus.cancelled).payableAmount,
        0,
      );
    },
  );

  test(
    'collector summary sums invoice remainders without prior-balance duplication',
    () {
      final older = InvoiceModel.fromJson({
        ..._partial.toJson(),
        'id': 2,
        'is_latest_invoice': false,
      });
      final latest = InvoiceModel.fromJson({..._partial.toJson(), 'id': 3})
          .copyWith(
            totalAmount: 10000,
            amountPaid: 5000,
            previousBalance: 5000,
            serverTrueRemaining: 10000,
          );
      final state = QuickCollectorState(invoices: [older, latest]);
      expect(state.totalDue, 10000);
      expect(state.copyWith(paidIds: {2}).totalDue, 5000);
      expect(state.copyWith(paidIds: {2}).openCount, 1);
    },
  );

  test(
    'collection sheet initializes and submits remaining 5000 instead of original 160800',
    () async {
      final payments = _Payments();
      final references = _References();
      when(
        () => references.dueSummary(2),
      ).thenAnswer((_) async => const SubscriberDueSummary(subscriberId: 2));
      when(
        () => payments.latestUnpaidInvoice(2),
      ).thenAnswer((_) async => _partial);
      final cubit = CollectPaymentCubit(
        paymentsRepository: payments,
        references: references,
        meterId: 3,
      );
      await cubit.openForInvoice(_partial);
      expect(cubit.state.amount, 5000);
      expect(cubit.state.dueAmount, 5000);
      expect(cubit.buildRequest().amount, 5000);
      await cubit.close();
    },
  );

  test(
    'refresh cannot replace the selected invoice with another meter account',
    () async {
      final payments = _Payments();
      final references = _References();
      when(
        () => references.dueSummary(2),
      ).thenAnswer((_) async => const SubscriberDueSummary(subscriberId: 2));
      when(() => payments.latestUnpaidInvoice(2)).thenAnswer(
        (_) async => InvoiceModel.fromJson({
          ..._partial.toJson(),
          'id': 90,
          'meter_id': 99,
          'true_remaining_amount': 90000,
        }),
      );
      final cubit = CollectPaymentCubit(
        paymentsRepository: payments,
        references: references,
        meterId: 3,
      );
      await cubit.openForInvoice(_partial);
      expect(cubit.state.invoiceId, 1);
      expect(cubit.state.dueAmount, 5000);
      expect(cubit.state.amount, 5000);
      expect(cubit.buildRequest().meterId, 3);
      await cubit.close();
    },
  );
}
