import 'dart:convert';

import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/diagnostics/api_log_manager.dart';
import 'package:water_project/core/diagnostics/app_bloc_observer.dart';
import 'package:water_project/features/invoices/data/models/invoice_model.dart';
import 'package:water_project/features/invoices/data/models/invoice_query.dart';
import 'package:water_project/features/invoices/domain/accounting/finance_enums.dart';
import 'package:water_project/features/invoices/domain/repositories/invoices_repository.dart';
import 'package:water_project/features/invoices/presentation/cubit/invoices_cubit.dart';
import 'package:water_project/features/invoices/presentation/cubit/invoices_state.dart';

class _InvoicesRepository extends Mock implements InvoicesRepository {}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  final period = BillingPeriodOption(
    start: DateTime(2026, 10, 1),
    end: DateTime(2026, 10, 31),
    invoiceCount: 3,
  );

  test('billing period diagnostic text encodes dates without changing API value', () {
    final decoded = jsonDecode(period.toString()) as Map<String, dynamic>;

    expect(DateTime.parse(decoded['start'] as String), period.start);
    expect(DateTime.parse(decoded['end'] as String), period.end);
    expect(period.wireValue, '2026-10-01|2026-10-31');
  });

  test('successful invoice load remains ready when the app observer logs the state', () async {
    final repository = _InvoicesRepository();
    const query = InvoiceQuery();
    const invoice = InvoiceModel(
      id: 43,
      subscriberId: 19,
      invoiceType: InvoiceType.meter,
      billNumber: 'INV-43',
      totalAmount: 1200,
    );
    when(() => repository.list(query)).thenAnswer((_) async => [invoice]);
    when(() => repository.billingPeriods()).thenAnswer((_) async => [period]);
    final logs = ApiLogManager();
    final previousObserver = Bloc.observer;
    Bloc.observer = AppBlocObserver(logManager: logs);
    final cubit = InvoicesCubit(repository: repository);
    addTearDown(() async {
      await cubit.close();
      Bloc.observer = previousObserver;
      logs.dispose();
    });

    await cubit.load();

    expect(cubit.state.status, FinanceLoadStatus.ready);
    expect(cubit.state.invoices, [invoice]);
    expect(cubit.state.billingPeriods, [period]);
    expect(cubit.state.errorMessage, isNull);
    expect(logs.errorLogs, isEmpty);
    expect(() => cubit.state.toString(), returnsNormally);
  });
}
