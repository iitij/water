import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/auth/session_service.dart';
import 'package:water_project/core/l10n/invoice_summary_strings.dart';
import 'package:water_project/core/permissions/permission_service.dart';
import 'package:water_project/core/router/finance_routes.dart';
import 'package:water_project/core/theme/app_colors.dart';
import 'package:water_project/core/theme/app_theme.dart';
import 'package:water_project/features/home/presentation/widgets/home_invoice_counts.dart';
import 'package:water_project/features/invoices/data/models/invoice_query.dart';
import 'package:water_project/features/invoices/domain/repositories/invoices_repository.dart';
import 'package:water_project/features/invoices/presentation/pages/invoices_list_screen.dart';
import 'package:water_project/features/invoices/presentation/widgets/invoice_filters_sheet.dart';

class _Session extends Mock implements SessionService {}

class _Repository extends Mock implements InvoicesRepository {}

void main() {
  setUpAll(() => registerFallbackValue(const InvoiceQuery()));

  setUp(() {
    final session = _Session();
    when(() => session.user).thenReturn({
      'roles': ['admin'],
    });
    GetIt.I.registerSingleton<SessionService>(session);
    GetIt.I.registerSingleton<PermissionService>(PermissionService(session));
  });

  tearDown(() async => GetIt.I.reset());

  for (final status in ['unpaid', 'overdue']) {
    testWidgets(
      '$status count opens the real invoice list with the matching query',
      (tester) async {
        final repository = _Repository();
        final queries = <InvoiceQuery>[];
        when(() => repository.list(any())).thenAnswer((call) async {
          queries.add(call.positionalArguments.single as InvoiceQuery);
          return [];
        });
        when(() => repository.billingPeriods()).thenAnswer((_) async => []);
        GetIt.I.registerSingleton<InvoicesRepository>(repository);
        final router = GoRouter(
          routes: [
            GoRoute(
              path: '/',
              builder: (_, _) => const Scaffold(
                body: HomeInvoiceCounts(
                  unpaid: 99,
                  overdue: 2,
                  canViewInvoices: true,
                ),
              ),
            ),
            ...buildFinanceRoutes(),
          ],
        );
        addTearDown(router.dispose);
        await tester.pumpWidget(
          MaterialApp.router(
            routerConfig: router,
            locale: const Locale('ar'),
            supportedLocales: const [Locale('ar')],
            localizationsDelegates: GlobalMaterialLocalizations.delegates,
          ),
        );
        await tester.pumpAndSettle();
        await tester.tap(
          find.text(
            status == 'unpaid'
                ? InvoiceSummaryStrings.unpaidCount(99)
                : InvoiceSummaryStrings.overdueCount(2),
          ),
        );
        await tester.pumpAndSettle();
        expect(find.byType(InvoicesListScreen), findsOneWidget);
        expect(queries.single.toQueryMap()['status'], status);
        expect(queries.single.trashed, 'without');
        router.pop();
        await tester.pumpAndSettle();
        expect(find.byType(HomeInvoiceCounts), findsOneWidget);
        expect(tester.takeException(), isNull);
      },
    );
  }

  testWidgets('counts stay informational without invoice permission', (
    tester,
  ) async {
    await tester.pumpWidget(
      const MaterialApp(
        home: Scaffold(
          body: HomeInvoiceCounts(
            unpaid: 99,
            overdue: 0,
            canViewInvoices: false,
          ),
        ),
      ),
    );
    expect(find.text('99 فاتورة غير مسددة · 0 متأخرة'), findsOneWidget);
    expect(find.byType(TextButton), findsNothing);
  });

  for (final width in [320.0, 1280.0]) {
    testWidgets('counts fit at $width with enlarged Arabic text', (
      tester,
    ) async {
      tester.view.physicalSize = Size(width, 800);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.dark,
          home: const Directionality(
            textDirection: TextDirection.rtl,
            child: MediaQuery(
              data: MediaQueryData(textScaler: TextScaler.linear(2)),
              child: Scaffold(
                body: Padding(
                  padding: EdgeInsets.all(24),
                  child: ColoredBox(
                    color: AppColors.panel,
                    child: HomeInvoiceCounts(
                      unpaid: 12345,
                      overdue: 0,
                      canViewInvoices: true,
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
      );
      expect(find.text(InvoiceSummaryStrings.overdueCount(0)), findsOneWidget);
      expect(find.byType(TextButton), findsNWidgets(2));
      for (final button in find.byType(TextButton).evaluate()) {
        expect(
          tester.getSize(find.byWidget(button.widget)).height,
          greaterThanOrEqualTo(48),
        );
      }
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('unpaid status can be kept when opening invoice filters', (
    tester,
  ) async {
    InvoiceQuery? submitted;
    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('ar'),
        supportedLocales: const [Locale('ar')],
        localizationsDelegates: GlobalMaterialLocalizations.delegates,
        home: Scaffold(
          body: SingleChildScrollView(
            child: InvoiceFiltersSheet(
              query: const InvoiceQuery(status: 'unpaid'),
              billingPeriods: const [],
              squares: const [],
              onSubmit: (query) => submitted = query,
            ),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text(InvoiceSummaryStrings.unpaid), findsOneWidget);
    expect(tester.takeException(), isNull);
    final apply = find.text('عرض النتائج');
    await tester.ensureVisible(apply);
    await tester.tap(apply);
    await tester.pumpAndSettle();
    expect(submitted?.status, 'unpaid');
  });
}
