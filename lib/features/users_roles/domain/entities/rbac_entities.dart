import 'package:equatable/equatable.dart';

class SystemUser extends Equatable {
  const SystemUser({
    required this.id,
    required this.name,
    required this.email,
    required this.roles,
    this.avatarUrl,
    this.permissions = const [],
    this.createdAt,
  });

  final int id;
  final String name;
  final String email;
  final String? avatarUrl;
  final List<String> roles;
  final List<String> permissions;
  final DateTime? createdAt;

  String get roleLabel {
    const labels = {
      'super_admin': 'مدير النظام الأعلى',
      'admin': 'مدير نظام',
      'collector': 'محصل',
      'reader': 'قارئ عداد',
      'meter_reader': 'قارئ عداد',
    };
    return roles.map((role) => labels[role] ?? role).join('، ');
  }

  @override
  List<Object?> get props => [id, name, email, avatarUrl, roles, permissions, createdAt];
}

class Role extends Equatable {
  const Role({
    required this.id,
    required this.name,
    required this.guardName,
    required this.permissions,
    this.permissionsCount,
    this.updatedAt,
  });

  final int id;
  final String name;
  final String guardName;
  final List<String> permissions;
  final int? permissionsCount;
  final DateTime? updatedAt;

  int get effectivePermissionsCount => permissionsCount ?? permissions.length;
  bool get isSuperAdmin => name == 'super_admin';

  @override
  List<Object?> get props => [id, name, guardName, permissions, permissionsCount, updatedAt];
}

enum ShieldPermissionKind { resource, page, widget }

class ShieldPermission extends Equatable {
  const ShieldPermission({required this.name, required this.label});

  final String name;
  final String label;

  @override
  List<Object> get props => [name, label];
}

class ShieldPermissionGroup extends Equatable {
  const ShieldPermissionGroup({
    required this.key,
    required this.label,
    required this.kind,
    required this.permissions,
  });

  final String key;
  final String label;
  final ShieldPermissionKind kind;
  final List<ShieldPermission> permissions;

  @override
  List<Object> get props => [key, label, kind, permissions];
}

class ShieldPermissionCatalog extends Equatable {
  const ShieldPermissionCatalog({required this.groups});

  final List<ShieldPermissionGroup> groups;

  List<ShieldPermissionGroup> byKind(ShieldPermissionKind kind) =>
      groups.where((group) => group.kind == kind).toList(growable: false);

  Set<String> get allNames =>
      groups.expand((group) => group.permissions.map((permission) => permission.name)).toSet();

  @override
  List<Object> get props => [groups];
}
