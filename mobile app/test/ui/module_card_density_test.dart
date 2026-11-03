import 'dart:io';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/navigation/module_catalog.dart';
import 'package:water_project/core/theme/app_theme.dart';
import 'package:water_project/core/ui/app_tiles.dart';
import 'package:water_project/features/navigation/presentation/widgets/module_section.dart';

void main() {
  setUpAll(() async {
    final font = FontLoader('IBMPlexSansArabic');
    for (final weight in ['Regular', 'Medium', 'SemiBold']) {
      font.addFont(
        rootBundle.load('assets/fonts/IBMPlexSansArabic-$weight.ttf'),
      );
    }
    await font.load();
    await (FontLoader(
      'MaterialIcons',
    )..addFont(rootBundle.load('fonts/MaterialIcons-Regular.otf'))).load();
  });
  for (final dark in [false, true]) {
    for (final width in [320.0, 390.0, 768.0, 1280.0]) {
      for (final scale in [1.0, 1.6]) {
        testWidgets('module cards: $width, text $scale, dark $dark', (
          tester,
        ) async {
          tester.view.physicalSize = Size(width, 1800);
          tester.view.devicePixelRatio = 1;
          addTearDown(tester.view.resetPhysicalSize);
          addTearDown(tester.view.resetDevicePixelRatio);
          final modules = ModuleCatalog.modules;
          String? opened;
          await tester.pumpWidget(
            MaterialApp(
              theme: dark ? AppTheme.dark : AppTheme.light,
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
                body: SingleChildScrollView(
                  padding: const EdgeInsets.all(16),
                  child: AppModuleGrid(
                    children: [
                      for (final module in modules)
                        AppModuleTile(
                          title: module.title,
                          subtitle: module.subtitle,
                          icon: module.icon,
                          color: module.color,
                          onTap: () => opened = module.route,
                        ),
                    ],
                  ),
                ),
              ),
            ),
          );
          await tester.pumpAndSettle();
          expect(tester.takeException(), isNull);
          final rows = <double, Size>{};
          for (final module in modules) {
            final title = find.text(module.title);
            final tile = find
                .ancestor(of: title, matching: find.byType(AppModuleTile))
                .first;
            final size = tester.getSize(tile);
            final top = tester.getTopLeft(tile).dy;
            final rowSize = rows.putIfAbsent(top, () => size);
            expect(
              size.height,
              closeTo(rowSize.height, .01),
              reason: module.title,
            );
            expect(
              size.width,
              closeTo(rowSize.width, .01),
              reason: module.title,
            );
            expect(size.height, greaterThanOrEqualTo(48));
            if (scale == 1 && width <= 390) {
              expect(size.height, lessThan(112), reason: module.title);
            }
            // No forced one-line titles or clipped descriptions at large text sizes.
            expect(tester.widget<Text>(title).maxLines, isNull);
          }
          final first = modules.first;
          await tester.tap(find.text(first.title));
          await tester.pumpAndSettle();
          expect(opened, first.route);
        });
      }
    }
  }

  testWidgets('rail grows with text and remains horizontally scrollable', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(320, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.dark,
        home: MediaQuery(
          data: const MediaQueryData(textScaler: TextScaler.linear(2)),
          child: Directionality(
            textDirection: TextDirection.rtl,
            child: Scaffold(
              body: ModuleRailSection(
                modules: ModuleCatalog.modules,
                title: 'أدوات الميدان',
                onViewAll: () {},
              ),
            ),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    final rail = find.byType(AppModuleRail);
    final tiles = find.descendant(
      of: rail,
      matching: find.byType(AppModuleTile),
    );
    final firstSize = tester.getSize(tiles.first);
    for (var index = 1; index < tiles.evaluate().length; index++) {
      expect(tester.getSize(tiles.at(index)), firstSize);
    }
    final scrollable = find.descendant(
      of: rail,
      matching: find.byType(Scrollable),
    );
    final position = tester.state<ScrollableState>(scrollable).position;
    expect(position.maxScrollExtent, greaterThan(0));
    await tester.drag(rail, const Offset(250, 0));
    await tester.pumpAndSettle();
    expect(position.pixels, greaterThan(0));
    expect(tester.takeException(), isNull);
  });

  if (const bool.fromEnvironment('WRITE_MODULE_PREVIEWS')) {
    for (final (width, dark) in [
      (390.0, true),
      (390.0, false),
      (1280.0, true),
    ]) {
      testWidgets('preview $width dark $dark', (tester) async {
        tester.view.physicalSize = Size(width, 1400);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        const key = ValueKey('module-preview');
        await tester.pumpWidget(
          MaterialApp(
            theme: dark ? AppTheme.dark : AppTheme.light,
            locale: const Locale('ar'),
            supportedLocales: const [Locale('ar')],
            localizationsDelegates: GlobalMaterialLocalizations.delegates,
            home: RepaintBoundary(
              key: key,
              child: Scaffold(
                body: SingleChildScrollView(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    children: [
                      for (final group in [
                        AppModuleGroup.field,
                        AppModuleGroup.billing,
                        AppModuleGroup.crm,
                      ]) ...[
                        ModuleGridSection(
                          title: group.label,
                          modules: ModuleCatalog.modules
                              .where((m) => m.group == group)
                              .toList(),
                        ),
                        const SizedBox(height: 24),
                      ],
                    ],
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
          final picture = await boundary.toImage();
          final png = await picture.toByteData(format: ui.ImageByteFormat.png);
          final file = File(
            'build/module-density-preview/$width-${dark ? 'dark' : 'light'}.png',
          );
          await file.parent.create(recursive: true);
          await file.writeAsBytes(png!.buffer.asUint8List());
          picture.dispose();
        });
      });
    }
  }
}
