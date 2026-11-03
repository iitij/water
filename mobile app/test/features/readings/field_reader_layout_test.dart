import 'dart:io';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/l10n/field_experience_strings.dart';
import 'package:water_project/core/l10n/field_reader_strings.dart';
import 'package:water_project/core/theme/app_theme.dart';
import 'package:water_project/core/ui/app_surfaces.dart';
import 'package:water_project/features/meters/data/models/meter.dart';
import 'package:water_project/features/readings/presentation/pages/field_reader_screen.dart';

const _meter = Meter(
  id: 1,
  uniqueCode: '2403823783',
  subscriberName: 'أحمد محمد عبدالله',
  squareName: 'مربع السلام',
  pathNumber: 6,
  lastReading: 1250,
  balance: -10523.5,
  tenantName: 'خالد محمد',
  status: 'inactive',
);
const _previewKey = ValueKey('compact-field-card');

Future<void> _pumpCard(
  WidgetTester tester, {
  required double width,
  double height = 900,
  double scale = 1,
  Meter meter = _meter,
  VoidCallback? onReading,
}) async {
  tester.view.physicalSize = Size(width, height);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);
  await tester.pumpWidget(
    MaterialApp(
      theme: AppTheme.dark,
      locale: const Locale('ar'),
      supportedLocales: const [Locale('ar')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      builder: (context, child) => MediaQuery(
        data: MediaQuery.of(
          context,
        ).copyWith(textScaler: TextScaler.linear(scale)),
        child: child!,
      ),
      home: Scaffold(
        body: RepaintBoundary(
          key: _previewKey,
          child: Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 760),
              child: ListView(
                children: [
                  FieldReaderMeterCard(
                    meter: meter,
                    onRecordReading: onReading ?? () {},
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  setUpAll(() async {
    final fonts = FontLoader('IBMPlexSansArabic');
    for (final weight in ['Regular', 'Medium', 'SemiBold', 'Bold']) {
      fonts.addFont(
        rootBundle.load('assets/fonts/IBMPlexSansArabic-$weight.ttf'),
      );
    }
    await fonts.load();
    final icons = FontLoader('MaterialIcons')
      ..addFont(rootBundle.load('fonts/MaterialIcons-Regular.otf'));
    await icons.load();
  });

  for (final width in [360.0, 412.0, 1280.0]) {
    testWidgets(
      'field card keeps figures compact and a 48dp action at $width',
      (tester) async {
        var opened = 0;
        await _pumpCard(tester, width: width, onReading: () => opened++);
        final card = find.byType(AppCard);
        expect(tester.getSize(card).height, lessThanOrEqualTo(220));
        expect(find.text(_meter.subscriberName!), findsOneWidget);
        expect(find.text(_meter.uniqueCode), findsOneWidget);
        expect(find.text('مربع السلام · المسار 6'), findsOneWidget);
        expect(
          tester.getTopLeft(find.text(FieldExperienceStrings.lastReading)).dy,
          tester.getTopLeft(find.text(FieldExperienceStrings.balance)).dy,
        );
        expect(find.text('-10,523.5'), findsOneWidget);
        final action = find.byType(FilledButton);
        expect(tester.getSize(action).height, greaterThanOrEqualTo(48));
        expect(
          tester.getSize(action).width,
          lessThan(tester.getSize(card).width),
        );
        await tester.tap(action);
        expect(opened, 1);
        await tester.tap(find.text(_meter.subscriberName!));
        expect(opened, 2);
        expect(tester.takeException(), isNull);

        if (const bool.fromEnvironment('WRITE_FIELD_LAYOUT_PREVIEWS')) {
          await tester.runAsync(() async {
            final boundary = tester.renderObject<RenderRepaintBoundary>(
              find.byKey(_previewKey),
            );
            final image = await boundary.toImage();
            final data = await image.toByteData(format: ui.ImageByteFormat.png);
            image.dispose();
            final directory = Directory('build/field-layout-preview')
              ..createSync(recursive: true);
            await File(
              '${directory.path}/card-${width.toInt()}.png',
            ).writeAsBytes(data!.buffer.asUint8List());
          });
        }
      },
    );
  }

  testWidgets('large Arabic text wraps and full meter details stay available', (
    tester,
  ) async {
    final meter = _meter.copyWith(
      subscriberName: 'عبدالرحمن أحمد عبدالله محمد علي صاحب الاشتراك الطويل',
      squareName: 'مربع ذو اسم طويل لاختبار التفاف الموقع في الشاشة الصغيرة',
    );
    var opened = 0;
    await _pumpCard(
      tester,
      width: 320,
      height: 720,
      scale: 2,
      meter: meter,
      onReading: () => opened++,
    );
    expect(tester.takeException(), isNull);
    expect(
      tester.getTopLeft(find.text(FieldExperienceStrings.balance)).dy,
      greaterThan(
        tester.getTopLeft(find.text(FieldExperienceStrings.lastReading)).dy,
      ),
    );
    final action = find.byType(FilledButton);
    expect(tester.getSize(action).height, greaterThanOrEqualTo(48));
    await tester.ensureVisible(find.text(FieldReaderStrings.details));
    await tester.tap(find.text(FieldReaderStrings.details));
    await tester.pumpAndSettle();
    expect(find.text(meter.subscriberName!), findsNWidgets(2));
    expect(find.text(FieldExperienceStrings.inactive), findsOneWidget);
    expect(
      find.text(FieldExperienceStrings.tenantName(_meter.tenantName!)),
      findsOneWidget,
    );
    expect(find.text('-10,523.5'), findsNWidgets(2));
    final sheetAction = find.descendant(
      of: find.byType(BottomSheet),
      matching: find.byType(FilledButton),
    );
    await tester.ensureVisible(sheetAction);
    await tester.tap(sheetAction);
    await tester.pumpAndSettle();
    expect(opened, 1);
    expect(find.byType(BottomSheet), findsNothing);
    expect(tester.takeException(), isNull);
  });
}
