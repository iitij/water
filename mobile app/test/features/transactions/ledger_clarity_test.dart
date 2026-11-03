import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/fouad/fouad_module.dart';
import 'package:water_project/core/l10n/list_clarity_strings.dart';
import 'package:water_project/core/theme/app_theme.dart';
import 'package:water_project/features/transactions/domain/entities/ledger_transaction.dart';
import 'package:water_project/features/transactions/domain/repositories/transactions_repository.dart';
import 'package:water_project/features/transactions/presentation/cubit/transactions_cubit.dart';
import 'package:water_project/features/transactions/presentation/pages/transactions_screen.dart';

const _entry = LedgerTransaction(
  id: 1,
  type: 'payment',
  amount: 123456789.25,
  subscriberName: 'أحمد محمد عبدالله المشترك',
  description: 'سداد فاتورة المياه لشهر أكتوبر',
  createdAt: '2026-11-04T09:00:00',
  referenceType: r'App\Models\Payment',
  referenceId: 71,
);

class _Repository implements TransactionsRepository {
  @override
  Future<List<LedgerTransaction>> getTransactions({
    String? search,
    int? subscriberId,
    String? meterId,
    String? type,
    String? fromDate,
    String? toDate,
  }) async => [_entry];
}

class _Cubit extends TransactionsCubit {
  _Cubit() : super(_Repository()) {
    emit(const TransactionsState(transactions: [_entry]));
  }
}

void main() {
  setUp(() => FouadModule.ensure(demoMode: true));
  tearDown(FouadModule.resetForTests);

  test(
    'reference labels hide PHP model names without losing reference identifiers',
    () {
      expect(
        ListClarityStrings.reference(r'App\Models\Invoice', 41),
        'فاتورة #41',
      );
      expect(
        ListClarityStrings.reference(r'App\Models\Payment', 71),
        'سند قبض #71',
      );
      expect(
        ListClarityStrings.reference('App.Models.Receipt', 9),
        'سند قبض #9',
      );
      expect(
        ListClarityStrings.reference('LegacyInternalModel', 88),
        'مرجع #88',
      );
      expect(ListClarityStrings.reference('Invoice', null), 'فاتورة');
    },
  );

  for (final width in <double>[320, 390, 1280]) {
    for (final scale in <double>[1, 2]) {
      testWidgets(
        'ledger identity has its own full-width row at $width / $scale',
        (tester) async {
          tester.view.physicalSize = Size(width, 2000);
          tester.view.devicePixelRatio = 1;
          addTearDown(tester.view.resetPhysicalSize);
          addTearDown(tester.view.resetDevicePixelRatio);
          final cubit = _Cubit();
          addTearDown(cubit.close);
          await tester.pumpWidget(
            MaterialApp(
              theme: AppTheme.light,
              locale: const Locale('ar'),
              supportedLocales: const [Locale('ar')],
              localizationsDelegates: GlobalMaterialLocalizations.delegates,
              builder: (context, child) => MediaQuery(
                data: MediaQuery.of(
                  context,
                ).copyWith(textScaler: TextScaler.linear(scale)),
                child: child!,
              ),
              home: BlocProvider<TransactionsCubit>.value(
                value: cubit,
                child: const TransactionsScreen(),
              ),
            ),
          );
          await tester.pumpAndSettle();
          final name = find.text(_entry.subscriberName!);
          final description = find.text(_entry.description!);
          expect(name, findsOneWidget);
          expect(description, findsOneWidget);
          expect(tester.getSize(name).width, greaterThan(width * .65));
          expect(
            tester.getTopLeft(description).dy,
            greaterThanOrEqualTo(tester.getBottomLeft(name).dy),
          );
          expect(find.text('سند قبض #71'), findsOneWidget);
          expect(find.textContaining('App\\Models'), findsNothing);
          expect(tester.takeException(), isNull);
        },
      );
    }
  }
}
