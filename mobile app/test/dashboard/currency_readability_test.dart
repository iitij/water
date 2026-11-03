import 'dart:io';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/theme/app_theme.dart';
import 'package:water_project/core/ui/app_tiles.dart';
import 'package:water_project/features/dashboard/domain/entities/dashboard_entities.dart';
import 'package:water_project/features/dashboard/presentation/widgets/stats_overview_grid.dart';

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
  setUpAll(() async {
    final loader = FontLoader('IBMPlexSansArabic');
    for (final weight in ['Regular', 'Medium', 'SemiBold', 'Bold']) {
      loader.addFont(
        rootBundle.load('assets/fonts/IBMPlexSansArabic-$weight.ttf'),
      );
    }
    await loader.load();
  });

  for (final width in [320.0, 390.0, 1280.0]) {
    for (final dark in [false, true]) {
      for (final scale in [1.0, 2.0]) {
        testWidgets(
          'currency stays legible at $width dark=$dark scale=$scale',
          (tester) async {
            tester.view.physicalSize = Size(width, 1100);
            tester.view.devicePixelRatio = 1;
            addTearDown(tester.view.resetPhysicalSize);
            addTearDown(tester.view.resetDevicePixelRatio);
            final key = GlobalKey();
            await tester.pumpWidget(
              MaterialApp(
                theme: dark ? AppTheme.dark : AppTheme.light,
                home: MediaQuery(
                  data: MediaQueryData(textScaler: TextScaler.linear(scale)),
                  child: Directionality(
                    textDirection: TextDirection.rtl,
                    child: Scaffold(
                      body: RepaintBoundary(
                        key: key,
                        child: Padding(
                          padding: const EdgeInsets.all(12),
                          child: StatsOverviewGrid(
                            stats: _stats,
                            can: (_) => false,
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            );
            await tester.pumpAndSettle();

            expect(find.text('ريال'), findsNothing);
            expect(find.text('ر.ي'), findsNWidgets(2));
            for (final label in find.text('ر.ي').evaluate()) {
              final unit = find.byWidget(label.widget);
              final card = find.ancestor(
                of: unit,
                matching: find.byType(AppMetricCard),
              );
              final number = find.descendant(
                of: card,
                matching: find.text((tester.widget<AppMetricCard>(card)).value),
              );
              final unitRect = tester.getRect(unit);
              final numberRect = tester.getRect(number);
              expect(tester.getRect(card).contains(unitRect.topLeft), isTrue);
              expect(
                tester.getRect(card).contains(unitRect.bottomRight),
                isTrue,
              );
              expect(unitRect.right, lessThanOrEqualTo(numberRect.left));
              expect(
                Directionality.of(tester.element(number)),
                TextDirection.ltr,
              );
            }
            expect(tester.takeException(), isNull);

            if (const bool.fromEnvironment('WRITE_CURRENCY_PREVIEWS')) {
              await tester.runAsync(() async {
                final boundary = tester.renderObject<RenderRepaintBoundary>(
                  find.byKey(key),
                );
                final image = await boundary.toImage();
                final bytes = await image.toByteData(
                  format: ui.ImageByteFormat.png,
                );
                image.dispose();
                final directory = Directory('build/currency-preview')
                  ..createSync(recursive: true);
                await File(
                  '${directory.path}/$width-$dark-$scale.png',
                ).writeAsBytes(bytes!.buffer.asUint8List());
              });
            }
          },
        );
      }
    }
  }
}
