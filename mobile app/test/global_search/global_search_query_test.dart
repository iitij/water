import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/l10n/global_search_strings.dart';
import 'package:water_project/core/l10n/list_options_strings.dart';
import 'package:water_project/core/theme/app_theme.dart';
import 'package:water_project/core/ui/list_presentation_controls.dart';
import 'package:water_project/features/global_search/data/global_search_repository.dart';
import 'package:water_project/features/global_search/data/models/global_search_models.dart';
import 'package:water_project/features/global_search/presentation/cubit/global_search_cubit.dart';
import 'package:water_project/features/global_search/presentation/pages/global_search_screen.dart';

class _Repository extends Mock implements GlobalSearchRepository {}

const _response = GlobalSearchResponse(
  query: '6',
  groups: [
    GlobalSearchGroup(
      type: 'meter',
      label: 'العدادات',
      items: [
        GlobalSearchItem(id: 1, title: 'عداد تجريبي', subtitle: 'مسار 6'),
      ],
    ),
  ],
);

void main() {
  for (final query in ['6', '٦', '۶', '0', ' 6 ', 'احمد']) {
    testWidgets('search accepts $query', (tester) async {
      final repository = _Repository();
      when(
        () => repository.search(query.trim()),
      ).thenAnswer((_) async => _response);
      final cubit = GlobalSearchCubit(repository);
      addTearDown(cubit.close);
      cubit.search(query);
      expect(cubit.state.needsMoreCharacters, isFalse);
      await tester.pump(const Duration(milliseconds: 321));
      expect(cubit.state.hasResults, isTrue);
      verify(() => repository.search(query.trim())).called(1);
    });
  }

  testWidgets('empty and single-letter queries do not request records', (
    tester,
  ) async {
    final repository = _Repository();
    final cubit = GlobalSearchCubit(repository);
    addTearDown(cubit.close);
    for (final query in ['', ' ', 'أ', '%']) {
      cubit.search(query);
      await cubit.retry();
      await tester.pump(const Duration(milliseconds: 321));
      expect(cubit.state.searched, isFalse);
      expect(cubit.state.needsMoreCharacters, query.trim().isNotEmpty);
    }
    verifyNever(() => repository.search(any()));
  });

  testWidgets('single digit can retry after a failed request', (tester) async {
    final repository = _Repository();
    when(() => repository.search('6')).thenThrow(Exception('offline'));
    final cubit = GlobalSearchCubit(repository);
    addTearDown(cubit.close);
    cubit.search('6');
    await tester.pump(const Duration(milliseconds: 321));
    expect(cubit.state.error, isNotNull);
    when(() => repository.search('6')).thenAnswer((_) async => _response);
    await cubit.retry();
    expect(cubit.state.error, isNull);
    expect(cubit.state.hasResults, isTrue);
    verify(() => repository.search('6')).called(2);
  });

  testWidgets('a digit query ignores a previous response during debounce', (
    tester,
  ) async {
    final repository = _Repository();
    final pending = Completer<GlobalSearchResponse>();
    when(() => repository.search('احمد')).thenAnswer((_) => pending.future);
    when(() => repository.search('6')).thenAnswer((_) async => _response);
    final cubit = GlobalSearchCubit(repository);
    addTearDown(cubit.close);
    cubit.search('احمد');
    await tester.pump(const Duration(milliseconds: 321));
    cubit.search('6');
    pending.complete(const GlobalSearchResponse(query: 'احمد'));
    await tester.pump();
    expect(cubit.state.loading, isTrue);
    await tester.pump(const Duration(milliseconds: 321));
    expect(cubit.state.query, '6');
    expect(cubit.state.hasResults, isTrue);
  });

  for (final width in [320.0, 1440.0]) {
    testWidgets('single-digit route results render at width $width', (
      tester,
    ) async {
      tester.view.physicalSize = Size(width, 1000);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final repository = _Repository();
      when(() => repository.search('٦')).thenAnswer((_) async => _response);
      final cubit = GlobalSearchCubit(repository);
      addTearDown(cubit.close);
      await tester.pumpWidget(
        MaterialApp(
          locale: const Locale('ar'),
          supportedLocales: const [Locale('ar')],
          localizationsDelegates: GlobalMaterialLocalizations.delegates,
          theme: AppTheme.dark,
          home: BlocProvider.value(
            value: cubit,
            child: const GlobalSearchScreen(),
          ),
        ),
      );
      await tester.enterText(find.byType(TextField), 'أ');
      await tester.pumpAndSettle();
      expect(find.text(GlobalSearchStrings.minimumTitle), findsOneWidget);
      await tester.enterText(find.byType(TextField), '٦');
      await tester.pump(const Duration(milliseconds: 321));
      await tester.pumpAndSettle();
      expect(find.text('مسار 6'), findsOneWidget);
      expect(find.text(GlobalSearchStrings.minimumTitle), findsNothing);
      verify(() => repository.search('٦')).called(1);

      // The main search is the only route-number input on this screen.
      final controls = tester.widget<ListPresentationControls>(
        find.byType(ListPresentationControls),
      );
      expect(controls.filterOptions!.hasPath, isFalse);
      await tester.tap(find.byTooltip(ListOptionsStrings.title));
      await tester.pumpAndSettle();
      expect(find.byType(TextFormField), findsNothing);
      expect(find.text('مسار 6'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }
}
