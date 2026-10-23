import '../../../admin_shared/data/admin_json.dart';
import '../../domain/entities/rbac_entities.dart';
import '../../domain/shield_permission_catalog.dart';

class SystemUserModel extends SystemUser {
  const SystemUserModel({
    required super.id,
    required super.name,
    required super.email,
    required super.roles,
    super.avatarUrl,
    super.permissions,
    super.createdAt,
  });

  factory SystemUserModel.fromJson(dynamic value) {
    final json = adminDataMap(value);
    final roles = _names(json['roles']);
    final legacyRole = adminNullableString(json['role']);
    return SystemUserModel(
      id: adminInt(json['id']),
      name: adminString(json['name']),
      email: adminString(json['email']),
      avatarUrl: adminNullableString(json['avatar_url'] ?? json['avatar']),
      roles: roles.isNotEmpty ? roles : [if (legacyRole != null) legacyRole],
      permissions: _names(json['permissions']),
      createdAt: adminDate(json['created_at']),
    );
  }

  Map<String, dynamic> toJson({String? password}) => {
        'name': name,
        'email': email,
        'roles': roles,
        if (password != null && password.isNotEmpty) 'password': password,
      };

  static List<String> _names(dynamic value) {
    if (value is! List) return const [];
    return value
        .map((item) => item is Map ? adminString(item['name']) : adminString(item))
        .where((name) => name.isNotEmpty)
        .toList(growable: false);
  }
}

class RoleModel extends Role {
  const RoleModel({
    required super.id,
    required super.name,
    required super.guardName,
    required super.permissions,
    super.permissionsCount,
    super.updatedAt,
  });

  factory RoleModel.fromJson(dynamic value) {
    final json = adminDataMap(value);
    final permissions = SystemUserModel._names(json['permissions']);
    final rawCount = json['permissions_count'];
    return RoleModel(
      id: adminInt(json['id']),
      name: adminString(json['name']),
      guardName: adminString(json['guard_name'], 'web'),
      permissions: permissions,
      permissionsCount: rawCount == null ? null : adminInt(rawCount),
      updatedAt: adminDate(json['updated_at']),
    );
  }

  Map<String, dynamic> toJson() => {
        'name': name,
        'guard_name': guardName,
        'permissions': permissions,
      };
}

class ShieldPermissionCatalogModel extends ShieldPermissionCatalog {
  const ShieldPermissionCatalogModel({required super.groups});

  factory ShieldPermissionCatalogModel.fromJson(dynamic value) {
    final root = adminDataMap(value);
    final groups = <ShieldPermissionGroup>[];
    final rawGroups = root['groups'];
    if (rawGroups is List) {
      for (final raw in rawGroups) {
        final json = adminMap(raw);
        final permissions = _permissions(json['permissions'] ?? json['items']);
        if (permissions.isEmpty) continue;
        groups.add(
          ShieldPermissionGroup(
            key: adminString(json['key'] ?? json['name']),
            label: adminString(json['label'] ?? json['name']),
            kind: _kind(adminString(json['kind'] ?? json['type'])),
            permissions: permissions,
          ),
        );
      }
    } else {
      for (final kind in ShieldPermissionKind.values) {
        final key = switch (kind) {
          ShieldPermissionKind.resource => 'resources',
          ShieldPermissionKind.page => 'pages',
          ShieldPermissionKind.widget => 'widgets',
        };
        final raw = root[key];
        if (raw is! Map) continue;
        for (final entry in raw.entries) {
          final value = entry.value;
          final json = adminMap(value);
          final permissions = _permissions(value is List ? value : json['permissions']);
          if (permissions.isEmpty) continue;
          groups.add(
            ShieldPermissionGroup(
              key: adminString(entry.key),
              label: adminString(json['label'], adminString(entry.key)),
              kind: kind,
              permissions: permissions,
            ),
          );
        }
      }
    }
    return groups.isEmpty
        ? ShieldPermissionCatalogModel(groups: standardShieldPermissionCatalog().groups)
        : ShieldPermissionCatalogModel(groups: groups);
  }

  static List<ShieldPermission> _permissions(dynamic value) {
    if (value is! List) return const [];
    return value.map((item) {
      if (item is Map) {
        final name = adminString(item['name']);
        return ShieldPermission(name: name, label: adminString(item['label'], name));
      }
      final name = adminString(item);
      return ShieldPermission(name: name, label: name.split(':').first);
    }).where((permission) => permission.name.isNotEmpty).toList(growable: false);
  }

  static ShieldPermissionKind _kind(String value) => switch (value) {
        'resource' || 'resources' => ShieldPermissionKind.resource,
        'widget' || 'widgets' => ShieldPermissionKind.widget,
        _ => ShieldPermissionKind.page,
      };
}
