import 'dart:io';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/finance/finance_search.dart';
import 'package:water_project/core/l10n/field_ux_strings.dart';
import 'package:water_project/core/l10n/finance_ux_strings.dart';
import 'package:water_project/core/l10n/owner_ux_strings.dart';
import 'package:water_project/core/theme/app_theme.dart';
import 'package:water_project/core/ui/app_controls.dart';
import 'package:water_project/features/cash_sessions/presentation/cash_session_screen.dart';
import 'package:water_project/features/dashboard/domain/entities/dashboard_entities.dart';
import 'package:water_project/features/home/presentation/home_screen.dart';
import 'package:water_project/features/invoices/data/datasources/references_remote_datasource.dart'
    show ReferencesRemoteDataSource;
import 'package:water_project/features/invoices/domain/repositories/invoices_repository.dart';
import 'package:water_project/features/meters/data/models/meter.dart';
import 'package:water_project/features/meters/domain/meters_repository.dart';
import 'package:water_project/features/meters/presentation/pages/meter_form_screen.dart';
import 'package:water_project/features/payments/presentation/cubit/quick_collector_cubit.dart';

class _Invoices extends Mock implements InvoicesRepository {}

class _References extends Mock implements ReferencesRemoteDataSource {}

class _Meters extends Mock implements MetersRepository {}

const _captureKey = ValueKey('owner-ux-preview');

DashboardSnapshot _snapshot({bool cached = false}) => DashboardSnapshot(
  data: const DashboardData(
    stats: DashboardStats(
      activeSubscribersCount: 24,
      totalSubscribersCount: 30,
      outstandingAmount: 1250000,
      monthlyCollection: 480000,
      overdueInvoicesCount: 3,
      unpaidInvoicesCount: 9,
      readingsThisMonth: 16,
      subscribersSparkline: [],
      collectionSparkline: [],
    ),
    monthlyRevenue: MonthlyRevenue(labels: [], values: []),
    latestInvoices: [],
    overdueInvoices: [],
  ),
  updatedAt: DateTime(2026, 10, 27, 12),
  isFromCache: cached,
);

Widget _app(Widget child, {double scale = 1}) => MaterialApp(
  locale: const Locale('ar'),
  supportedLocales: const [Locale('ar')],
  localizationsDelegates: GlobalMaterialLocalizations.delegates,
  theme: AppTheme.light,
  home: Builder(
    builder: (context) => MediaQuery(
      data: MediaQuery.of(
        context,
      ).copyWith(textScaler: TextScaler.linear(scale)),
      child: RepaintBoundary(
        key: _captureKey,
        child: Scaffold(body: child),
      ),
    ),
  ),
);

void _size(WidgetTester tester, {double width = 320, double height = 900}) {
  tester.view.physicalSize = Size(width, height);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);
}

Future<void> _capture(WidgetTester tester, String name) async {
  if (!const bool.fromEnvironment('WRITE_REDESIGN_PREVIEWS')) return;
  await tester.runAsync(() async {
    final boundary = tester.renderObject<RenderRepaintBoundary>(
      find.byKey(_captureKey),
    );
    final image = await boundary.toImage();
    final bytes = await image.toByteData(format: ui.ImageByteFormat.png);
    image.dispose();
    final directory = Directory('build/redesign-preview')
      ..createSync(recursive: true);
    await File(
      '${directory.path}/$name.png',
    ).writeAsBytes(bytes!.buffer.asUint8List());
  });
}

void main() {
  setUpAll(() async {
    registerFallbackValue(FinanceSearchQuery.parse(''));
    registerFallbackValue(const MeterDraft(uniqueCode: '', subscriberId: 0));
    final font = FontLoader('IBMPlexSansArabic');
    for (final weight in ['Regular', 'Medium', 'SemiBold', 'Bold']) {
      font.addFont(
        rootBundle.load('assets/fonts/IBMPlexSansArabic-$weight.ttf'),
      );
    }
    await font.load();
    final icons = FontLoader('MaterialIcons')
      ..addFont(rootBundle.load('fonts/MaterialIcons-Regular.otf'));
    await icons.load();
  });

  test(
    'clearing collector text keeps the selected square; clear filters resets both',
    () async {
      final invoices = _Invoices();
      final calls = <int?>[];
      when(
        () => invoices.collectorList(
          search: any(named: 'search'),
          squareId: any(named: 'squareId'),
        ),
      ).thenAnswer((call) async {
        calls.add(call.namedArguments[#squareId] as int?);
        return [];
      });
      when(() => invoices.collectorList()).thenAnswer((_) async {
        calls.add(null);
        return [];
      });
      final cubit = QuickCollectorCubit(
        invoicesRepository: invoices,
        references: _References(),
      );
      addTearDown(cubit.close);
      await cubit.selectSquare(7);
      await cubit.onSearchChanged('أحمد');
      cubit.clearSearch();
      await Future<void>.delayed(Duration.zero);
      expect(cubit.state.searchText, isEmpty);
      expect(cubit.state.selectedSquareId, 7);
      expect(calls.last, 7);
      cubit.clearFilters();
      await Future<void>.delayed(Duration.zero);
      expect(cubit.state.searchText, isEmpty);
      expect(cubit.state.selectedSquareId, isNull);
      expect(calls.last, isNull);
    },
  );

  for (final sample in [
    (counted: 100.0, expected: 100.0, title: FinanceUxStrings.cashMatches),
    (counted: 75.0, expected: 100.0, title: FinanceUxStrings.cashShortage),
    (counted: 125.0, expected: 100.0, title: FinanceUxStrings.cashSurplus),
  ]) {
    testWidgets('cash count ${sample.title} remains readable at 2x text', (
      tester,
    ) async {
      _size(tester);
      await tester.pumpWidget(
        _app(
          CashCountPreview(counted: sample.counted, expected: sample.expected),
          scale: 2,
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text(sample.title), findsOneWidget);
      expect(tester.takeException(), isNull);
      if (sample.title == FinanceUxStrings.cashShortage) {
        await _capture(tester, 'cash-count-shortage-light');
      }
    });
  }

  testWidgets(
    'shared action buttons grow for Arabic text and cannot submit while disabled',
    (tester) async {
      _size(tester);
      var count = 0;
      await tester.pumpWidget(
        _app(
          Column(
            children: [
              AppPrimaryButton(
                label: 'حفظ بيانات المشترك وإضافة العداد التالي',
                onPressed: () => count++,
              ),
              AppSecondaryButton(
                label: 'العودة إلى قائمة المشتركين بدون حفظ',
                onPressed: () => count++,
              ),
              const AppPrimaryButton(
                label: 'الحفظ غير متاح حالياً',
                onPressed: null,
              ),
              AppPrimaryButton(
                label: 'جارٍ الحفظ',
                onPressed: () => count++,
                loading: true,
              ),
            ],
          ),
          scale: 2,
        ),
      );
      await tester.pump();
      expect(tester.takeException(), isNull);
      expect(
        tester.getSize(find.byType(FilledButton).first).height,
        greaterThanOrEqualTo(48),
      );
      expect(
        tester.getSize(find.byType(OutlinedButton)).height,
        greaterThanOrEqualTo(48),
      );
      await tester.tap(find.text('حفظ بيانات المشترك وإضافة العداد التالي'));
      await tester.tap(find.text('العودة إلى قائمة المشتركين بدون حفظ'));
      await tester.tap(find.text('الحفظ غير متاح حالياً'));
      await tester.tap(find.text('جارٍ الحفظ'));
      expect(count, 2);
    },
  );

  testWidgets(
    'owner picker searches names and squares, rejects missing owner, and saves selected identity',
    (tester) async {
      _size(tester, width: 390, height: 1200);
      final meters = _Meters();
      const owners = [
        SubscriberOption(
          id: 1,
          name: 'أحمد الأول',
          squareId: 7,
          squareName: 'حي السلام',
          meterCount: 1,
        ),
        SubscriberOption(
          id: 2,
          name: 'محمد الثاني',
          squareId: 8,
          squareName: 'حي النور',
          meterCount: 1,
        ),
      ];
      when(() => meters.getSubscribers()).thenAnswer((_) async => owners);
      MeterDraft? saved;
      when(() => meters.saveMeter(any(), meterId: null)).thenAnswer((
        call,
      ) async {
        saved = call.positionalArguments.first as MeterDraft;
        return Meter(
          id: 30,
          uniqueCode: saved!.uniqueCode,
          subscriberId: saved!.subscriberId,
        );
      });
      await tester.pumpWidget(_app(MeterFormScreen(repository: meters)));
      await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextFormField).first, 'M-9');
      await tester.ensureVisible(find.text('حفظ بيانات العداد'));
      await tester.tap(find.text('حفظ بيانات العداد'));
      await tester.pumpAndSettle();
      expect(find.text(FieldUxStrings.ownerRequired), findsOneWidget);
      verifyNever(() => meters.saveMeter(any(), meterId: null));
      await tester.ensureVisible(find.text(FieldUxStrings.chooseOwner));
      await tester.tap(find.text(FieldUxStrings.chooseOwner));
      await tester.pumpAndSettle();
      final search = find.widgetWithText(TextField, FieldUxStrings.searchOwner);
      await tester.enterText(search, 'zzzzz');
      await tester.pumpAndSettle();
      expect(find.text(FieldUxStrings.noOwnerMatches), findsOneWidget);
      await tester.enterText(search, 'حي النور');
      await tester.pumpAndSettle();
      expect(find.text('أحمد الأول'), findsNothing);
      await tester.tap(find.text('محمد الثاني'));
      await tester.pumpAndSettle();
      expect(find.text('محمد الثاني'), findsOneWidget);
      await tester.ensureVisible(find.text('حفظ وإضافة عداد آخر'));
      await tester.tap(find.text('حفظ وإضافة عداد آخر'));
      await tester.pumpAndSettle();
      expect(saved?.subscriberId, 2);
      expect(saved?.uniqueCode, 'M-9');
      expect(find.text('محمد الثاني'), findsOneWidget);
      expect(tester.takeException(), isNull);
      await tester.ensureVisible(find.text('محمد الثاني'));
      await tester.pumpAndSettle();
      await _capture(tester, 'meter-owner-selected-light');
    },
  );

  testWidgets('meter form recovers after owner lookup fails then succeeds', (
    tester,
  ) async {
    final meters = _Meters();
    var attempts = 0;
    when(() => meters.getSubscribers()).thenAnswer((_) async {
      if (attempts++ == 0) throw StateError('offline');
      return const [
        SubscriberOption(
          id: 5,
          name: 'صاحب العداد',
          squareId: 1,
          meterCount: 1,
        ),
      ];
    });
    await tester.pumpWidget(_app(MeterFormScreen(repository: meters)));
    await tester.pumpAndSettle();
    expect(find.text('تعذر تحميل النموذج'), findsOneWidget);
    await tester.tap(find.text('إعادة المحاولة'));
    await tester.pumpAndSettle();
    expect(find.text('تعذر تحميل النموذج'), findsNothing);
    expect(find.text(FieldUxStrings.chooseOwner), findsOneWidget);
    expect(attempts, 2);
  });

  testWidgets('capture task-first owner home at phone width', (tester) async {
    _size(tester, width: 390, height: 1100);
    await tester.pumpWidget(
      _app(
        HomeWorkspace(
          name: 'صاحب المشروع',
          can: (_) => true,
          online: true,
          pendingCount: 2,
          failedCount: 0,
          onNavigate: (_) {},
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    await _capture(tester, 'owner-home-light');
  });

  testWidgets(
    'project summary preserves cached amounts and declares failed refresh',
    (tester) async {
      _size(tester, width: 320, height: 1100);
      var refreshes = 0;
      await tester.pumpWidget(
        _app(
          SingleChildScrollView(
            child: HomeProjectSummary(
              snapshot: _snapshot(cached: true),
              failed: true,
              onRefresh: () => refreshes++,
            ),
          ),
          scale: 2,
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text(OwnerUxStrings.refreshFailed), findsOneWidget);
      expect(find.textContaining('1,250,000'), findsOneWidget);
      expect(find.textContaining('480,000'), findsOneWidget);
      expect(find.textContaining('آخر تحميل ناجح:'), findsOneWidget);
      expect(find.textContaining('%'), findsNothing);
      expect(tester.takeException(), isNull);
      await tester.tap(find.text(OwnerUxStrings.refresh));
      expect(refreshes, 1);
    },
  );

  testWidgets(
    'missing project data reports failure instead of invented zero amounts',
    (tester) async {
      await tester.pumpWidget(
        _app(
          HomeProjectSummary(snapshot: null, failed: true, onRefresh: () {}),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text(OwnerUxStrings.failedNumbers), findsOneWidget);
      expect(find.text(OwnerUxStrings.outstanding), findsNothing);
      expect(find.text(OwnerUxStrings.monthlyCollection), findsNothing);
    },
  );
}
