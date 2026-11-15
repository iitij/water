import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/l10n/laravel_screen_strings.dart';
import 'package:water_project/core/l10n/subscriber_experience_strings.dart';
import 'package:water_project/core/navigation/module_catalog.dart';
import 'package:water_project/core/router/route_names.dart';
import 'package:water_project/core/theme/app_theme.dart';
import 'package:water_project/features/home/domain/workspace_catalog.dart';
import 'package:water_project/features/home/presentation/services_screen.dart';
import 'package:water_project/features/home/presentation/widgets/workspace_widgets.dart';

Directory _repositoryRoot() {
  var directory = Directory.current.absolute;
  while (!File('${directory.path}/artisan').existsSync()) {
    final parent = directory.parent;
    if (parent.path == directory.path) {
      throw StateError(
        'Laravel repository not found from ${Directory.current.path}',
      );
    }
    directory = parent;
  }
  return directory;
}

String _laravelLabel(String path, {bool page = false}) {
  final file = File('${_repositoryRoot().path}/app/Filament/$path.php');
  final source = file.readAsStringSync();
  // Filament prefers an explicit navigation label, then the plural model label
  // (or page title). Fail visibly if a future dynamic label needs a new parser.
  for (final property in [
    'navigationLabel',
    page ? 'title' : 'pluralModelLabel',
  ]) {
    final match = RegExp(
      '\\\$$property\\s*=\\s*\'([^\']+)\'\\s*;',
    ).firstMatch(source);
    if (match != null) return match.group(1)!;
  }
  throw StateError('No literal Filament label in ${file.path}');
}

typedef _Destination = ({
  String source,
  String label,
  String? route,
  String? module,
  bool page,
});

const _destinations = <_Destination>[
  (
    source: 'Resources/SubscriberResource',
    label: LaravelScreenStrings.subscribers,
    route: RouteNames.subscribers,
    module: 'subscribers',
    page: false,
  ),
  (
    source: 'Resources/SquareResource',
    label: LaravelScreenStrings.squares,
    route: RouteNames.squares,
    module: 'squares',
    page: false,
  ),
  (
    source: 'Resources/Meters/MeterResource',
    label: LaravelScreenStrings.meters,
    route: RouteNames.meters,
    module: 'meters',
    page: false,
  ),
  (
    source: 'Resources/ReadingResource',
    label: LaravelScreenStrings.readings,
    route: RouteNames.readings,
    module: 'readings',
    page: false,
  ),
  (
    source: 'Resources/InvoiceResource',
    label: LaravelScreenStrings.invoices,
    route: RouteNames.invoices,
    module: 'invoices',
    page: false,
  ),
  (
    source: 'Resources/PaymentResource',
    label: LaravelScreenStrings.payments,
    route: RouteNames.payments,
    module: 'payments',
    page: false,
  ),
  (
    source: 'Resources/SubscriptionFeeResource',
    label: LaravelScreenStrings.subscriptionFees,
    route: RouteNames.subscriptionFees,
    module: 'fees',
    page: false,
  ),
  (
    source: 'Resources/Transactions/TransactionResource',
    label: LaravelScreenStrings.transactions,
    route: RouteNames.transactions,
    module: 'transactions',
    page: false,
  ),
  (
    source: 'Resources/Tariffs/TariffResource',
    label: LaravelScreenStrings.tariffs,
    route: RouteNames.tariffs,
    module: 'tariffs',
    page: false,
  ),
  (
    source: 'Resources/Settings/SettingResource',
    label: LaravelScreenStrings.settings,
    route: '${RouteNames.settings}?section=general',
    module: 'settings',
    page: false,
  ),
  (
    source: 'Pages/ReadingSettings',
    label: LaravelScreenStrings.readingSettings,
    route: '${RouteNames.settings}?section=reading',
    module: null,
    page: true,
  ),
  (
    source: 'Pages/InvoiceSettings',
    label: LaravelScreenStrings.invoiceSettings,
    route: '${RouteNames.settings}?section=invoice',
    module: null,
    page: true,
  ),
  (
    source: 'Pages/ReceiptSettings',
    label: LaravelScreenStrings.receiptSettings,
    route: '${RouteNames.settings}?section=receipt',
    module: null,
    page: true,
  ),
  (
    source: 'Pages/ExportDataPage',
    label: LaravelScreenStrings.exports,
    route: RouteNames.exports,
    module: 'exports',
    page: true,
  ),
  (
    source: 'Resources/CashSessions/CashSessionResource',
    label: LaravelScreenStrings.cashReview,
    route: RouteNames.cashSessionReview,
    module: 'cash_review',
    page: false,
  ),
  (
    source: 'Resources/AuditEvents/AuditEventResource',
    label: LaravelScreenStrings.audit,
    route: RouteNames.auditEvents,
    module: 'audit_events',
    page: false,
  ),
  (
    source: 'Resources/VoidRequests/VoidRequestResource',
    label: LaravelScreenStrings.voidRequests,
    route: null,
    module: null,
    page: false,
  ),
  (
    source: 'Resources/Users/UserResource',
    label: LaravelScreenStrings.users,
    route: null,
    module: null,
    page: false,
  ),
];

void main() {
  test('subscriber tabs match contextual Laravel relation titles', () {
    const tabs = {
      'Meters': SubscriberExperienceStrings.meters,
      'Invoices': SubscriberExperienceStrings.invoices,
      'Payments': SubscriberExperienceStrings.payments,
      'SubscriptionFees': SubscriberExperienceStrings.fees,
    };
    for (final tab in tabs.entries) {
      expect(
        tab.value,
        _laravelLabel(
          'Resources/SubscriberResource/RelationManagers/${tab.key}RelationManager',
          page: true,
        ),
      );
    }
  });

  test('dashboard matches the installed Filament Arabic translation', () {
    final source = File(
      '${_repositoryRoot().path}/vendor/filament/filament/resources/lang/ar/pages/dashboard.php',
    ).readAsStringSync();
    final title = RegExp(
      "'title'\\s*=>\\s*'([^']+)'",
    ).firstMatch(source)?.group(1);
    expect(
      title,
      isNotNull,
      reason: 'Filament Arabic dashboard title must exist',
    );
    expect(LaravelScreenStrings.dashboard, title);
    expect(ModuleCatalog.byId('dashboard')?.title, title);
    expect(
      WorkspaceCatalog.entries
          .singleWhere((entry) => entry.route == RouteNames.dashboard)
          .title,
      title,
    );
  });

  for (final destination in _destinations) {
    test(
      '${destination.source} matches Laravel and all navigation surfaces',
      () {
        final expected = _laravelLabel(
          destination.source,
          page: destination.page,
        );
        expect(destination.label, expected);
        if (destination.route != null) {
          final entry = WorkspaceCatalog.entries.singleWhere(
            (entry) => entry.route == destination.route,
          );
          expect(entry.title, expected, reason: destination.route);
        }
        if (destination.module != null) {
          expect(
            ModuleCatalog.byId(destination.module!)?.title,
            expected,
            reason: destination.module,
          );
        }
      },
    );
  }

  for (final width in [320.0, 1280.0]) {
    testWidgets(
      'Laravel names render and open their destinations at $width with enlarged Arabic text',
      (tester) async {
        tester.view.physicalSize = Size(width, 1100);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        final opened = <String>[];
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
                child: Scaffold(
                  body: ServicesDirectory(
                    can: (_) => true,
                    onNavigate: opened.add,
                  ),
                ),
              ),
            ),
          ),
        );
        await tester.pumpAndSettle();
        for (final destination in _destinations.where(
          (destination) => destination.route != null,
        )) {
          final expected = _laravelLabel(
            destination.source,
            page: destination.page,
          );
          tester
              .state<ScrollableState>(find.byType(Scrollable).first)
              .position
              .jumpTo(0);
          await tester.pumpAndSettle();
          await tester.ensureVisible(find.byType(TextField));
          await tester.enterText(find.byType(TextField), expected);
          await tester.pumpAndSettle();
          final tile = find.widgetWithText(WorkspaceTile, expected);
          await tester.ensureVisible(tile);
          await tester.pumpAndSettle();
          expect(tile, findsOneWidget);
          await tester.tap(tile);
          expect(opened.last, destination.route);
          expect(tester.takeException(), isNull, reason: expected);
        }
      },
    );
  }
}
