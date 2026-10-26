import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/l10n/app_strings.dart';
import 'package:water_project/core/permissions/permission_service.dart';
import 'package:water_project/features/meters/data/models/meter.dart';
import 'package:water_project/features/meters/domain/meters_repository.dart';
import 'package:water_project/features/meters/presentation/pages/meter_form_screen.dart';
import 'package:water_project/features/squares/domain/entities/square.dart';
import 'package:water_project/features/subscribers/domain/entities/subscriber.dart';
import 'package:water_project/features/subscribers/domain/repositories/subscribers_repository.dart';
import 'package:water_project/features/subscribers/presentation/pages/subscriber_form_screen.dart';
import 'package:water_project/features/subscription_fees/domain/entities/subscription_fee.dart';
import 'package:water_project/features/subscription_fees/domain/repositories/subscription_fees_repository.dart';
import 'package:water_project/features/subscription_fees/presentation/widgets/setup_fee_dialog.dart';

class _Subscribers extends Mock implements SubscribersRepository {}
class _Meters extends Mock implements MetersRepository {}
class _Fees extends Mock implements SubscriptionFeesRepository {}
class _Permissions extends Mock implements PermissionService {}

void main() {
  late _Subscribers subscribers;
  late _Meters meters;
  late _Fees fees;
  late _Permissions permissions;
  final operations = <String>[];
  final strings = AppStrings.ar;

  setUpAll(() {
    registerFallbackValue(const Subscriber(id: 0, name: 'draft'));
    registerFallbackValue(const MeterDraft(uniqueCode: '', subscriberId: 0));
  });

  setUp(() {
    operations.clear();
    subscribers = _Subscribers();
    meters = _Meters();
    fees = _Fees();
    permissions = _Permissions();
    when(() => permissions.can(any())).thenReturn(true);
    when(() => subscribers.getSquaresLookup()).thenAnswer((_) async => const [Square(id: 7, name: 'المربع الأول')]);
    when(() => subscribers.createSubscriber(any())).thenAnswer((call) async {
      operations.add('subscriber');
      return const Subscriber(id: 101, name: 'مشترك التجربة', squareId: 7);
    });
    const option = SubscriberOption(id: 101, name: 'مشترك التجربة', squareId: 7, meterCount: 1);
    when(() => meters.getSubscribers()).thenAnswer((_) async => [option]);
    when(() => meters.getSubscriber(101)).thenAnswer((_) async => option);
    when(() => meters.saveMeter(any(), meterId: null)).thenAnswer((call) async {
      operations.add('meter');
      final draft = call.positionalArguments.first as MeterDraft;
      expect(draft.subscriberId, 101);
      return const Meter(id: 501, uniqueCode: 'TEST-501', subscriberId: 101, squareId: 7);
    });
    when(() => fees.createFee(subscriberId: 101, meterId: 501, amount: 300, notes: null))
        .thenAnswer((_) async {
      operations.add('fee');
      return const SubscriptionFee(id: 701, subscriberId: 101, meterId: 501, amount: 300);
    });
  });

  Future<void> mount(WidgetTester tester) async {
    tester.view.physicalSize = const Size(1000, 1600);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(MaterialApp(locale: const Locale('ar'),
      supportedLocales: const [Locale('ar')], localizationsDelegates: GlobalMaterialLocalizations.delegates,
      home: Scaffold(body: Builder(builder: (context) => TextButton(
        child: const Text('open'),
        onPressed: () => Navigator.push(context, MaterialPageRoute<void>(builder: (_) => SubscriberFormScreen(
          initialSquareId: 7, repository: subscribers, metersRepository: meters,
          feesRepository: fees, permissions: permissions,
        ))),
      )))));
    await tester.tap(find.text('open'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 500));
    await tester.enterText(find.byType(TextFormField).first, 'مشترك التجربة');
  }

  Future<void> saveSubscriberAndMeter(WidgetTester tester) async {
    await tester.ensureVisible(find.text(strings.saveAndAddMeter));
    await tester.tap(find.text(strings.saveAndAddMeter));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 500));
    expect(find.byType(MeterFormScreen), findsOneWidget);
    expect(operations, ['subscriber']);
    await tester.enterText(find.byType(TextFormField).first, 'TEST-501');
    await tester.ensureVisible(find.text('حفظ بيانات العداد'));
    await tester.tap(find.text('حفظ بيانات العداد'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 500));
  }

  testWidgets('subscriber then meter then fee use the saved server identities', (tester) async {
    await mount(tester);
    await saveSubscriberAndMeter(tester);
    expect(find.byType(SetupFeeDialog), findsOneWidget);
    final dialog = tester.widget<SetupFeeDialog>(find.byType(SetupFeeDialog));
    expect(dialog.subscriberId, 101);
    expect(dialog.meterId, 501);
    await tester.enterText(find.descendant(of: find.byType(SetupFeeDialog), matching: find.byType(TextFormField)).first, '٣٠٠');
    await tester.tap(find.widgetWithText(FilledButton, strings.save));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 500));
    expect(operations, ['subscriber', 'meter', 'fee']);
    expect(find.text('open'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('cancelling the meter preserves one subscriber and avoids duplicate creation', (tester) async {
    await mount(tester);
    await tester.ensureVisible(find.text(strings.saveAndAddMeter));
    await tester.tap(find.text(strings.saveAndAddMeter));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 500));
    await tester.binding.handlePopRoute();
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 500));
    expect(operations, ['subscriber']);
    expect(find.byType(SubscriberFormScreen), findsNothing);
    verify(() => subscribers.createSubscriber(any())).called(1);
  });

  testWidgets('fee failure leaves the final step open without recreating prior records', (tester) async {
    when(() => fees.createFee(subscriberId: 101, meterId: 501, amount: 300, notes: null))
        .thenThrow(Exception('تعذر حفظ رسوم الاشتراك'));
    await mount(tester);
    await saveSubscriberAndMeter(tester);
    await tester.enterText(find.descendant(of: find.byType(SetupFeeDialog), matching: find.byType(TextFormField)).first, '300');
    await tester.tap(find.widgetWithText(FilledButton, strings.save));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 500));
    expect(find.byType(SetupFeeDialog), findsOneWidget);
    expect(operations, ['subscriber', 'meter']);
    verify(() => subscribers.createSubscriber(any())).called(1);
    verify(() => meters.saveMeter(any(), meterId: null)).called(1);
    await tester.tap(find.text(strings.setupSkipFee));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 500));
    expect(find.byType(SubscriberFormScreen), findsNothing);
  });

  testWidgets('missing meter permission hides the chained creation action', (tester) async {
    when(() => permissions.can('Create:Meter')).thenReturn(false);
    await mount(tester);
    expect(find.text(strings.saveAndAddMeter), findsNothing);
    verifyNever(() => subscribers.createSubscriber(any()));
  });
}
