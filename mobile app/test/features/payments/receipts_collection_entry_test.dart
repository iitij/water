import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/auth/session_service.dart';
import 'package:water_project/core/l10n/laravel_screen_strings.dart';
import 'package:water_project/core/permissions/permission_service.dart';
import 'package:water_project/core/router/route_names.dart';
import 'package:water_project/features/invoices/domain/repositories/invoices_repository.dart';
import 'package:water_project/features/payments/data/models/payment_model.dart';
import 'package:water_project/features/payments/domain/repositories/payments_repository.dart';
import 'package:water_project/features/payments/presentation/pages/payments_list_screen.dart';

class _Payments extends Mock implements PaymentsRepository {}
class _Invoices extends Mock implements InvoicesRepository {}
class _Session extends Mock implements SessionService {}

void main() {
  setUpAll(() => registerFallbackValue(const PaymentQuery()));

  testWidgets('receipt list opens the collection flow and reloads on return',
      (tester) async {
    final payments = _Payments();
    when(() => payments.list(any())).thenAnswer((_) async => <PaymentModel>[]);
    GetIt.I.registerSingleton<PaymentsRepository>(payments);
    GetIt.I.registerSingleton<InvoicesRepository>(_Invoices());
    final session = _Session();
    when(() => session.user).thenReturn({
      'permissions': ['payments.view', 'payments.collect', 'invoices.view'],
    });
    GetIt.I.registerSingleton<PermissionService>(PermissionService(session));
    addTearDown(GetIt.I.reset);

    final router = GoRouter(
      initialLocation: RouteNames.payments,
      routes: [
        GoRoute(
          path: RouteNames.payments,
          builder: (_, __) => const PaymentsListScreen(),
        ),
        GoRoute(
          path: RouteNames.quickCollector,
          builder: (_, __) => const Scaffold(body: Text('مسار التحصيل')),
        ),
      ],
    );
    addTearDown(router.dispose);
    await tester.pumpWidget(MaterialApp.router(routerConfig: router));
    await tester.pumpAndSettle();
    expect(find.text(LaravelScreenStrings.payments), findsOneWidget);
    expect(find.text('بدء التحصيل'), findsOneWidget);
    expect(find.text('دفعة جديدة'), findsNothing);
    verify(() => payments.list(any())).called(1);

    await tester.tap(find.text('بدء التحصيل'));
    await tester.pumpAndSettle();
    expect(find.text('مسار التحصيل'), findsOneWidget);

    router.pop();
    await tester.pumpAndSettle();
    verify(() => payments.list(any())).called(1);
  });

  testWidgets('receipt viewer cannot start collection without collection access',
      (tester) async {
    final payments = _Payments();
    when(() => payments.list(any())).thenAnswer((_) async => <PaymentModel>[]);
    GetIt.I.registerSingleton<PaymentsRepository>(payments);
    final session = _Session();
    when(() => session.user).thenReturn({'permissions': ['payments.view']});
    GetIt.I.registerSingleton<PermissionService>(PermissionService(session));
    addTearDown(GetIt.I.reset);

    await tester.pumpWidget(const MaterialApp(home: PaymentsListScreen()));
    await tester.pumpAndSettle();
    expect(find.text('بدء التحصيل'), findsNothing);
  });
}
