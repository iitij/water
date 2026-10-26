import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/error/failures.dart';
import 'package:water_project/features/invoices/domain/accounting/finance_enums.dart';
import 'package:water_project/features/payments/data/models/payment_model.dart';
import 'package:water_project/features/payments/data/models/payments_page.dart';
import 'package:water_project/features/payments/domain/repositories/paginated_payments_repository.dart';
import 'package:water_project/features/payments/domain/repositories/payments_repository.dart';
import 'package:water_project/features/payments/presentation/cubit/payments_cubit.dart';

class _Repository extends Mock implements PaymentsRepository, PaginatedPaymentsRepository {}

PaymentModel receipt(int id) => PaymentModel(id: id, subscriberId: 10,
  receiptNumber: 'R-$id', amount: 100, method: PaymentMethod.cash);
PaymentsPage page(int number, {int id = 1}) => PaymentsPage(payments: [receipt(id)],
  currentPage: number, lastPage: 3, total: 21);

void main() {
  setUpAll(() => registerFallbackValue(const PaymentQuery()));

  test('real page metadata exposes later receipts and keeps filters and sorting', () async {
    final repository = _Repository();
    final seen = <PaymentQuery>[];
    when(() => repository.listPage(any())).thenAnswer((call) async {
      final query = call.positionalArguments.single as PaymentQuery;
      seen.add(query);
      return page(query.page, id: query.page);
    });
    final cubit = PaymentsCubit(repository: repository,
      initialQuery: const PaymentQuery(search: 'احمد', method: 'cash', limit: 10,
        sortBy: 'amount', sortDirection: 'asc'));
    addTearDown(cubit.close);
    await cubit.load();
    expect(cubit.state.totalCount, 21);
    expect(cubit.state.hasNextPage, isTrue);
    cubit.toggleSelection(1);
    await cubit.goToPage(2);
    expect(cubit.state.payments.single.id, 2);
    expect(cubit.state.selectedIds, isEmpty);
    expect(seen.last.search, 'احمد');
    expect(seen.last.method, 'cash');
    expect(seen.last.toQueryMap(), containsPair('sort_by', 'amount'));
    expect(seen.last.toQueryMap(), containsPair('per_page', 10));
    await cubit.search('علي');
    expect(cubit.state.query.page, 1);
    verifyNever(() => repository.list(any()));
  });

  test('a late response cannot overwrite the current search', () async {
    final repository = _Repository();
    final old = Completer<PaymentsPage>();
    when(() => repository.listPage(any())).thenAnswer((call) {
      final query = call.positionalArguments.single as PaymentQuery;
      return query.search.isEmpty ? old.future : Future.value(page(1, id: 9));
    });
    final cubit = PaymentsCubit(repository: repository);
    addTearDown(cubit.close);
    final first = cubit.load();
    await cubit.search('new');
    old.complete(page(1, id: 2));
    await first;
    expect(cubit.state.query.search, 'new');
    expect(cubit.state.payments.single.id, 9);
  });

  test('a rejected deletion keeps the receipt and clears its busy state', () async {
    final repository = _Repository();
    when(() => repository.listPage(any())).thenAnswer((_) async => page(1));
    when(() => repository.delete(1)).thenThrow(const ServerFailure('لا يمكن حذف السند'));
    final cubit = PaymentsCubit(repository: repository);
    addTearDown(cubit.close);
    await cubit.load();
    expect(await cubit.deletePayment(1), isFalse);
    expect(cubit.state.payments.single.id, 1);
    expect(cubit.state.errorMessage, 'لا يمكن حذف السند');
    expect(cubit.state.busyId, isNull);
  });

  test('malformed paginator fails explicitly instead of showing an empty successful list', () {
    expect(() => PaymentsPage.fromJson({'data': <Object?>[], 'current_page': 'oops', 'last_page': 1, 'total': 0}),
      throwsA(isA<ServerFailure>()));
    expect(() => PaymentsPage.fromJson({'data': [null], 'current_page': 1, 'last_page': 1, 'total': 1}),
      throwsA(isA<ServerFailure>()));
  });
}
