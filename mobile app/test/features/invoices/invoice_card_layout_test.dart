import 'dart:io';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/l10n/collection_ui_strings.dart';
import 'package:water_project/core/theme/app_theme.dart';
import 'package:water_project/features/invoices/data/models/invoice_model.dart';
import 'package:water_project/features/invoices/domain/accounting/finance_enums.dart';
import 'package:water_project/features/invoices/presentation/widgets/invoice_card.dart';

InvoiceModel invoice({
  bool latest = true,
  bool deleted = false,
  double due = 5000,
}) => InvoiceModel(
  id: 12,
  subscriberId: 1,
  invoiceType: InvoiceType.meter,
  billNumber: 'INV-2026-000012',
  subscriber: const InvoiceSubscriberRef(
    id: 1,
    name: 'أحمد محمد عبدالله المشترك',
    phone: '777123456',
  ),
  totalAmount: 160800,
  amountPaid: 155800,
  previousBalance: 10000,
  serverTrueRemaining: due,
  isLatestInvoice: latest,
  deletedAt: deleted ? DateTime(2026, 1, 1) : null,
);

Future<void> showCard(
  WidgetTester tester,
  Widget card, {
  double width = 320,
  double scale = 1,
}) async {
  await tester.binding.setSurfaceSize(Size(width, 1600));
  addTearDown(() => tester.binding.setSurfaceSize(null));
  await tester.pumpWidget(
    MaterialApp(
      home: Directionality(
        textDirection: TextDirection.rtl,
        child: MediaQuery(
          data: MediaQueryData(
            size: Size(width, 1600),
            textScaler: TextScaler.linear(scale),
          ),
          child: Scaffold(
            body: SingleChildScrollView(
              child: Padding(padding: const EdgeInsets.all(12), child: card),
            ),
          ),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

void main() {
  for (final width in <double>[320, 390, 720]) {
    for (final scale in <double>[1, 2]) {
      testWidgets('invoice remains readable in RTL at $width / $scale', (
        tester,
      ) async {
        final value = invoice(due: 123456789.25);
        await showCard(
          tester,
          InvoiceCard(
            invoice: value,
            remainingLabel: remainingLabelFor(value, [value]),
            canCollect: true,
            onCollect: () {},
            onPrint: () {},
            thermalEnabled: true,
            onPrintThermal: () {},
            whatsappEnabled: true,
            onWhatsapp: () {},
            showCheckbox: true,
            onToggleSelect: () {},
            onMoreActions: () {},
          ),
          width: width,
          scale: scale,
        );
        expect(tester.takeException(), isNull);
        final amount = tester.widget<Text>(find.text('123,456,789.25 ريال'));
        expect(amount.maxLines, 1);
        expect(amount.softWrap, false);
        expect(amount.textDirection, TextDirection.ltr);
        expect(find.text(CollectionUiStrings.invoiceValue), findsNothing);
        expect(
          tester.getSize(find.byType(FilledButton)).height,
          greaterThanOrEqualTo(48),
        );
        for (final button in find.byType(IconButton).evaluate()) {
          expect(
            tester.getSize(find.byWidget(button.widget)).height,
            greaterThanOrEqualTo(48),
          );
        }
        await tester.ensureVisible(find.text(CollectionUiStrings.details));
        await tester.tap(find.text(CollectionUiStrings.details));
        await tester.pumpAndSettle();
        expect(find.text(CollectionUiStrings.invoiceValue), findsOneWidget);
        expect(find.text('160,800 ريال'), findsOneWidget);
        expect(tester.takeException(), isNull);
      });
    }
  }

  testWidgets(
    'live remaining is dominant and collection does not quote stale invoice total',
    (tester) async {
      final value = invoice();
      var collected = 0;
      await showCard(
        tester,
        InvoiceCard(
          invoice: value,
          remainingLabel: remainingLabelFor(value, [value]),
          canCollect: canCollectInvoice(value, [value]),
          onCollect: () => collected++,
        ),
      );
      expect(find.text('5,000.00 ريال'), findsOneWidget);
      expect(find.textContaining('160,800'), findsNothing);
      await tester.tap(find.text(CollectionUiStrings.collectPayment));
      expect(collected, 1);
    },
  );

  testWidgets(
    'print, sharing, selection, and detail callbacks remain accessible',
    (tester) async {
      final value = invoice();
      final called = <String>[];
      await showCard(
        tester,
        InvoiceCard(
          invoice: value,
          remainingLabel: remainingLabelFor(value, [value]),
          onTap: () => called.add('details'),
          onPrint: () => called.add('pdf'),
          thermalEnabled: true,
          onPrintThermal: () => called.add('print'),
          whatsappEnabled: true,
          onWhatsapp: () => called.add('share'),
          showCheckbox: true,
          onToggleSelect: () => called.add('select'),
          onMoreActions: () => called.add('more'),
        ),
      );
      for (final tooltip in [
        CollectionUiStrings.pdf,
        CollectionUiStrings.thermalPrint,
        CollectionUiStrings.whatsapp,
        CollectionUiStrings.invoiceOptions,
      ]) {
        await tester.tap(find.byTooltip(tooltip));
      }
      await tester.tap(find.byType(Checkbox));
      await tester.tap(find.text(value.beneficiaryName));
      expect(called, ['pdf', 'print', 'share', 'more', 'select', 'details']);
    },
  );

  testWidgets(
    'historical invoice explains its balance and cannot collect even with callback',
    (tester) async {
      final value = invoice(latest: false);
      var collected = 0;
      await showCard(
        tester,
        InvoiceCard(
          invoice: value,
          remainingLabel: remainingLabelFor(value, [value]),
          canCollect: true,
          onCollect: () => collected++,
          onPrint: () {},
        ),
      );
      expect(find.text(CollectionUiStrings.historicalBalance), findsOneWidget);
      expect(find.text(CollectionUiStrings.collectPayment), findsNothing);
      expect(find.byTooltip(CollectionUiStrings.pdf), findsOneWidget);
      expect(canCollectInvoice(value, [value]), isFalse);
      expect(collected, 0);
    },
  );

  test('deleted and fully settled invoices are ineligible for collection', () {
    final deleted = invoice(deleted: true);
    final settled = invoice(due: 0);
    expect(canCollectInvoice(deleted, [deleted]), isFalse);
    expect(canCollectInvoice(settled, [settled]), isFalse);
  });

  testWidgets(
    'summary total keeps decimals together at phone width and large text',
    (tester) async {
      await showCard(
        tester,
        const InvoicesSummaryBar(
          count: 90,
          openCount: 80,
          overdueCount: 10,
          totalDue: 123456789.25,
        ),
        scale: 2,
      );
      expect(find.text('123,456,789.25 ريال'), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );
  testWidgets(
    'capture actual Arabic dark invoice card preview',
    (tester) async {
      await tester.runAsync(() async {
        final font = FontLoader('IBMPlexSansArabic');
        for (final weight in ['Regular', 'Medium', 'SemiBold', 'Bold']) {
          font.addFont(
            rootBundle.load('assets/fonts/IBMPlexSansArabic-$weight.ttf'),
          );
        }
        await font.load();
        await (FontLoader(
          'MaterialIcons',
        )..addFont(rootBundle.load('fonts/MaterialIcons-Regular.otf'))).load();
      });
      tester.view.physicalSize = const Size(390, 900);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      const key = ValueKey('invoice-clarity-preview');
      final value = invoice();
      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.dark,
          home: Directionality(
            textDirection: TextDirection.rtl,
            child: Scaffold(
              body: SingleChildScrollView(
                child: RepaintBoundary(
                  key: key,
                  child: ColoredBox(
                    color: AppTheme.dark.scaffoldBackgroundColor,
                    child: Padding(
                      padding: const EdgeInsets.all(12),
                      child: InvoiceCard(
                        invoice: value,
                        remainingLabel: remainingLabelFor(value, [value]),
                        canCollect: true,
                        onCollect: () {},
                        onPrint: () {},
                        thermalEnabled: true,
                        onPrintThermal: () {},
                        whatsappEnabled: true,
                        onWhatsapp: () {},
                        onMoreActions: () {},
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
      await tester.runAsync(() async {
        final boundary = tester.renderObject<RenderRepaintBoundary>(
          find.byKey(key),
        );
        final picture = await boundary.toImage(pixelRatio: 2);
        final png = await picture.toByteData(format: ui.ImageByteFormat.png);
        final output = File(
          'build/usability-preview/invoice-card-dark-390.png',
        );
        await output.parent.create(recursive: true);
        await output.writeAsBytes(png!.buffer.asUint8List());
        picture.dispose();
      });
    },
    skip: !const bool.fromEnvironment('GENERATE_CLARITY_PREVIEW'),
  );
}
