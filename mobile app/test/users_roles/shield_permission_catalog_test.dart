import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/features/users_roles/domain/entities/rbac_entities.dart';
import 'package:water_project/features/users_roles/domain/shield_permission_catalog.dart';

void main() {
  test('standard Shield catalog contains 11 resources, 6 pages and 5 widgets', () {
    final catalog = standardShieldPermissionCatalog();
    final resources = catalog.byKind(ShieldPermissionKind.resource);

    expect(resources, hasLength(11));
    expect(catalog.byKind(ShieldPermissionKind.page), hasLength(6));
    expect(catalog.byKind(ShieldPermissionKind.widget), hasLength(5));
    expect(resources.every((group) => group.permissions.length == 12), isTrue);
    expect(catalog.allNames, hasLength(143));
    expect(catalog.allNames, isNot(contains('ViewAny:SubscriptionFee')));
    expect(catalog.allNames, contains('ViewAny:Invoice'));
    expect(catalog.allNames, contains('ForceDeleteAny:Subscriber'));
    expect(catalog.allNames, contains('View:ExportDataPage'));
    expect(catalog.allNames, contains('View:CustomAccountWidget'));
  });
}
