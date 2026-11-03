import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/l10n/reading_invoice_strings.dart';
import 'package:water_project/core/ui/app_controls.dart';
import 'package:water_project/features/meters/data/models/meter.dart';
import 'package:water_project/features/readings/data/models/reading.dart';
import 'package:water_project/features/readings/data/reading_fault_log.dart';
import 'package:water_project/features/readings/data/services/reading_invoice_actions_service.dart';
import 'package:water_project/features/readings/domain/readings_repository.dart';
import 'package:water_project/features/readings/presentation/pages/submit_reading_screen.dart';

class _Readings extends Mock implements ReadingsRepository {}

class _Actions extends Mock implements ReadingInvoiceActionsService {}

void main() {
  const meter = Meter(id: 81, uniqueCode: '2025060644', subscriberId: 10);
  const invoice = InvoiceSummary(
    id: 592,
    billNumber: 'INV-202611-0003',
    totalAmount: 47200,
    subscriberPhone: '777488791',
    subscriberName: 'مشترك',
  );
  final start = DateTime(2026, 7);
  final end = DateTime(2026, 7, 31);
  late _Readings readings;
  late _Actions actions;

  setUpAll(() {
    registerFallbackValue(
      ReadingSubmission(
        meterId: 81,
        readingDate: end,
        periodStart: start,
        periodEnd: end,
        previousReading: 100,
        currentReading: 120,
      ),
    );
  });

  setUp(() {
    readings = _Readings();
    actions = _Actions();
    when(() => readings.getMeterPeriod(meter)).thenAnswer(
      (_) async => MeterPeriodResponse(
        lastReading: 100,
        periodStart: start,
        periodEnd: end,
        autoIssueInvoice: true,
      ),
    );
    when(() => readings.submitReading(any())).thenAnswer(
      (_) async => ReadingSaveResult(
        reading: Reading(
          id: 1,
          meterId: 81,
          readingDate: end,
          periodStart: start,
          periodEnd: end,
          previousReading: 100,
          currentReading: 120,
          consumptionUnits: 20,
        ),
        isPendingSync: false,
        invoiceIssued: true,
        invoice: invoice,
      ),
    );
    when(() => actions.sendSms(invoice)).thenAnswer((_) async {});
    when(() => actions.printInvoice(invoice)).thenAnswer((_) async {});
    when(() => actions.shareOnWhatsApp(invoice)).thenAnswer((_) async {});
  });

  Future<void> save(WidgetTester tester, double width) async {
    tester.view.physicalSize = Size(width, 1100);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('ar'),
        supportedLocales: const [Locale('ar')],
        localizationsDelegates: GlobalMaterialLocalizations.delegates,
        home: SubmitReadingScreen(
          meter: meter,
          readingsRepository: readings,
          invoiceActions: actions,
          faultLog: InMemoryReadingFaultLog(),
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text(ReadingInvoiceStrings.sms), findsNothing);
    await tester.enterText(find.byType(TextFormField).first, '120');
    await tester.ensureVisible(find.text('حفظ القراءة'));
    await tester.tap(find.text('حفظ القراءة'));
    await tester.pumpAndSettle();
  }

  for (final width in [320.0, 390.0, 1440.0]) {
    testWidgets('issued invoice offers SMS and image sharing at $width px', (
      tester,
    ) async {
      await save(tester, width);
      expect(find.text(ReadingInvoiceStrings.sms), findsOneWidget);
      expect(find.text(ReadingInvoiceStrings.whatsapp), findsOneWidget);
      expect(find.text(ReadingInvoiceStrings.sharingHelp), findsOneWidget);
      await tester.ensureVisible(find.text('طباعة الفاتورة'));
      await tester.tap(find.text('طباعة الفاتورة'));
      await tester.pumpAndSettle();
      verify(() => actions.printInvoice(invoice)).called(1);
      await tester.ensureVisible(find.text(ReadingInvoiceStrings.sms));
      await tester.tap(find.text(ReadingInvoiceStrings.sms));
      await tester.pumpAndSettle();
      verify(() => actions.sendSms(invoice)).called(1);
      await tester.ensureVisible(find.text(ReadingInvoiceStrings.whatsapp));
      await tester.tap(find.text(ReadingInvoiceStrings.whatsapp));
      await tester.pumpAndSettle();
      verify(() => actions.shareOnWhatsApp(invoice)).called(1);
    });
  }

  testWidgets('sharing locks messaging actions until preparation finishes', (
    tester,
  ) async {
    final pending = Completer<void>();
    when(
      () => actions.shareOnWhatsApp(invoice),
    ).thenAnswer((_) => pending.future);
    await save(tester, 390);
    await tester.ensureVisible(find.text(ReadingInvoiceStrings.whatsapp));
    await tester.tap(find.text(ReadingInvoiceStrings.whatsapp));
    await tester.pump();
    for (final label in [
      ReadingInvoiceStrings.whatsapp,
      ReadingInvoiceStrings.sms,
      'طباعة الفاتورة',
    ]) {
      final button = tester.widget<AppSecondaryButton>(
        find.widgetWithText(AppSecondaryButton, label),
      );
      expect(button.onPressed, isNull);
    }
    pending.complete();
    await tester.pumpAndSettle();
    expect(
      tester
          .widget<AppSecondaryButton>(
            find.widgetWithText(AppSecondaryButton, ReadingInvoiceStrings.sms),
          )
          .onPressed,
      isNotNull,
    );
  });

  testWidgets('SMS failure is shown without a sent confirmation', (
    tester,
  ) async {
    when(
      () => actions.sendSms(invoice),
    ).thenThrow(StateError(ReadingInvoiceStrings.smsUnavailable));
    await save(tester, 390);
    await tester.ensureVisible(find.text(ReadingInvoiceStrings.sms));
    await tester.tap(find.text(ReadingInvoiceStrings.sms));
    await tester.pumpAndSettle();
    expect(find.text(ReadingInvoiceStrings.smsUnavailable), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}
