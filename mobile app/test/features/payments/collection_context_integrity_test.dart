import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/error/failures.dart';
import 'package:water_project/features/invoices/data/datasources/references_remote_datasource.dart';
import 'package:water_project/features/invoices/data/models/invoice_model.dart';
import 'package:water_project/features/invoices/domain/accounting/finance_enums.dart';
import 'package:water_project/features/payments/domain/repositories/payments_repository.dart';
import 'package:water_project/features/payments/presentation/cubit/collect_payment_cubit.dart';

class _Payments extends Mock implements PaymentsRepository {}
class _References extends Mock implements ReferencesRemoteDataSource {}

InvoiceModel invoice(int id, {int owner = 1, int meter = 10, double due = 100}) =>
    InvoiceModel(id: id, subscriberId: owner, meterId: meter,
        invoiceType: InvoiceType.meter, billNumber: 'QA-$id', totalAmount: due,
        status: InvoiceStatus.issued);

void main() {
  test('explicit invoice cannot be replaced by latest invoice or route meter hint', () async {
    final payments = _Payments();
    final references = _References();
    when(() => references.dueSummary(1)).thenAnswer((_) async =>
        const SubscriberDueSummary(subscriberId: 1));
    when(() => payments.latestUnpaidInvoice(1)).thenAnswer((_) async =>
        invoice(200, meter: 20, due: 500));
    final cubit = CollectPaymentCubit(paymentsRepository: payments,
        references: references, meterId: 999);
    addTearDown(cubit.close);
    await cubit.openForInvoice(invoice(100));
    final request = cubit.buildRequest();
    expect(request.subscriberId, 1);
    expect(request.invoiceId, 100);
    expect(request.meterId, 10);
    expect(request.amount, 100);
  });

  test('new subscriber without debt cannot inherit prior account payment details', () async {
    final payments = _Payments();
    final references = _References();
    when(() => references.dueSummary(1)).thenAnswer((_) async =>
        const SubscriberDueSummary(subscriberId: 1));
    when(() => references.dueSummary(2)).thenAnswer((_) async =>
        const SubscriberDueSummary(subscriberId: 2));
    when(() => payments.latestUnpaidInvoice(1)).thenAnswer((_) async => invoice(100));
    when(() => payments.latestUnpaidInvoice(2)).thenAnswer((_) async => null);
    final cubit = CollectPaymentCubit(paymentsRepository: payments,
        references: references, meterId: 10);
    addTearDown(cubit.close);
    await cubit.openForSubscriber(1, name: 'First');
    cubit.setReferenceNumber('old-reference');
    cubit.setNotes('old-note');
    await cubit.openForSubscriber(2);
    cubit.setAmount(50);
    final request = cubit.buildRequest();
    expect(request.subscriberId, 2);
    expect(request.invoiceId, isNull);
    expect(request.meterId, isNull);
    expect(request.referenceNumber, isNull);
    expect(request.notes, isNull);
    expect(cubit.state.subscriberName, isNull);
    expect(request.validate(), isNull);
  });

  test('old response for the same subscriber cannot overwrite a newer explicit invoice', () async {
    final payments = _Payments();
    final references = _References();
    final old = Completer<SubscriberDueSummary?>();
    var calls = 0;
    when(() => references.dueSummary(1)).thenAnswer((_) async =>
        ++calls == 1 ? old.future : SubscriberDueSummary(subscriberId: 1,
          invoices: [invoice(200, meter: 20, due: 200)], dueAmount: 200));
    when(() => payments.latestUnpaidInvoice(1)).thenAnswer((_) async => invoice(200, meter: 20, due: 200));
    final cubit = CollectPaymentCubit(paymentsRepository: payments,
        references: references, meterId: null);
    addTearDown(cubit.close);
    final first = cubit.openForInvoice(invoice(100));
    expect(cubit.buildRequest, throwsA(isA<ValidationFailure>()));
    await cubit.openForInvoice(invoice(200, meter: 20, due: 200));
    old.complete(SubscriberDueSummary(subscriberId: 1,
        invoices: [invoice(100)], dueAmount: 100));
    await first;
    expect(cubit.buildRequest().invoiceId, 200);
    expect(cubit.buildRequest().meterId, 20);
    expect(cubit.buildRequest().amount, 200);
    verify(() => payments.latestUnpaidInvoice(1)).called(1);
  });

  test('foreign summary or conflicting invoice identity cannot be submitted after editing amount', () async {
    final payments = _Payments();
    final references = _References();
    when(() => references.dueSummary(1)).thenAnswer((_) async =>
        SubscriberDueSummary(subscriberId: 2, invoices: [invoice(100, owner: 2)]));
    when(() => payments.latestUnpaidInvoice(1)).thenAnswer((_) async => null);
    final cubit = CollectPaymentCubit(paymentsRepository: payments,
        references: references, meterId: null);
    addTearDown(cubit.close);
    await cubit.openForSubscriber(1);
    cubit.setAmount(50);
    expect(cubit.buildRequest, throwsA(isA<ValidationFailure>()));
    expect(await cubit.submit(), isFalse);

    when(() => references.dueSummary(1)).thenAnswer((_) async =>
        SubscriberDueSummary(subscriberId: 1, invoices: [invoice(100, meter: 99)]));
    await cubit.openForInvoice(invoice(100));
    cubit.setAmount(50);
    expect(cubit.buildRequest, throwsA(isA<ValidationFailure>()));
    expect(await cubit.submit(), isFalse);
  });
}
