import 'dart:ui' show PointerDeviceKind;

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/fouad/fouad_module.dart';
import 'package:water_project/core/l10n/laravel_screen_strings.dart';
import 'package:water_project/core/theme/app_theme.dart';
import 'package:water_project/core/ui/app_filters.dart';
import 'package:water_project/features/transactions/domain/entities/ledger_transaction.dart';
import 'package:water_project/features/transactions/domain/repositories/transactions_repository.dart';
import 'package:water_project/features/transactions/presentation/cubit/transactions_cubit.dart';
import 'package:water_project/features/transactions/presentation/pages/transactions_screen.dart';

const _transaction = LedgerTransaction(
  id: 1,
  type: 'payment',
  amount: 250,
  meterId: 7,
  subscriberName: 'أحمد',
  description: 'سداد تجريبي',
  createdAt: '2026-11-04T09:00:00',
);

class _Repository implements TransactionsRepository {
  final meterFilters = <String?>[];
  final typeFilters = <String?>[];

  @override
  Future<List<LedgerTransaction>> getTransactions({
    String? search,
    int? subscriberId,
    String? meterId,
    String? type,
    String? fromDate,
    String? toDate,
  }) async {
    meterFilters.add(meterId);
    typeFilters.add(type);
    return [_transaction];
  }
}

class _Cubit extends TransactionsCubit {
  _Cubit(super.repo) {
    emit(const TransactionsState(loading: true));
  }
}

void main() {
  setUp(() => FouadModule.ensure(demoMode: true));
  tearDown(FouadModule.resetForTests);

  for (final width in [320.0, 390.0, 1280.0]) {
    testWidgets('ledger opens, loads and filters with a mouse at $width', (
      tester,
    ) async {
      tester.view.physicalSize = Size(width, 900);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final repository = _Repository();
      final cubit = _Cubit(repository);
      addTearDown(cubit.close);
      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.light,
          locale: const Locale('ar'),
          supportedLocales: const [Locale('ar')],
          localizationsDelegates: GlobalMaterialLocalizations.delegates,
          home: Builder(
            builder: (context) => Scaffold(
              body: TextButton(
                onPressed: () => Navigator.of(context).push(
                  MaterialPageRoute<void>(
                    builder: (_) => BlocProvider<TransactionsCubit>.value(
                      value: cubit,
                      child: const TransactionsScreen(),
                    ),
                  ),
                ),
                child: const Text('دفتر الأستاذ'),
              ),
            ),
          ),
        ),
      );
      await tester.tap(find.text('دفتر الأستاذ'));
      // Loading has an indeterminate progress bar, so don't pumpAndSettle yet.
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));
      expect(find.text(LaravelScreenStrings.transactions), findsOneWidget);
      expect(tester.takeException(), isNull);
      expect(find.byType(LinearProgressIndicator), findsOneWidget);

      final mouse = await tester.createGesture(kind: PointerDeviceKind.mouse);
      await mouse.addPointer(location: const Offset(100, 160));
      await tester.pump();
      await cubit.load();
      await tester.pumpAndSettle();
      expect(find.textContaining('سداد تجريبي'), findsOneWidget);
      expect(tester.takeException(), isNull);

      final accountFilter = find.byType(AppFilterDropdown<String>).first;
      await tester.ensureVisible(accountFilter);
      await mouse.moveTo(tester.getCenter(accountFilter));
      await tester.pump();
      await tester.tap(accountFilter);
      await tester.pumpAndSettle();
      await tester.tap(find.text('الحساب العام (بدون عداد)').last);
      await tester.pumpAndSettle();
      expect(repository.meterFilters.last, 'general');
      expect(cubit.state.meterId, 'general');
      expect(tester.takeException(), isNull);

      final typeFilter = find.byType(AppFilterDropdown<String>).last;
      await tester.ensureVisible(typeFilter);
      await mouse.moveTo(tester.getCenter(typeFilter));
      await tester.tap(typeFilter);
      await tester.pumpAndSettle();
      await tester.tap(find.text('تسوية').last);
      await tester.pumpAndSettle();
      expect(repository.typeFilters.last, 'adjustment');
      expect(repository.meterFilters.last, 'general');
      expect(tester.takeException(), isNull);
      await mouse.removePointer();
      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
    });
  }
}
