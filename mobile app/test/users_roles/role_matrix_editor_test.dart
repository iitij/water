import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/l10n/app_strings.dart';
import 'package:water_project/features/users_roles/domain/entities/rbac_entities.dart';
import 'package:water_project/features/users_roles/domain/shield_permission_catalog.dart';
import 'package:water_project/features/users_roles/presentation/widgets/role_matrix_editor.dart';

void main() {
  testWidgets('Select All selects the current Shield matrix without retired fee permissions', (tester) async {
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

    expect(find.text('0 من ${standardShieldPermissionCatalog().allNames.length} صلاحية محددة'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('select-all-permissions')));
    await tester.pump();

    expect(find.text('${standardShieldPermissionCatalog().allNames.length} من ${standardShieldPermissionCatalog().allNames.length} صلاحية محددة'), findsOneWidget);
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

    final selectAll = tester.widget<Switch>(
      find.byKey(const ValueKey('select-all-permissions')),
    );
    expect(selectAll.onChanged, isNull);
    expect(find.text('${standardShieldPermissionCatalog().allNames.length} من ${standardShieldPermissionCatalog().allNames.length} صلاحية محددة'), findsOneWidget);
  });

  testWidgets('view-only role exposes its matrix without allowing changes or saves', (tester) async {
    var saves = 0;
    await tester.pumpWidget(MaterialApp(home: RoleMatrixEditor(
      role: const Role(id: 12, name: 'auditor', guardName: 'web', permissions: ['View:Invoice']),
      catalog: standardShieldPermissionCatalog(), readOnly: true,
      onSave: (_) async { saves++; return true; },
    )));
    expect(tester.widget<TextFormField>(find.byKey(const ValueKey('role-name'))).enabled, isFalse);
    expect(tester.widget<Switch>(find.byKey(const ValueKey('select-all-permissions'))).onChanged, isNull);
    expect(tester.widget<DropdownButtonFormField<String>>(find.byType(DropdownButtonFormField<String>)).onChanged, isNull);
    await tester.tap(find.byKey(const ValueKey('save-role-matrix')));
    await tester.pump();
    expect(saves, 0);
  });

  testWidgets('changing guard requires confirmation and drops incompatible permissions', (tester) async {
    Role? saved;
    const catalog = ShieldPermissionCatalog(guardNames: ['web', 'staff'],
      guardPermissions: {'web': ['View:Invoice', 'Update:Invoice'], 'staff': ['View:Invoice']},
      groups: [ShieldPermissionGroup(key: 'Invoice', label: 'الفواتير', kind: ShieldPermissionKind.resource,
        permissions: [ShieldPermission(name: 'View:Invoice', label: 'عرض'),
          ShieldPermission(name: 'Update:Invoice', label: 'تعديل')])]);
    await tester.pumpWidget(MaterialApp(locale: const Locale('ar'),
      supportedLocales: const [Locale('ar')], localizationsDelegates: GlobalMaterialLocalizations.delegates,
      home: RoleMatrixEditor(
        role: const Role(id: 12, name: 'auditor', guardName: 'web', permissions: ['View:Invoice', 'Update:Invoice']),
        catalog: catalog, onSave: (role) async { saved = role; return false; },
      )));
    await tester.tap(find.byType(DropdownButtonFormField<String>));
    await tester.pumpAndSettle();
    await tester.tap(find.text('staff').last);
    await tester.pumpAndSettle();
    expect(find.text(AppStrings.ar.roleGuardChangeTitle), findsOneWidget);
    await tester.tap(find.text(AppStrings.ar.cancel));
    await tester.pumpAndSettle();
    expect(tester.state<FormFieldState<String>>(find.byType(DropdownButtonFormField<String>)).value, 'web');
    await tester.tap(find.byType(DropdownButtonFormField<String>));
    await tester.pumpAndSettle();
    await tester.tap(find.text('staff').last);
    await tester.pumpAndSettle();
    await tester.tap(find.text(AppStrings.ar.roleGuardConfirm));
    await tester.pumpAndSettle();
    expect(find.text('1 من 1 صلاحية محددة'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('save-role-matrix')));
    await tester.pumpAndSettle();
    expect(saved!.guardName, 'staff');
    expect(saved!.permissions, ['View:Invoice']);
  });
}
