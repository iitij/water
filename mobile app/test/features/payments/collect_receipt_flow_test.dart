import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get_it/get_it.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/finance/finance_module.dart';
import 'package:water_project/core/settings/finance_config.dart';
import 'package:water_project/core/theme/app_theme.dart';
import 'package:water_project/features/invoices/data/datasources/references_remote_datasource.dart';
import 'package:water_project/features/invoices/domain/accounting/finance_enums.dart';
import 'package:water_project/features/invoices/domain/repositories/invoices_repository.dart';
import 'package:water_project/features/payments/data/models/collect_payment_models.dart';
import 'package:water_project/features/payments/data/models/payment_model.dart';
import 'package:water_project/features/payments/domain/repositories/payments_repository.dart';
import 'package:water_project/features/payments/presentation/cubit/collect_payment_cubit.dart';
import 'package:water_project/features/payments/presentation/cubit/collect_payment_state.dart';
import 'package:water_project/features/payments/presentation/sheets/receipt_success_sheet.dart';
import 'package:water_project/features/payments/presentation/widgets/collect_payment_form.dart';

class _Invoices extends Mock implements InvoicesRepository {}

class _Payments extends Mock implements PaymentsRepository {}

class _References extends Mock implements ReferencesRemoteDataSource {}

class _Config extends Mock implements FinanceConfigRepository {}

/// Starts with no outcome and publishes one only after the asynchronous save.
class _Submission extends CollectPaymentCubit {
  _Submission()
    : super(
        paymentsRepository: _Payments(),
        references: _References(),
        meterId: null,
      );
  int submissions = 0;

  @override
  Future<bool> submit() async {
    submissions++;
    await Future<void>.delayed(Duration.zero);
    emit(
      state.copyWith(
        outcome: const CollectPaymentOutcome(
          payment: PaymentModel(
            id: 88,
            subscriberId: 5,
            receiptNumber: 'R-88',
            amount: 100,
            method: PaymentMethod.cash,
            subscriberName: 'أحمد صاحب السند',
          ),
        ),
      ),
    );
    return true;
  }
}

void main() {
  setUp(() {
    final config = _Config();
    when(
      () => config.cached,
    ).thenReturn(const FinanceConfig(whatsappEnabled: false));
    GetIt.I.registerSingleton<InvoicesRepository>(_Invoices());
    GetIt.I.registerSingleton<FinanceConfigRepository>(config);
  });
  tearDown(() async {
    await GetIt.I.reset();
    FinanceModule.resetForTests();
  });

  testWidgets(
    'optional payment details retain entered reason and notes across collapse',
    (tester) async {
      tester.view.physicalSize = const Size(600, 1400);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final cubit = _Submission();
      addTearDown(cubit.close);
      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.light,
          locale: const Locale('ar'),
          supportedLocales: const [Locale('ar')],
          localizationsDelegates: GlobalMaterialLocalizations.delegates,
          home: Scaffold(
            body: BlocProvider<CollectPaymentCubit>.value(
              value: cubit,
              child: const CollectPaymentForm(),
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.text('تفاصيل إضافية'));
      await tester.tap(find.text('تفاصيل إضافية'));
      await tester.pumpAndSettle();
      final reason = find.descendant(
        of: find.byType(ReasonField),
        matching: find.byType(TextField),
      );
      final notes = find.descendant(
        of: find.byType(NotesField),
        matching: find.byType(TextField),
      );
      await tester.enterText(reason, 'سداد شهر سبتمبر');
      await tester.enterText(notes, 'تسليم نقدي للمحصل');
      await tester.ensureVisible(find.text('تفاصيل إضافية'));
      await tester.tap(find.text('تفاصيل إضافية'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('تفاصيل إضافية'));
      await tester.pumpAndSettle();
      expect(cubit.state.reason, 'سداد شهر سبتمبر');
      expect(cubit.state.notes, 'تسليم نقدي للمحصل');
      expect(
        tester.widget<TextField>(reason).controller?.text,
        'سداد شهر سبتمبر',
      );
      expect(
        tester.widget<TextField>(notes).controller?.text,
        'تسليم نقدي للمحصل',
      );
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets(
    'receipt with a long subscriber name fits a small phone at 2x text',
    (tester) async {
      tester.view.physicalSize = const Size(320, 800);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.light,
          locale: const Locale('ar'),
          supportedLocales: const [Locale('ar')],
          localizationsDelegates: GlobalMaterialLocalizations.delegates,
          home: Builder(
            builder: (context) => MediaQuery(
              data: MediaQuery.of(
                context,
              ).copyWith(textScaler: const TextScaler.linear(2)),
              child: const Scaffold(
                body: ReceiptSuccessSheet(
                  payment: PaymentModel(
                    id: 91,
                    subscriberId: 5,
                    receiptNumber: 'R-91',
                    amount: 12345678,
                    method: PaymentMethod.cash,
                    subscriberName:
                        'عبدالرحمن محمد عبدالله صاحب الاشتراك في حي السلام',
                  ),
                ),
              ),
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
      await tester.ensureVisible(find.text('تم'));
      expect(tester.takeException(), isNull);
    },
  );

  for (final dismissByButton in [true, false]) {
    testWidgets(
      'new receipt appears and completion fires once after ${dismissByButton ? 'done' : 'back'}',
      (tester) async {
        tester.view.physicalSize = const Size(390, 1000);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        final cubit = _Submission();
        addTearDown(cubit.close);
        var completed = 0;
        await tester.pumpWidget(
          MaterialApp(
            theme: AppTheme.light,
            locale: const Locale('ar'),
            supportedLocales: const [Locale('ar')],
            localizationsDelegates: GlobalMaterialLocalizations.delegates,
            home: Scaffold(
              body: CollectSubmitButton(
                state: const CollectPaymentState(amount: 100, dueAmount: 100),
                cubit: cubit,
                onSubmitted: (_) => completed++,
              ),
            ),
          ),
        );
        expect(cubit.state.outcome, isNull);
        await tester.tap(find.text('تحصيل 100 ريال'));
        await tester.pumpAndSettle();
        expect(find.byType(ReceiptSuccessSheet), findsOneWidget);
        expect(find.text('سند قبض R-88'), findsOneWidget);
        expect(find.text('تم التحصيل بنجاح'), findsOneWidget);
        expect(completed, 0);
        expect(cubit.submissions, 1);
        if (dismissByButton) {
          await tester.ensureVisible(find.text('تم'));
          await tester.tap(find.text('تم'));
        } else {
          Navigator.of(tester.element(find.byType(ReceiptSuccessSheet))).pop();
        }
        await tester.pumpAndSettle();
        expect(find.byType(ReceiptSuccessSheet), findsNothing);
        expect(completed, 1);
        expect(cubit.submissions, 1);
        expect(tester.takeException(), isNull);
      },
    );
  }
}
