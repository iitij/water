import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/l10n/field_list_options_strings.dart';
import 'package:water_project/core/l10n/list_options_strings.dart';
import 'package:water_project/features/meters/data/models/meter.dart';
import 'package:water_project/features/meters/domain/meters_repository.dart';
import 'package:water_project/features/meters/presentation/cubit/meters_cubit.dart';
import 'package:water_project/features/meters/presentation/pages/meters_list_screen.dart';
import 'package:water_project/features/readings/domain/readings_repository.dart';

class _Meters extends Mock implements MetersRepository {}

class _Readings extends Mock implements ReadingsRepository {}

void main() {
  late _Meters repository;
  const meters = [
    Meter(
      id: 11,
      uniqueCode: 'TEN',
      pathNumber: 10,
      subscriberId: 8,
      subscriberName: 'مشترك عشرة',
    ),
    Meter(
      id: 12,
      uniqueCode: 'ONE',
      pathNumber: 1,
      subscriberId: 3,
      subscriberName: 'مشترك واحد',
    ),
    Meter(
      id: 13,
      uniqueCode: 'NONE',
      subscriberId: 9,
      subscriberName: 'بدون مسار',
    ),
  ];
  setUp(() {
    repository = _Meters();
    when(
      () => repository.getMeters(
        query: any(named: 'query'),
        squareId: any(named: 'squareId'),
        status: any(named: 'status'),
      ),
    ).thenAnswer((_) async => meters);
    when(() => repository.getSquares()).thenAnswer((_) async => []);
  });

  test(
    'exact path filtering preserves other facets and clears to the full list',
    () async {
      final cubit = MetersCubit(repository);
      addTearDown(cubit.close);
      var loaded = cubit.stream
          .skipWhile((s) => s.status != MetersLoadStatus.loading)
          .firstWhere((s) => s.status == MetersLoadStatus.success);
      cubit.applyFilters(squareId: 4, status: 'active', pathNumber: 1);
      await loaded;
      expect(cubit.state.meters.map((m) => m.id), [12]);
      verify(
        () => repository.getMeters(query: '', squareId: 4, status: 'active'),
      ).called(1);
      loaded = cubit.stream
          .skipWhile((s) => s.status != MetersLoadStatus.loading)
          .firstWhere((s) => s.status == MetersLoadStatus.success);
      cubit.setPathFilter(null);
      await loaded;
      expect(cubit.state.meters, meters);
      expect(cubit.state.squareFilter, 4);
      expect(cubit.state.statusFilter, 'active');
    },
  );

  for (final width in [390.0, 1280.0]) {
    testWidgets(
      'route filter accepts Arabic digits and validates input at width $width',
      (tester) async {
        tester.view.physicalSize = Size(width, 1000);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        await tester.pumpWidget(
          MaterialApp(
            locale: const Locale('ar'),
            supportedLocales: const [Locale('ar')],
            localizationsDelegates: GlobalMaterialLocalizations.delegates,
            home: MetersListScreen(
              metersRepository: repository,
              readingsRepository: _Readings(),
            ),
          ),
        );
        await tester.pumpAndSettle();
        expect(find.text(FieldListOptionsStrings.searchHelp), findsOneWidget);
        await tester.tap(find.bySemanticsLabel('الفلاتر'));
        await tester.pumpAndSettle();
        final path = find.byKey(const ValueKey('meters-path-filter'));
        await tester.enterText(path, 'غير صالح');
        await tester.tap(find.text('عرض النتائج'));
        await tester.pumpAndSettle();
        expect(find.text(FieldListOptionsStrings.invalidPath), findsOneWidget);
        await tester.enterText(path, '١');
        await tester.tap(find.text('عرض النتائج'));
        await tester.pumpAndSettle();
        expect(
          find.text(FieldListOptionsStrings.pathFilter(1)),
          findsOneWidget,
        );
        expect(find.text('مشترك واحد'), findsWidgets);
        expect(find.text('مشترك عشرة'), findsNothing);
        expect(tester.takeException(), isNull);
        await tester.tap(find.byTooltip(ListOptionsStrings.title));
        await tester.pumpAndSettle();
        await tester.tap(find.byKey(const ValueKey('list-sort-default')));
        await tester.pumpAndSettle();
        expect(
          find.text(FieldListOptionsStrings.subscriberAddedOrder),
          findsOneWidget,
        );
        expect(
          find.text(FieldListOptionsStrings.subscriptionStart),
          findsOneWidget,
        );
        expect(tester.takeException(), isNull);
      },
    );
  }
}
