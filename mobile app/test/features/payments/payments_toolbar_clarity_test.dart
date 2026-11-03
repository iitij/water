import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get_it/get_it.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/auth/session_service.dart';
import 'package:water_project/core/l10n/list_clarity_strings.dart';
import 'package:water_project/core/l10n/list_options_strings.dart';
import 'package:water_project/core/permissions/permission_service.dart';
import 'package:water_project/features/invoices/domain/repositories/invoices_repository.dart';
import 'package:water_project/features/payments/data/models/payment_model.dart';
import 'package:water_project/features/payments/domain/repositories/payments_repository.dart';
import 'package:water_project/features/payments/presentation/pages/payments_list_screen.dart';

class _Payments extends Mock implements PaymentsRepository {}

class _Invoices extends Mock implements InvoicesRepository {}

class _Session extends Mock implements SessionService {}

void main() {
  setUpAll(() => registerFallbackValue(const PaymentQuery()));
  tearDown(GetIt.I.reset);

  for (final approver in [false, true]) {
    testWidgets(
      'receipt toolbar keeps primary controls and permission-aware menu (approver=$approver)',
      (tester) async {
        tester.view.physicalSize = const Size(320, 1400);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        final payments = _Payments();
        when(
          () => payments.list(any()),
        ).thenAnswer((_) async => <PaymentModel>[]);
        GetIt.I.registerSingleton<PaymentsRepository>(payments);
        GetIt.I.registerSingleton<InvoicesRepository>(_Invoices());
        final session = _Session();
        when(() => session.user).thenReturn({
          'permissions': [
            'payments.view',
            if (approver) 'payments.void.approve',
          ],
        });
        GetIt.I.registerSingleton<PermissionService>(
          PermissionService(session),
        );
        await tester.pumpWidget(
          const MaterialApp(
            locale: Locale('ar'),
            supportedLocales: [Locale('ar')],
            localizationsDelegates: GlobalMaterialLocalizations.delegates,
            home: PaymentsListScreen(),
          ),
        );
        await tester.pumpAndSettle();
        expect(find.byTooltip(ListOptionsStrings.title), findsOneWidget);
        expect(find.byIcon(Icons.refresh), findsOneWidget);
        expect(find.byIcon(Icons.checklist_rtl), findsNothing);
        expect(find.byIcon(Icons.ios_share_rounded), findsNothing);
        expect(find.text(ListClarityStrings.multiSelect), findsNothing);
        await tester.tap(find.byTooltip(ListClarityStrings.moreActions));
        await tester.pumpAndSettle();
        expect(find.text(ListClarityStrings.multiSelect), findsOneWidget);
        expect(find.text(ListClarityStrings.exportToday), findsOneWidget);
        expect(
          find.text(ListClarityStrings.voidRequests),
          approver ? findsOneWidget : findsNothing,
        );
        await tester.tap(find.text(ListClarityStrings.multiSelect));
        await tester.pumpAndSettle();
        expect(find.text('اختيار سندات'), findsOneWidget);
        await tester.tap(find.byTooltip(ListClarityStrings.moreActions));
        await tester.pumpAndSettle();
        expect(find.text(ListClarityStrings.selectAll), findsOneWidget);
        expect(find.text(ListClarityStrings.deleteSelected), findsOneWidget);
        await tester.tap(find.text(ListClarityStrings.selectAll));
        await tester.pumpAndSettle();
        await tester.tap(find.byIcon(Icons.close));
        await tester.pumpAndSettle();
        expect(find.text('اختيار سندات'), findsNothing);
        verify(() => payments.list(any())).called(1);
        await tester.tap(find.byIcon(Icons.refresh));
        await tester.pumpAndSettle();
        verify(() => payments.list(any())).called(1);
        expect(tester.takeException(), isNull);
      },
    );
  }
}
