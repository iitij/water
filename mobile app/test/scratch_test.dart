import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get_it/get_it.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/features/invoices/data/datasources/invoices_remote_datasource.dart';
import 'package:water_project/features/invoices/data/datasources/references_remote_datasource.dart';
import 'package:water_project/features/invoices/domain/repositories/invoices_repository.dart';
import 'package:water_project/features/invoices/presentation/sheets/generate_invoice_sheets.dart';
import 'package:water_project/features/tariffs/domain/repositories/tariffs_repository.dart';

class MockInvoicesRepository extends Mock implements InvoicesRepository {}
class MockInvoicesRemoteDataSource extends Mock implements InvoicesRemoteDataSource {}
class MockReferencesRemoteDataSource extends Mock implements ReferencesRemoteDataSource {}
class MockTariffsRepository extends Mock implements TariffsRepository {}

void main() {
  testWidgets('pump GenerateMeterInvoiceSheet', (tester) async {
    final invRepo = MockInvoicesRepository();
    final invSrc = MockInvoicesRemoteDataSource();
    final refs = MockReferencesRemoteDataSource();
    final tariffs = MockTariffsRepository();

    when(() => refs.squares()).thenAnswer((_) async => []);
    when(() => refs.subscribers(status: any(named: 'status'), limit: any(named: 'limit')))
        .thenAnswer((_) async => []);
    when(() => tariffs.activePrice()).thenAnswer((_) async => 100.0);

    GetIt.I.registerSingleton<InvoicesRepository>(invRepo);
    GetIt.I.registerSingleton<InvoicesRemoteDataSource>(invSrc);
    GetIt.I.registerSingleton<ReferencesRemoteDataSource>(refs);
    GetIt.I.registerSingleton<TariffsRepository>(tariffs);

    await tester.pumpWidget(
      MaterialApp(
        home: Builder(
          builder: (context) => ElevatedButton(
            onPressed: () => GenerateMeterInvoiceSheet.show(context),
            child: const Text('Open'),
          ),
        ),
      ),
    );

    await tester.tap(find.text('Open'));
    await tester.pump();
    await tester.pump(const Duration(seconds: 1));
  });
}
