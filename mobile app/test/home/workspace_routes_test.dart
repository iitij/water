import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/auth/session_service.dart';
import 'package:water_project/core/router/app_router.dart';
import 'package:water_project/core/router/finance_route_names.dart';
import 'package:water_project/features/home/domain/workspace_catalog.dart';
import 'package:water_project/features/invoices/presentation/pages/invoice_issue_screen.dart';
import 'package:water_project/features/settings/presentation/routes.dart';

class _Session extends Mock implements SessionService {}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('every service matches a registered application route', () {
    final session = _Session();
    when(() => session.isReady).thenReturn(false);
    when(() => session.isAuthenticated).thenReturn(false);
    final router = AppRouter(session: session).router;
    addTearDown(router.dispose);
    for (final entry in WorkspaceCatalog.entries) {
      expect(router.configuration.findMatch(Uri.parse(entry.route)).isError,
        isFalse, reason: 'Missing route for ${entry.title}: ${entry.route}');
    }
    expect(router.configuration.findMatch(Uri.parse('/users')).isError, isTrue);
    expect(router.configuration.findMatch(Uri.parse('/roles')).isError, isTrue);
  });

  test('mobile account administration routes are unavailable', () {
    final session = _Session();
    when(() => session.isReady).thenReturn(false);
    when(() => session.isAuthenticated).thenReturn(false);
    final router = AppRouter(session: session).router;
    addTearDown(router.dispose);
    expect(router.configuration.findMatch(Uri.parse('/users')).isError, isTrue);
    expect(router.configuration.findMatch(Uri.parse('/roles')).isError, isTrue);
  });

  testWidgets('settings route passes the section query to its screen builder', (tester) async {
    final router = GoRouter(
      initialLocation: '/settings?section=reading',
      routes: SettingsRoutes.routes(sectionBuilder: (section) => Scaffold(body: Text('section:$section'))),
    );
    addTearDown(router.dispose);
    await tester.pumpWidget(MaterialApp.router(routerConfig: router));
    await tester.pumpAndSettle();
    expect(find.text('section:reading'), findsOneWidget);
    router.go('/settings?section=invoice');
    await tester.pumpAndSettle();
    expect(find.text('section:invoice'), findsOneWidget);
  });

  for (final choice in {
    'فاتورة عداد': FinanceRouteNames.invoiceGenerateMeter,
    'فاتورة اشتراك': FinanceRouteNames.invoiceGenerateSubscription,
    'فاتورة يدوية': FinanceRouteNames.invoiceCreate,
  }.entries) {
    testWidgets('invoice choice ${choice.key} opens its existing engine', (tester) async {
      final router = GoRouter(
        initialLocation: FinanceRouteNames.invoiceIssue,
        routes: [
          GoRoute(path: FinanceRouteNames.invoiceIssue, builder: (_, __) => const InvoiceIssueScreen()),
          GoRoute(path: choice.value, builder: (_, __) => Scaffold(body: Text('opened:${choice.value}'))),
        ],
      );
      addTearDown(router.dispose);
      await tester.pumpWidget(MaterialApp.router(routerConfig: router));
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.text(choice.key));
      await tester.pumpAndSettle();
      await tester.tap(find.text(choice.key));
      await tester.pumpAndSettle();
      expect(find.text('opened:${choice.value}'), findsOneWidget);
      router.pop();
      await tester.pumpAndSettle();
      expect(find.text('اختر نوع الفاتورة'), findsOneWidget);
    });
  }
}
