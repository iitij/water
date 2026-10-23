import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/router/route_names.dart';
import 'package:water_project/features/home/domain/workspace_catalog.dart';

void main() {
  test('every destination is unique and has a usable label', () {
    final entries = WorkspaceCatalog.entries;
    expect(entries.map((e) => e.route).toSet(), hasLength(entries.length));
    for (final entry in entries) {
      expect(entry.title.trim(), isNotEmpty);
      expect(entry.description.trim(), isNotEmpty);
      expect(Uri.parse(entry.route).path, startsWith('/'));
    }
  });

  test('all Laravel resource modules are discoverable', () {
    final paths = WorkspaceCatalog.visible((_) => true).map((e) => Uri.parse(e.route).path).toSet();
    expect(paths, containsAll([
      RouteNames.squares, RouteNames.subscribers, RouteNames.meters,
      RouteNames.readings, RouteNames.invoices, RouteNames.payments,
      RouteNames.tariffs, RouteNames.subscriptionFees, RouteNames.serviceRequests,
      RouteNames.transactions, RouteNames.users, RouteNames.roles,
      RouteNames.settings, RouteNames.exports, RouteNames.dashboard,
      RouteNames.reports, RouteNames.profile, RouteNames.offlineSync,
    ]));
    expect(WorkspaceCatalog.entries.where((e) => e.group == WorkspaceGroup.settings)
        .map((e) => Uri.parse(e.route).queryParameters['section']),
      containsAll(['reading', 'invoice', 'receipt', 'whatsapp', 'general']));
  });

  test('an account without permissions only sees its own tools', () {
    expect(WorkspaceCatalog.visible((_) => false).map((e) => e.route),
      [RouteNames.offlineSync, RouteNames.profile]);
  });

  test('one settings permission never exposes other admin tools', () {
    final entries = WorkspaceCatalog.visible((p) => p == 'View:WhatsAppSettings');
    expect(entries.map((e) => e.route), [
      '${RouteNames.settings}?section=whatsapp', RouteNames.offlineSync, RouteNames.profile,
    ]);
  });

  test('Arabic spelling, diacritics, English and multiword searches', () {
    final all = WorkspaceCatalog.visible;
    expect(all((_) => true, query: 'إِعْدَادَات قـراءات'), isNotEmpty);
    expect(all((_) => true, query: 'واتس اب').single.title, 'الواتساب');
    expect(all((_) => true, query: 'WHATSAPP').single.title, 'الواتساب');
    expect(all((_) => true, query: 'فاتوره يدويه').single.route, RouteNames.invoiceIssue);
    expect(all((_) => true, query: 'not-a-real-service'), isEmpty);
  });

  test('search and category filters cannot reveal a hidden service', () {
    expect(WorkspaceCatalog.visible((_) => false, query: 'واتساب'), isEmpty);
    expect(WorkspaceCatalog.visible((_) => true, group: WorkspaceGroup.field)
        .every((e) => e.group == WorkspaceGroup.field), isTrue);
    expect(WorkspaceCatalog.visible((_) => false, group: WorkspaceGroup.management), isEmpty);
  });
}
