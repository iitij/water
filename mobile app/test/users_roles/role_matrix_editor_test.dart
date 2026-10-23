import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/features/users_roles/domain/entities/rbac_entities.dart';
import 'package:water_project/features/users_roles/domain/shield_permission_catalog.dart';
import 'package:water_project/features/users_roles/presentation/widgets/role_matrix_editor.dart';

void main() {
  testWidgets('Select All selects the full 167-permission Shield matrix', (tester) async {
    const role = Role(
      id: 0,
      name: 'auditor',
      guardName: 'web',
      permissions: [],
    );

    await tester.pumpWidget(
      MaterialApp(
        home: RoleMatrixEditor(
          role: role,
          catalog: standardShieldPermissionCatalog(),
          onSave: (role) async => true,
        ),
      ),
    );

    expect(find.text('0 من 167 صلاحية محددة'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('select-all-permissions')));
    await tester.pump();

    expect(find.text('167 من 167 صلاحية محددة'), findsOneWidget);
  });

  testWidgets('super_admin matrix is read-only', (tester) async {
    const role = Role(
      id: 1,
      name: 'super_admin',
      guardName: 'web',
      permissions: [],
    );

    await tester.pumpWidget(
      MaterialApp(
        home: RoleMatrixEditor(
          role: role,
          catalog: standardShieldPermissionCatalog(),
          onSave: (role) async => true,
        ),
      ),
    );

    final selectAll = tester.widget<SwitchListTile>(
      find.byKey(const ValueKey('select-all-permissions')),
    );
    expect(selectAll.onChanged, isNull);
    expect(find.text('167 من 167 صلاحية محددة'), findsOneWidget);
  });
}
