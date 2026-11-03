import 'dart:async';
import 'dart:io';
import 'dart:ui' as ui;
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/l10n/report_strings.dart';
import 'package:water_project/core/theme/app_theme.dart';
import 'package:water_project/features/dashboard/data/models/report_models.dart';
import 'package:water_project/features/dashboard/domain/entities/dashboard_entities.dart';
import 'package:water_project/features/dashboard/domain/repositories/dashboard_repository.dart';
import 'package:water_project/features/dashboard/presentation/cubit/reports_cubit.dart';
import 'package:water_project/features/dashboard/presentation/pages/reports_screen.dart';

Map<String, dynamic> _payload() => {
  'summary': {
    'total_invoiced': 160800,
    'total_collected': 155800,
    'total_outstanding': 5000,
    'overdue_amount': 5000,
    'invoices_count': 10,
    'paid_invoices_count': 9,
    'overdue_invoices_count': 1,
  },
  'revenue_series': [
    {'label': '2026-11-03', 'value': 155800},
  ],
  'status_breakdown': [
    {
      'key': 'partially_paid',
      'label': 'مسددة جزئيًا',
      'value': 160800,
      'count': 10,
    },
  ],
  'square_breakdown': [
    {'key': '2', 'label': 'الربع الثاني', 'value': 155800, 'count': 2},
  ],
};

class _Repository implements DashboardRepository {
  _Repository(this.report);
  FinancialReport report;
  final filters = <ReportFilter>[];
  Completer<ReportSnapshot>? pending;
  @override
  Future<DashboardSnapshot> getDashboard({bool forceRefresh = false}) =>
      throw UnimplementedError();
  @override
  Future<ReportSnapshot> getReport(ReportFilter filter) async {
    filters.add(filter);
    return pending?.future ??
        ReportSnapshot(
          report: report,
          updatedAt: DateTime(2026, 11, 4),
          isFromCache: false,
        );
  }
}

const _preview = ValueKey('report-preview');
Widget _app(_Repository repository, {double scale = 1, bool dark = true}) =>
    MaterialApp(
      locale: const Locale('ar'),
      supportedLocales: const [Locale('ar')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      theme: dark ? AppTheme.dark : AppTheme.light,
      builder: (context, child) => MediaQuery(
        data: MediaQuery.of(
          context,
        ).copyWith(textScaler: TextScaler.linear(scale)),
        child: child!,
      ),
      home: RepaintBoundary(
        key: _preview,
        child: ReportsScreen(repository: repository),
      ),
    );

void main() {
  setUpAll(() async {
    final font = FontLoader('IBMPlexSansArabic');
    for (final weight in ['Regular', 'Medium', 'SemiBold', 'Bold']) {
      font.addFont(
        rootBundle.load('assets/fonts/IBMPlexSansArabic-$weight.ttf'),
      );
    }
    await font.load();
    await (FontLoader(
      'MaterialIcons',
    )..addFont(rootBundle.load('fonts/MaterialIcons-Regular.otf'))).load();
  });

  test(
    'legacy report totals are read and missing detail is explicit after caching',
    () {
      final report = FinancialReportModel.fromJson({
        'summary': {
          'collection_total': '155800.00',
          'invoices_total': '160800.00',
          'invoices_count': 10,
        },
        'invoices_by_status': [
          {'status': 'issued', 'total': 10},
        ],
      });
      expect(report.totalInvoiced, 160800);
      expect(report.totalCollected, 155800);
      expect(report.hasDetailedData, isFalse);
      expect(
        FinancialReportModel.fromJson(report.toJson()).hasDetailedData,
        isFalse,
      );
      expect(FinancialReportModel.fromJson(_payload()).hasDetailedData, isTrue);
    },
  );

  test(
    'explicit default dates and failed period switches never relabel stale data',
    () async {
      final repository = _Repository(FinancialReportModel.fromJson(_payload()));
      final cubit = ReportsCubit(repository, now: () => DateTime(2026, 11, 4));
      await cubit.load();
      expect(
        repository.filters.single,
        ReportFilter(from: DateTime(2026, 11), to: DateTime(2026, 11, 4)),
      );
      repository.pending = Completer<ReportSnapshot>();
      final request = cubit.load(
        ReportFilter(from: DateTime(2026, 10), to: DateTime(2026, 10, 31)),
      );
      expect(cubit.state.snapshot, isNull);
      repository.pending!.completeError(Exception('offline'));
      await request;
      expect(cubit.state.status, ReportsRequestStatus.failure);
      expect(cubit.state.snapshot, isNull);
      await cubit.close();
    },
  );

  test('report response arriving after navigation is ignored', () async {
    final repository = _Repository(FinancialReportModel.fromJson(_payload()))
      ..pending = Completer<ReportSnapshot>();
    final cubit = ReportsCubit(repository);
    final request = cubit.load();
    await cubit.close();
    repository.pending!.completeError(Exception('offline'));
    await request;
  });

  for (final width in [320.0, 390.0, 1280.0]) {
    for (final scale in [1.0, 2.0]) {
      testWidgets(
        'report cards fit width=$width scale=$scale and period changes reload',
        (tester) async {
          tester.view.physicalSize = Size(width, 900);
          tester.view.devicePixelRatio = 1;
          addTearDown(tester.view.resetPhysicalSize);
          addTearDown(tester.view.resetDevicePixelRatio);
          final repository = _Repository(
            FinancialReportModel.fromJson(_payload()),
          );
          await tester.pumpWidget(
            _app(repository, scale: scale, dark: width != 1280),
          );
          await tester.pumpAndSettle();
          expect(tester.takeException(), isNull);
          expect(find.text('لا توجد بيانات تفصيلية'), findsNothing);
          if (scale == 1 && width == 390) {
            expect(
              tester.getTopLeft(find.text(ReportStrings.revenue)).dy,
              lessThan(560),
            );
          }
          if (const bool.fromEnvironment('WRITE_REDESIGN_PREVIEWS') &&
              scale == 1) {
            await tester.runAsync(() async {
              final boundary = tester.renderObject<RenderRepaintBoundary>(
                find.byKey(_preview),
              );
              final image = await boundary.toImage();
              final data = await image.toByteData(
                format: ui.ImageByteFormat.png,
              );
              image.dispose();
              Directory('build/usability-preview').createSync(recursive: true);
              await File(
                'build/usability-preview/reports-${width.toInt()}.png',
              ).writeAsBytes(data!.buffer.asUint8List());
            });
          }
          await tester.tap(find.text(ReportStrings.previousMonth));
          await tester.pumpAndSettle();
          expect(repository.filters, hasLength(2));
          final now = DateTime.now();
          expect(
            repository.filters.last.from,
            DateTime(now.year, now.month - 1),
          );
          expect(tester.takeException(), isNull);
        },
      );
    }
  }

  testWidgets('legacy and genuinely empty reports have distinct explanations', (
    tester,
  ) async {
    final repository = _Repository(
      FinancialReportModel.fromJson({
        'summary': {'collection_total': 5000, 'invoices_total': 10000},
      }),
    );
    await tester.pumpWidget(_app(repository));
    await tester.pumpAndSettle();
    expect(find.text(ReportStrings.detailsUnavailable), findsOneWidget);
    expect(find.text(ReportStrings.outstanding), findsNothing);
    expect(find.text(ReportStrings.emptyPeriod), findsNothing);
    repository.report = FinancialReportModel.fromJson({
      'summary': {'total_outstanding': 0},
      'revenue_series': <dynamic>[],
      'status_breakdown': <dynamic>[],
      'square_breakdown': <dynamic>[],
    });
    await tester.tap(find.byTooltip(ReportStrings.refresh));
    await tester.pumpAndSettle();
    expect(find.text(ReportStrings.emptyPeriod), findsOneWidget);
    expect(find.text(ReportStrings.detailsUnavailable), findsNothing);
    expect(tester.takeException(), isNull);
  });
}
