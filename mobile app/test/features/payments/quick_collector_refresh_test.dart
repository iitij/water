import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/finance/finance_search.dart';
import 'package:water_project/features/invoices/data/datasources/references_remote_datasource.dart'
    show ReferencesRemoteDataSource;
import 'package:water_project/features/invoices/data/models/invoice_model.dart';
import 'package:water_project/features/invoices/domain/accounting/finance_enums.dart';
import 'package:water_project/features/invoices/domain/repositories/invoices_repository.dart';
import 'package:water_project/features/payments/presentation/cubit/quick_collector_cubit.dart';

class _Invoices extends Mock implements InvoicesRepository {}

class _References extends Mock implements ReferencesRemoteDataSource {}

const _invoice = InvoiceModel(
  id: 10,
  subscriberId: 3,
  invoiceType: InvoiceType.meter,
  billNumber: 'B-10',
);
const _other = InvoiceModel(
  id: 11,
  subscriberId: 4,
  invoiceType: InvoiceType.meter,
  billNumber: 'B-11',
);

void main() {
  setUpAll(() => registerFallbackValue(FinanceSearchQuery.parse('')));

  test(
    'refresh after collection keeps the selected square and search',
    () async {
      final invoices = _Invoices();
      final calls = <({String search, int? square})>[];
      when(
        () => invoices.collectorList(
          search: any(named: 'search'),
          squareId: any(named: 'squareId'),
        ),
      ).thenAnswer((call) async {
        calls.add((
          search: (call.namedArguments[#search] as FinanceSearchQuery)
              .toString(),
          square: call.namedArguments[#squareId] as int?,
        ));
        return [_invoice];
      });
      final cubit = QuickCollectorCubit(
        invoicesRepository: invoices,
        references: _References(),
      );
      addTearDown(cubit.close);
      await cubit.selectSquare(7);
      await cubit.onSearchChanged('أحمد');
      final before = calls.last;
      cubit.markCollected(_invoice);
      await Future<void>.delayed(Duration.zero);
      expect(calls.last, before);
      expect(cubit.state.searchText, 'أحمد');
      expect(cubit.state.selectedSquareId, 7);
      expect(cubit.state.paidIds, contains(10));
    },
  );

  test(
    'an old quiet refresh cannot overwrite a new square selection',
    () async {
      final invoices = _Invoices();
      final oldRefresh = Completer<List<InvoiceModel>>();
      var calls = 0;
      when(
        () => invoices.collectorList(
          search: any(named: 'search'),
          squareId: any(named: 'squareId'),
        ),
      ).thenAnswer((_) async {
        calls++;
        if (calls == 2) return oldRefresh.future;
        return calls == 1 ? [_invoice] : [_other];
      });
      final cubit = QuickCollectorCubit(
        invoicesRepository: invoices,
        references: _References(),
      );
      addTearDown(cubit.close);
      await cubit.selectSquare(7);
      final refresh = cubit.refreshQuietly();
      await cubit.selectSquare(8);
      expect(cubit.state.invoices, [_other]);
      oldRefresh.complete([_invoice]);
      await refresh;
      expect(cubit.state.selectedSquareId, 8);
      expect(cubit.state.invoices, [_other]);
    },
  );

  test(
    'closing the collector during debounce does not request or emit later',
    () async {
      final invoices = _Invoices();
      final cubit = QuickCollectorCubit(
        invoicesRepository: invoices,
        references: _References(),
      );
      final search = cubit.onSearchChanged('أحمد');
      await cubit.close();
      await search;
      verifyNever(
        () => invoices.collectorList(
          search: any(named: 'search'),
          squareId: any(named: 'squareId'),
        ),
      );
    },
  );
}
