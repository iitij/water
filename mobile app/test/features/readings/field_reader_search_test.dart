import 'dart:async';
import 'dart:io';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/theme/app_theme.dart';
import 'package:water_project/features/meters/data/models/meter.dart';
import 'package:water_project/features/meters/domain/meters_repository.dart';
import 'package:water_project/features/meters/presentation/cubit/meters_cubit.dart';
import 'package:water_project/features/readings/domain/readings_repository.dart';
import 'package:water_project/features/readings/presentation/pages/field_reader_screen.dart';

class _Meters extends Mock implements MetersRepository {}

class _Readings extends Mock implements ReadingsRepository {}

const _previewKey = ValueKey('field-search-preview');

Future<void> _preview(WidgetTester tester, double width) async {
  if (!const bool.fromEnvironment('WRITE_FIELD_SEARCH_PREVIEWS')) return;
  await tester.runAsync(() async {
    final boundary = tester.renderObject<RenderRepaintBoundary>(
      find.byKey(_previewKey),
    );
    final image = await boundary.toImage();
    final data = await image.toByteData(format: ui.ImageByteFormat.png);
    image.dispose();
    final directory = Directory('build/field-search-preview')
      ..createSync(recursive: true);
    await File(
      '${directory.path}/route-6-${width.toInt()}.png',
    ).writeAsBytes(data!.buffer.asUint8List());
  });
}

const _meters = [
  Meter(
    id: 1,
    uniqueCode: '2403823783',
    pathNumber: 6,
    squareId: 7,
    subscriberName: 'صاحب مسار ستة',
  ),
  Meter(
    id: 2,
    uniqueCode: '2403824377',
    pathNumber: 6,
    squareId: 1,
    subscriberName: 'صاحب مسار ستة آخر',
  ),
  Meter(
    id: 3,
    uniqueCode: '2025060644',
    pathNumber: 1,
    squareId: 2,
    subscriberName: 'رقم عداده يحتوي ستة',
  ),
  Meter(
    id: 4,
    uniqueCode: '2025000016',
    pathNumber: 16,
    squareId: 7,
    subscriberName: 'صاحب مسار ستة عشر',
  ),
  Meter(
    id: 5,
    uniqueCode: '2025000060',
    squareId: 7,
    subscriberName: 'عداد بلا مسار',
  ),
];

Future<void> _afterLoad(MetersCubit cubit, void Function() action) async {
  final loaded = cubit.stream
      .skipWhile((state) => state.status != MetersLoadStatus.loading)
      .firstWhere((state) => state.status == MetersLoadStatus.success);
  action();
  await loaded.timeout(const Duration(seconds: 3));
}

void main() {
  late _Meters repository;

  setUp(() {
    repository = _Meters();
    // Deliberately return broad results, including the screenshot's false
    // match, so the field reader must enforce the selected scope itself.
    when(
      () => repository.getMeters(
        query: any(named: 'query'),
        squareId: any(named: 'squareId'),
        status: any(named: 'status'),
      ),
    ).thenAnswer((_) async => _meters);
    when(() => repository.getSquares()).thenAnswer((_) async => []);
  });

  for (final query in ['6', '٦', '۶', ' 06 ']) {
    test(
      'field route search "$query" excludes serial and partial paths',
      () async {
        final cubit = MetersCubit(repository, fieldReaderSearch: true);
        addTearDown(cubit.close);
        expect(cubit.state.searchByPath, isTrue);
        await _afterLoad(cubit, () => cubit.setQuery(query));
        expect(cubit.state.meters.map((meter) => meter.id), [1, 2]);
        verify(
          () => repository.getMeters(query: '', squareId: null, status: null),
        ).called(1);
      },
    );
  }

  for (final query in ['999', 'خالد']) {
    test(
      'route search "$query" never falls back to unrelated meters',
      () async {
        final cubit = MetersCubit(repository, fieldReaderSearch: true);
        addTearDown(cubit.close);
        await _afterLoad(cubit, () => cubit.setQuery(query));
        expect(cubit.state.meters, isEmpty);
      },
    );
  }

  test('compound square/path search isolates exact square and path', () async {
    final cubit = MetersCubit(repository, fieldReaderSearch: true);
    addTearDown(cubit.close);
    await _afterLoad(cubit, () => cubit.setQuery('٧/٦'));
    expect(cubit.state.meters.map((meter) => meter.id), [1]);
  });

  test('route search preserves the selected square and status', () async {
    final cubit = MetersCubit(repository, fieldReaderSearch: true);
    addTearDown(cubit.close);
    await _afterLoad(
      cubit,
      () => cubit.applyFilters(squareId: 7, status: 'active', pathNumber: null),
    );
    clearInteractions(repository);
    await _afterLoad(cubit, () => cubit.setQuery('6'));
    verify(
      () => repository.getMeters(query: '', squareId: 7, status: 'active'),
    ).called(1);
    expect(cubit.state.squareFilter, 7);
    expect(cubit.state.statusFilter, 'active');
    expect(cubit.state.meters.map((meter) => meter.id), [1]);
  });

  test(
    'switching scope forwards the same query as general meter search',
    () async {
      final cubit = MetersCubit(repository, fieldReaderSearch: true);
      addTearDown(cubit.close);
      await _afterLoad(cubit, () => cubit.setQuery('6'));
      await _afterLoad(cubit, () => cubit.setSearchByPath(false));
      expect(cubit.state.query, '6');
      expect(cubit.state.searchByPath, isFalse);
      expect(cubit.state.meters.map((meter) => meter.id), [3, 4, 5]);
      verify(
        () => repository.getMeters(query: '6', squareId: null, status: null),
      ).called(1);
      await _afterLoad(cubit, () => cubit.setSearchByPath(true));
      expect(cubit.state.meters.map((meter) => meter.id), [1, 2]);
    },
  );

  test(
    'scanned numeric meter code automatically selects meter search',
    () async {
      final cubit = MetersCubit(repository, fieldReaderSearch: true);
      addTearDown(cubit.close);
      await _afterLoad(cubit, () => cubit.setMeterQuery('2025060644'));
      expect(cubit.state.searchByPath, isFalse);
      verify(
        () => repository.getMeters(
          query: '2025060644',
          squareId: null,
          status: null,
        ),
      ).called(1);
    },
  );

  test('clearing route query restores all available meters', () async {
    final cubit = MetersCubit(repository, fieldReaderSearch: true);
    addTearDown(cubit.close);
    await _afterLoad(cubit, () => cubit.setQuery('6'));
    await _afterLoad(cubit, () => cubit.setQuery(''));
    expect(cubit.state.meters, _meters);
    expect(cubit.state.searchByPath, isTrue);
  });

  test('regular meter list keeps its existing general search', () async {
    final cubit = MetersCubit(repository);
    addTearDown(cubit.close);
    await _afterLoad(cubit, () => cubit.setQuery('6'));
    expect(cubit.state.searchByPath, isFalse);
    expect(cubit.state.meters, _meters);
    verify(
      () => repository.getMeters(query: '6', squareId: null, status: null),
    ).called(1);
  });

  test('an older route load cannot replace a newer meter query', () async {
    final oldResponse = Completer<List<Meter>>();
    when(
      () => repository.getMeters(query: '', squareId: null, status: null),
    ).thenAnswer((_) => oldResponse.future);
    when(
      () => repository.getMeters(
        query: '2025060644',
        squareId: null,
        status: null,
      ),
    ).thenAnswer((_) async => [_meters[2]]);
    final cubit = MetersCubit(repository, fieldReaderSearch: true);
    addTearDown(cubit.close);
    final firstLoad = cubit.load();
    await _afterLoad(cubit, () => cubit.setMeterQuery('2025060644'));
    expect(cubit.state.meters.map((meter) => meter.id), [3]);
    oldResponse.complete(_meters);
    await firstLoad;
    expect(cubit.state.meters.map((meter) => meter.id), [3]);
  });

  for (final width in [390.0, 1280.0]) {
    testWidgets('field search distinguishes routes from meters at $width', (
      tester,
    ) async {
      tester.view.physicalSize = Size(width, 1200);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.dark,
          locale: const Locale('ar'),
          supportedLocales: const [Locale('ar')],
          localizationsDelegates: GlobalMaterialLocalizations.delegates,
          home: RepaintBoundary(
            key: _previewKey,
            child: FieldReaderScreen(
              metersRepository: repository,
              readingsRepository: _Readings(),
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('المسار'), findsOneWidget);
      expect(find.text('الاسم أو رقم العداد'), findsOneWidget);
      await tester.enterText(find.byType(TextField), '٦');
      await tester.pumpAndSettle(const Duration(milliseconds: 350));
      expect(find.text('صاحب مسار ستة'), findsOneWidget);
      expect(find.text('رقم عداده يحتوي ستة'), findsNothing);
      expect(find.text('صاحب مسار ستة عشر'), findsNothing);
      await _preview(tester, width);
      await tester.tap(find.text('الاسم أو رقم العداد'));
      await tester.pumpAndSettle();
      verify(
        () => repository.getMeters(
          query: '٦',
          squareId: null,
          status: null,
        ),
      ).called(1);
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('opening a route stop keeps its numeric meter query', (
    tester,
  ) async {
    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('ar'),
        supportedLocales: const [Locale('ar')],
        localizationsDelegates: GlobalMaterialLocalizations.delegates,
        home: FieldReaderScreen(
          metersRepository: repository,
          readingsRepository: _Readings(),
          initialMeterQuery: '2025060644',
        ),
      ),
    );
    await tester.pumpAndSettle(const Duration(milliseconds: 350));
    verify(
      () => repository.getMeters(
        query: '2025060644',
        squareId: null,
        status: null,
      ),
    ).called(1);
    expect(
      tester.widget<TextField>(find.byType(TextField)).controller?.text,
      '2025060644',
    );
    expect(tester.takeException(), isNull);
  });
}
