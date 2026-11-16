import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/auth/session_service.dart';
import 'package:water_project/core/permissions/permission_service.dart';
import 'package:water_project/core/router/route_names.dart';
import 'package:water_project/features/home/domain/workspace_catalog.dart';

class _Session extends Mock implements SessionService {}

void main() {
  late _Session session;
  late PermissionService permissions;

  setUp(() {
    session = _Session();
    permissions = PermissionService(session);
  });

  void user({List<String> roles = const [], List<String> grants = const []}) {
    when(() => session.user).thenReturn({'roles': roles, 'permissions': grants});
  }

  test('reader sees field tasks, not billing or administration', () {
    user(roles: ['reader']);
    final routes = WorkspaceCatalog.visible(permissions.can).map((e) => e.route);
    expect(routes, containsAll([RouteNames.fieldReader, RouteNames.readings, RouteNames.meters]));
    expect(routes, isNot(contains(RouteNames.quickCollector)));
    expect(routes, isNot(contains(RouteNames.invoiceIssue)));
    expect(routes, isNot(contains('/users')));
  });

  test('collector sees collection and squares but cannot create invoices', () {
    user(roles: ['collector']);
    final routes = WorkspaceCatalog.visible(permissions.can).map((e) => e.route);
    expect(routes, containsAll([RouteNames.quickCollector, RouteNames.squares, RouteNames.invoices]));
    expect(routes, isNot(contains(RouteNames.fieldReader)));
    expect(routes, isNot(contains(RouteNames.invoiceIssue)));
  });

  test('legacy and Shield grants expose the same navigation', () {
    user(grants: ['view_any_subscriber',
      'view_any_setting', 'create_reading', 'view_any_invoice']);
    final legacy = WorkspaceCatalog.visible(permissions.can).map((e) => e.route).toList();
    user(grants: ['ViewAny:Subscriber',
      'ViewAny:Setting', 'Create:Reading', 'ViewAny:Invoice']);
    expect(WorkspaceCatalog.visible(permissions.can).map((e) => e.route), legacy);
    expect(permissions.can('Create:Subscriber'), isFalse);
  });

  test('action-only grants do not lead into inaccessible lookup screens', () {
    user(grants: ['Create:Payment', 'Create:Reading', 'View:Invoice']);
    final routes = WorkspaceCatalog.visible(permissions.can).map((e) => e.route);
    expect(routes, isNot(contains(RouteNames.quickCollector)));
    expect(routes, isNot(contains(RouteNames.fieldReader)));
    expect(routes, isNot(contains(RouteNames.invoices)));
  });

  test('admin keeps all services; wildcard retains visibility', () {
    user(roles: ['super_admin']);
    expect(WorkspaceCatalog.visible(permissions.can), hasLength(WorkspaceCatalog.entries.length));
    user(grants: ['*']);
    expect(WorkspaceCatalog.visible(permissions.can), hasLength(WorkspaceCatalog.entries.length));
  });

  test('permission changes do not leave cached admin visibility', () {
    user(roles: ['admin']);
    expect(permissions.can('ViewAny:User'), isTrue);
    user();
    expect(WorkspaceCatalog.visible(permissions.can).map((e) => e.route),
      [RouteNames.offlineSync, RouteNames.profile]);
  });
}
