import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/finance/finance_search.dart';
import 'package:water_project/features/invoices/data/datasources/references_remote_datasource.dart';
import 'package:water_project/features/invoices/data/models/invoice_model.dart';
import 'package:water_project/features/invoices/domain/accounting/finance_enums.dart';
import 'package:water_project/features/invoices/domain/repositories/invoices_repository.dart';
import 'package:water_project/features/payments/presentation/cubit/quick_collector_cubit.dart';

class _Invoices extends Mock implements InvoicesRepository {}
class _References extends Mock implements ReferencesRemoteDataSource {}

InvoiceModel _invoice(int id, {double amountPaid = 0}) => InvoiceModel(
  id: id,
  subscriberId: id,
  meterId: id,
  invoiceType: InvoiceType.meter,
  billNumber: 'QA-$id',
  totalAmount: 100,
  amountPaid: amountPaid,
  status: amountPaid == 0 ? InvoiceStatus.issued : InvoiceStatus.partiallyPaid,
);

void main() {
  setUpAll(() => registerFallbackValue(const FinanceSearchQuery(rawQuery: '')));

  test('confirmed partial collection reappears in the selected square', () async {
    final repo = _Invoices();
    final refreshed = Completer<List<InvoiceModel>>();
    var calls = 0;
    when(() => repo.collectorList(squareId: 7, search: any(named: 'search')))
        .thenAnswer((_) async => ++calls == 1 ? [_invoice(7)] : refreshed.future);
    final cubit = QuickCollectorCubit(invoicesRepository: repo, references: _References());
    addTearDown(cubit.close);
    await cubit.selectSquare(7);
    cubit.markCollected(_invoice(7));
    expect(cubit.state.visibleInvoices, isEmpty);
    final pending = cubit.refreshQuietly();
    refreshed.complete([_invoice(7, amountPaid: 50)]);
    await pending;
    expect(cubit.state.selectedSquareId, 7);
    expect(cubit.state.visibleInvoices.single.id, 7);
    expect(cubit.state.visibleInvoices.single.ownRemaining, 50);
    expect(cubit.state.totalDue, 50);
    verifyNever(() => repo.collectorList());
  });

  test('offline queued receipt remains suppressed after stale server list refresh', () async {
    final repo = _Invoices();
    when(() => repo.collectorList()).thenAnswer((_) async => [_invoice(8)]);
    final cubit = QuickCollectorCubit(invoicesRepository: repo, references: _References());
    addTearDown(cubit.close);
    await cubit.loadUnpaid();
    cubit.markCollected(_invoice(8), queuedOffline: true);
    await cubit.refreshQuietly();
    expect(cubit.state.paidIds, contains(8));
    expect(cubit.state.visibleInvoices, isEmpty);
    expect(cubit.state.totalDue, 0);
  });

  test('old in-flight response cannot replace current square after a filter change', () async {
    final repo = _Invoices();
    final old = Completer<List<InvoiceModel>>();
    when(() => repo.collectorList(squareId: 1, search: any(named: 'search')))
        .thenAnswer((_) => old.future);
    when(() => repo.collectorList(squareId: 2, search: any(named: 'search')))
        .thenAnswer((_) async => [_invoice(2)]);
    final cubit = QuickCollectorCubit(invoicesRepository: repo, references: _References());
    addTearDown(cubit.close);
    final first = cubit.selectSquare(1);
    await cubit.selectSquare(2);
    old.complete([_invoice(1)]);
    await first;
    expect(cubit.state.selectedSquareId, 2);
    expect(cubit.state.visibleInvoices.single.id, 2);
  });

  test('closing the collector with an outstanding refresh never emits after close', () async {
    final repo = _Invoices();
    final pending = Completer<List<InvoiceModel>>();
    when(() => repo.collectorList()).thenAnswer((_) => pending.future);
    final cubit = QuickCollectorCubit(invoicesRepository: repo, references: _References());
    final load = cubit.loadUnpaid();
    await cubit.close();
    pending.complete([_invoice(1)]);
    await expectLater(load, completes);
  });
}
