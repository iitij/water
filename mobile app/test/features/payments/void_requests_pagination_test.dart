import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/l10n/app_strings.dart';
import 'package:water_project/core/l10n/list_options_strings.dart';
import 'package:water_project/features/payments/data/models/payment_model.dart';
import 'package:water_project/features/payments/domain/repositories/payments_repository.dart';
import 'package:water_project/features/payments/domain/repositories/sorted_void_requests_repository.dart';
import 'package:water_project/features/payments/presentation/sheets/void_requests_sheet.dart';

class _Repository extends Mock implements PaymentsRepository, SortedVoidRequestsRepository {}

void main() {
  testWidgets('void-request sorting is reachable from the list and later pages retain options', (tester) async {
    final repository = _Repository();
    final seen = <Map<Symbol, dynamic>>[];
    when(() => repository.sortedVoidRequests(page: any(named: 'page'), perPage: any(named: 'perPage'),
      sortBy: any(named: 'sortBy'), sortDirection: any(named: 'sortDirection'))).thenAnswer((call) async {
      seen.add(call.namedArguments);
      final page = call.namedArguments[#page] as int;
      final size = call.namedArguments[#perPage] as int;
      return List.generate(size, (index) => PaymentVoidRequest(id: page * 100 + index,
        receiptNumber: 'R-$page-$index', amount: 10));
    });
    await tester.pumpWidget(MaterialApp(locale: const Locale('ar'), supportedLocales: const [Locale('ar')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      home: VoidRequestsSheet(repository: repository)));
    await tester.pumpAndSettle();
    expect(find.byType(AlertDialog), findsNothing);
    expect(find.byTooltip(ListOptionsStrings.title), findsOneWidget);
    await tester.tap(find.byTooltip(ListOptionsStrings.title));
    await tester.pumpAndSettle();
    await tester.tap(find.byType(DropdownButtonFormField<String>));
    await tester.pumpAndSettle();
    await tester.tap(find.text(ListOptionsStrings.amount).last);
    await tester.pumpAndSettle();
    await tester.tap(find.byType(DropdownButtonFormField<int>));
    await tester.pumpAndSettle();
    await tester.tap(find.text('10').last);
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text(ListOptionsStrings.apply));
    await tester.tap(find.text(ListOptionsStrings.apply));
    await tester.pumpAndSettle();
    expect(seen.last[#sortBy], 'amount');
    expect(seen.last[#perPage], 10);
    expect(seen.last[#page], 1);
    await tester.tap(find.text(AppStrings.ar.cashReviewNext));
    await tester.pumpAndSettle();
    expect(seen.last[#page], 2);
    expect(seen.last[#sortBy], 'amount');
    expect(find.textContaining('R-2-0'), findsOneWidget);
    verifyNever(() => repository.voidRequests(page: any(named: 'page'), perPage: any(named: 'perPage')));
    expect(tester.takeException(), isNull);
  });
}
