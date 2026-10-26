import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/features/invoices/data/models/invoice_model.dart';
import 'package:water_project/features/invoices/data/models/invoice_query.dart';
import 'package:water_project/features/invoices/data/models/invoices_page.dart';
import 'package:water_project/features/invoices/domain/repositories/invoices_repository.dart';
import 'package:water_project/features/invoices/domain/repositories/paginated_invoices_repository.dart';
import 'package:water_project/features/invoices/presentation/cubit/invoices_cubit.dart';

class _Repository extends Mock implements InvoicesRepository, PaginatedInvoicesRepository {}

InvoicesPage page(int number, int id) => InvoicesPage(
  invoices: [InvoiceModel.fromJson({'id': id, 'subscriber_id': 10, 'bill_number': 'I-$id'})],
  currentPage: number, lastPage: 3, total: 21);

void main() {
  setUpAll(() => registerFallbackValue(const InvoiceQuery()));

  test('invoice pages preserve search and sorting without using a partial local search', () async {
    final repository = _Repository();
    final seen = <InvoiceQuery>[];
    when(() => repository.billingPeriods()).thenAnswer((_) async => <BillingPeriodOption>[]);
    when(() => repository.listPage(any())).thenAnswer((call) async {
      final query = call.positionalArguments.single as InvoiceQuery;
      seen.add(query);
      return page(query.page, query.page);
    });
    final cubit = InvoicesCubit(repository: repository);
    addTearDown(cubit.close);
    await cubit.load();
    expect(cubit.state.totalCount, 21);
    await cubit.goToPage(2);
    expect(cubit.state.invoices.single.id, 2);
    await cubit.search('فاتورة');
    expect(seen.last.page, 1);
    expect(seen.last.search, 'فاتورة');
    expect(cubit.state.invoices.single.id, 1);
    verifyNever(() => repository.list(any()));
  });

  test('late invoice responses cannot overwrite a newer search', () async {
    final repository = _Repository();
    final old = Completer<InvoicesPage>();
    when(() => repository.billingPeriods()).thenAnswer((_) async => <BillingPeriodOption>[]);
    when(() => repository.listPage(any())).thenAnswer((call) {
      final query = call.positionalArguments.single as InvoiceQuery;
      return query.search.isEmpty ? old.future : Future.value(page(1, 9));
    });
    final cubit = InvoicesCubit(repository: repository);
    addTearDown(cubit.close);
    final first = cubit.load();
    await cubit.search('new');
    old.complete(page(1, 2));
    await first;
    expect(cubit.state.query.search, 'new');
    expect(cubit.state.invoices.single.id, 9);
  });

  test('billing period query is encoded as date strings for Dio', () {
    final query = const InvoiceQuery(
      billingPeriod: '2026-10-01T00:00:00.000|2026-10-31T00:00:00.000',
    ).toQueryMap();

    expect(query['billing_period'], '2026-10-01|2026-10-31');
    expect(query.values.whereType<DateTime>(), isEmpty);
  });
}
