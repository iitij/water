import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/auth/session_service.dart';
import 'package:water_project/core/fouad/fouad_module.dart';
import 'package:water_project/core/l10n/dashboard_metric_strings.dart';
import 'package:water_project/core/permissions/permission_service.dart';
import 'package:water_project/core/router/finance_routes.dart';
import 'package:water_project/core/theme/app_theme.dart';
import 'package:water_project/core/ui/app_tiles.dart';
import 'package:water_project/features/dashboard/domain/entities/dashboard_entities.dart';
import 'package:water_project/features/dashboard/presentation/widgets/stats_overview_grid.dart';
import 'package:water_project/features/invoices/data/models/invoice_query.dart';
import 'package:water_project/features/invoices/domain/repositories/invoices_repository.dart';
import 'package:water_project/features/invoices/presentation/pages/invoices_list_screen.dart';
import 'package:water_project/features/payments/data/models/payment_model.dart';
import 'package:water_project/features/payments/domain/repositories/payments_repository.dart';
import 'package:water_project/features/payments/presentation/pages/payments_list_screen.dart';
import 'package:water_project/features/subscribers/domain/entities/subscriber.dart';
import 'package:water_project/features/subscribers/domain/repositories/subscribers_repository.dart';
import 'package:water_project/features/subscribers/presentation/pages/subscribers_screen.dart';

class _Session extends Mock implements SessionService {}

class _Invoices extends Mock implements InvoicesRepository {}

class _Payments extends Mock implements PaymentsRepository {}

class _Subscribers extends Mock implements SubscribersRepository {}

const _stats = DashboardStats(
  activeSubscribersCount: 253,
  totalSubscribersCount: 253,
  outstandingAmount: 949950,
  monthlyCollection: 196285,
  overdueInvoicesCount: 8,
  unpaidInvoicesCount: 106,
  readingsThisMonth: 10,
  subscribersSparkline: [0, 1, 0],
  collectionSparkline: [0, 0, 1],
);

void main() {
  setUpAll(() {
    registerFallbackValue(const InvoiceQuery());
    registerFallbackValue(const PaymentQuery());
  });
  setUp(() {
    final session = _Session();
    when(() => session.user).thenReturn({
      'roles': ['admin'],
    });
    GetIt.I.registerSingleton<SessionService>(session);
    GetIt.I.registerSingleton<PermissionService>(PermissionService(session));
  });
  tearDown(() => GetIt.I.reset());

  for (final width in [320.0, 1280.0]) {
    for (final title in [
      DashboardMetricStrings.activeSubscribers,
      DashboardMetricStrings.outstanding,
      DashboardMetricStrings.monthlyRevenue,
      DashboardMetricStrings.overdueInvoices,
    ]) {
      testWidgets('$title opens the real filtered list at $width', (
        tester,
      ) async {
        tester.view.physicalSize = Size(width, 1000);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        final invoices = _Invoices();
        final payments = _Payments();
        final subscribers = _Subscribers();
        final invoiceQueries = <InvoiceQuery>[];
        final paymentQueries = <PaymentQuery>[];
        final subscriberStatuses = <SubscriberStatus?>[];
        when(() => invoices.list(any())).thenAnswer((call) async {
          invoiceQueries.add(call.positionalArguments.single as InvoiceQuery);
          return [];
        });
        when(() => invoices.billingPeriods()).thenAnswer((_) async => []);
        when(() => payments.list(any())).thenAnswer((call) async {
          paymentQueries.add(call.positionalArguments.single as PaymentQuery);
          return [];
        });
        when(() => subscribers.getSquaresLookup()).thenAnswer((_) async => []);
        when(
          () => subscribers.getSubscribers(
            query: any(named: 'query'),
            squareId: any(named: 'squareId'),
            status: any(named: 'status'),
            perPage: any(named: 'perPage'),
          ),
        ).thenAnswer((call) async {
          subscriberStatuses.add(
            call.namedArguments[#status] as SubscriberStatus?,
          );
          return [];
        });
        GetIt.I.registerSingleton<InvoicesRepository>(invoices);
        GetIt.I.registerSingleton<PaymentsRepository>(payments);
        final demo = FouadDependencies.create(demoMode: true);
        GetIt.I.registerSingleton<FouadDependencies>(
          FouadDependencies(
            squares: demo.squares,
            subscribers: subscribers,
            fees: demo.fees,
            transactions: demo.transactions,
          ),
        );
        final router = GoRouter(
          routes: [
            GoRoute(
              path: '/',
              builder: (_, _) => Scaffold(
                body: SingleChildScrollView(
                  child: StatsOverviewGrid(
                    stats: _stats,
                    can: GetIt.I<PermissionService>().can,
                  ),
                ),
              ),
            ),
            ...buildFinanceRoutes(),
            ...FouadModule.routes,
          ],
        );
        addTearDown(router.dispose);
        await tester.pumpWidget(
          MaterialApp.router(
            routerConfig: router,
            theme: AppTheme.light,
            locale: const Locale('ar'),
            supportedLocales: const [Locale('ar')],
            localizationsDelegates: GlobalMaterialLocalizations.delegates,
          ),
        );
        await tester.pumpAndSettle();
        await tester.tap(find.text(title));
        await tester.pumpAndSettle();
        expect(tester.takeException(), isNull);
        if (title == DashboardMetricStrings.activeSubscribers) {
          expect(find.byType(SubscribersScreen), findsOneWidget);
          expect(subscriberStatuses, [SubscriberStatus.active]);
          expect(find.text('الحالة · نشط'), findsOneWidget);
        } else if (title == DashboardMetricStrings.monthlyRevenue) {
          expect(find.byType(PaymentsListScreen), findsOneWidget);
          final now = DateTime.now();
          expect(paymentQueries.single.fromDate, DateTime(now.year, now.month));
          expect(
            paymentQueries.single.toDate,
            DateTime(now.year, now.month + 1, 0),
          );
        } else {
          expect(find.byType(InvoicesListScreen), findsOneWidget);
          expect(
            invoiceQueries.single.status,
            title == DashboardMetricStrings.outstanding ? 'unpaid' : 'overdue',
          );
        }
        router.pop();
        await tester.pumpAndSettle();
        expect(find.byType(StatsOverviewGrid), findsOneWidget);
      });
    }
  }

  testWidgets('cards only enable destinations allowed by the account', (
    tester,
  ) async {
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: StatsOverviewGrid(
            stats: _stats,
            can: (permission) => permission == 'invoices.view',
          ),
        ),
      ),
    );
    final cards = tester
        .widgetList<AppMetricCard>(find.byType(AppMetricCard))
        .toList();
    expect(cards.map((card) => card.onTap != null), [false, true, false, true]);
    expect(tester.takeException(), isNull);
  });
}
