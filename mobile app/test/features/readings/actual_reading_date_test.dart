import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/features/meters/data/models/meter.dart';
import 'package:water_project/features/readings/data/models/reading.dart';
import 'package:water_project/features/readings/data/reading_fault_log.dart';
import 'package:water_project/features/readings/domain/readings_repository.dart';
import 'package:water_project/features/readings/presentation/pages/submit_reading_screen.dart';

class _Readings extends Mock implements ReadingsRepository {}

void main() {
  final start = DateTime(2026, 9, 26);
  final end = DateTime(2026, 10, 26);
  const meter = Meter(id: 81, uniqueCode: 'TEST-81', subscriberId: 10);
  late _Readings repository;

  setUpAll(() {
    registerFallbackValue(ReadingSubmission(meterId: 81, readingDate: end,
      periodStart: start, periodEnd: end, previousReading: 10, currentReading: 15));
  });

  setUp(() {
    repository = _Readings();
    when(() => repository.getMeterPeriod(meter)).thenAnswer((_) async =>
      MeterPeriodResponse(lastReading: 10, periodStart: start, periodEnd: end));
  });

  Future<void> mount(WidgetTester tester) async {
    tester.view.physicalSize = const Size(1000, 1800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(MaterialApp(locale: const Locale('ar'),
      supportedLocales: const [Locale('ar')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      home: SubmitReadingScreen(meter: meter, readingsRepository: repository,
        faultLog: InMemoryReadingFaultLog())));
    await tester.pumpAndSettle();
  }

  testWidgets('choosing the actual date changes measured billing end and disables changes during saving', (tester) async {
    final pending = Completer<ReadingSaveResult>();
    ReadingSubmission? sent;
    when(() => repository.submitReading(any())).thenAnswer((call) {
      sent = call.positionalArguments.single as ReadingSubmission;
      return pending.future;
    });
    await mount(tester);
    await tester.tap(find.byKey(const ValueKey('actual-reading-date')));
    await tester.pumpAndSettle();
    final picker = tester.widget<CalendarDatePicker>(find.byType(CalendarDatePicker));
    final initial = picker.initialDate!;
    final selected = DateTime(initial.year, initial.month, initial.day == 1 ? 2 : 1);
    final locale = MaterialLocalizations.of(tester.element(find.byType(CalendarDatePicker)));
    await tester.tap(find.text(locale.formatDecimal(selected.day)).last);
    await tester.tap(find.text(locale.okButtonLabel));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextFormField).first, '١٥');
    await tester.ensureVisible(find.text('حفظ القراءة'));
    await tester.tap(find.text('حفظ القراءة'));
    await tester.pump();
    expect(sent, isNotNull);
    expect(sent!.readingDate, selected);
    expect(sent!.periodStart, start);
    expect(sent!.periodEnd, selected);
    expect(sent!.previousReading, 10);
    expect(sent!.currentReading, 15);
    expect(tester.widget<InkWell>(find.byKey(const ValueKey('actual-reading-date'))).onTap, isNull);
    pending.complete(ReadingSaveResult(reading: Reading(id: 1, meterId: 81,
      readingDate: selected, periodStart: start, periodEnd: selected,
      previousReading: 10, currentReading: 15, consumptionUnits: 5), isPendingSync: false));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
  });

  testWidgets('canceling the actual-date picker leaves the date unchanged', (tester) async {
    await mount(tester);
    final control = find.byKey(const ValueKey('actual-reading-date'));
    final before = tester.widgetList<Text>(find.descendant(of: control, matching: find.byType(Text)))
      .map((text) => text.data).toList();
    await tester.tap(control);
    await tester.pumpAndSettle();
    final locale = MaterialLocalizations.of(tester.element(find.byType(CalendarDatePicker)));
    await tester.tap(find.text(locale.cancelButtonLabel));
    await tester.pumpAndSettle();
    expect(tester.widgetList<Text>(find.descendant(of: control, matching: find.byType(Text)))
      .map((text) => text.data).toList(), before);
    verifyNever(() => repository.submitReading(any()));
  });
}
