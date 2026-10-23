import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/router/route_names.dart';
import 'package:water_project/core/theme/app_theme.dart';
import 'package:water_project/features/home/presentation/home_screen.dart';
import 'package:water_project/features/home/presentation/services_screen.dart';
import 'package:water_project/features/home/presentation/widgets/workspace_widgets.dart';

Widget _app(Widget child, {double scale = 1, bool dark = false}) => MaterialApp(
  locale: const Locale('ar'),
  supportedLocales: const [Locale('ar')],
  localizationsDelegates: GlobalMaterialLocalizations.delegates,
  theme: dark ? AppTheme.dark : AppTheme.light,
  home: Builder(builder: (context) => MediaQuery(
    data: MediaQuery.of(context).copyWith(textScaler: TextScaler.linear(scale)),
    child: Scaffold(body: child),
  )),
);

void main() {
  testWidgets('home is task-first and shortcuts open the correct workflow', (tester) async {
    final opened = <String>[];
    await tester.pumpWidget(_app(HomeWorkspace(
      name: 'أحمد', can: (_) => true, online: true,
      pendingCount: 0, failedCount: 0, onNavigate: opened.add,
    )));
    expect(find.text('أهلاً، أحمد'), findsOneWidget);
    expect(find.text('صلاحيات حسابك'), findsNothing);
    expect(find.text('لا توجد عمليات معلقة'), findsOneWidget);
    await tester.ensureVisible(find.text('تسجيل قراءة'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('تسجيل قراءة'));
    expect(opened.last, RouteNames.fieldReader);
    await tester.ensureVisible(find.text('إصدار فاتورة'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('إصدار فاتورة'));
    expect(opened.last, RouteNames.invoiceIssue);
  });

  testWidgets('home search and sync status are actionable, including failures', (tester) async {
    final opened = <String>[];
    await tester.pumpWidget(_app(HomeWorkspace(
      name: 'سارة', can: (_) => false, online: false,
      pendingCount: 3, failedCount: 1, onNavigate: opened.add,
    )));
    expect(find.text('غير متصل بالشبكة'), findsOneWidget);
    expect(find.text('1 عملية تحتاج مراجعة'), findsOneWidget);
    expect(find.text('تسجيل قراءة'), findsNothing);
    await tester.tap(find.text('ابحث عن خدمة…'));
    expect(opened.last, '${RouteNames.services}?search=1');
    await tester.tap(find.text('1 عملية تحتاج مراجعة'));
    expect(opened.last, RouteNames.offlineSync);
  });

  testWidgets('service search supports Arabic and direct settings navigation', (tester) async {
    final opened = <String>[];
    await tester.pumpWidget(_app(ServicesDirectory(can: (_) => true, onNavigate: opened.add)));
    await tester.enterText(find.byType(TextField), 'واتس اب');
    await tester.pumpAndSettle();
    expect(find.text('الواتساب'), findsOneWidget);
    expect(find.text('المستخدمون'), findsNothing);
    await tester.ensureVisible(find.text('الواتساب'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('الواتساب'));
    expect(opened.single, '${RouteNames.settings}?section=whatsapp');
  });

  testWidgets('empty results can be reset and category filters apply', (tester) async {
    await tester.pumpWidget(_app(ServicesDirectory(can: (_) => true, onNavigate: (_) {})));
    await tester.enterText(find.byType(TextField), 'zzzzz');
    await tester.pumpAndSettle();
    expect(find.text('لم نجد خدمة بهذا الاسم'), findsOneWidget);
    await tester.ensureVisible(find.text('عرض كل الخدمات'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('عرض كل الخدمات'));
    await tester.pumpAndSettle();
    expect(find.text('لم نجد خدمة بهذا الاسم'), findsNothing);
    await tester.ensureVisible(find.widgetWithText(ChoiceChip, 'الإعدادات'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(ChoiceChip, 'الإعدادات'));
    await tester.pumpAndSettle();
    expect(find.text('5 خدمة متاحة'), findsOneWidget);
    expect(find.text('المشتركون'), findsNothing);
  });

  testWidgets('directory updates immediately when permissions change', (tester) async {
    await tester.pumpWidget(_app(ServicesDirectory(can: (_) => true, onNavigate: (_) {})));
    await tester.enterText(find.byType(TextField), 'المستخدمون');
    await tester.pumpAndSettle();
    // `find.text` also matches the search field's EditableText, so assert on the tile.
    expect(find.widgetWithText(WorkspaceTile, 'المستخدمون'), findsOneWidget);
    await tester.pumpWidget(_app(ServicesDirectory(can: (_) => false, onNavigate: (_) {})));
    await tester.pumpAndSettle();
    expect(find.widgetWithText(WorkspaceTile, 'المستخدمون'), findsNothing);
    expect(find.text('0 خدمة متاحة'), findsOneWidget);
  });

  for (final width in [320.0, 390.0, 1024.0]) {
    for (final scale in [1.0, 2.0]) {
      testWidgets('RTL home and directory fit width $width at text scale $scale', (tester) async {
        tester.view.physicalSize = Size(width, 1200);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        await tester.pumpWidget(_app(HomeWorkspace(
          name: 'اسم مستخدم طويل لاختبار الواجهة', can: (_) => true, online: null,
          pendingCount: 12, failedCount: 0, onNavigate: (_) {},
        ), scale: scale, dark: true));
        await tester.pumpAndSettle();
        expect(tester.takeException(), isNull);
        await tester.pumpWidget(_app(ServicesDirectory(can: (_) => true, onNavigate: (_) {}), scale: scale));
        await tester.pumpAndSettle();
        expect(tester.takeException(), isNull);
      });
    }
  }
}
