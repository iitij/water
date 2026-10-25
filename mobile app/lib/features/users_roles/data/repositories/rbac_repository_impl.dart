import 'dart:typed_data';

import '../../domain/entities/rbac_entities.dart';
import '../../domain/repositories/rbac_repository.dart';
import '../datasources/rbac_remote_data_source.dart';
import '../models/rbac_models.dart';

class RbacRepositoryImpl implements RbacRepository {
  const RbacRepositoryImpl(this._remote);

  final RbacRemoteDataSource _remote;

  @override
  Future<List<SystemUser>> getUsers({String? role}) => _remote.getUsers(role: role);

  @override
  Future<List<Role>> getRoles() => _remote.getRoles();

  @override
  Future<ShieldPermissionCatalog> getPermissionCatalog() => _remote.getPermissionCatalog();

  @override
  Future<SystemUser> createUser(
    SystemUser user,
    String password, {
    Uint8List? avatarBytes,
    String? avatarFileName,
  }) =>
      _remote.createUser(
        _userModel(user),
        password,
        avatarBytes: avatarBytes,
        avatarFileName: avatarFileName,
      );

  @override
  Future<SystemUser> updateUser(
    SystemUser user, {
    String? password,
    Uint8List? avatarBytes,
    String? avatarFileName,
    bool removeAvatar = false,
  }) =>
      _remote.updateUser(
        _userModel(user),
        password: password,
        avatarBytes: avatarBytes,
        avatarFileName: avatarFileName,
        removeAvatar: removeAvatar,
      );

  @override
  Future<void> deleteUser(int id) => _remote.deleteUser(id);

  @override
  Future<void> bulkDeleteUsers(List<int> ids) => _remote.bulkDeleteUsers(ids);

  @override
  Future<Role> createRole(Role role) => _remote.createRole(_roleModel(role));

  @override
  Future<Role> updateRole(Role role) => _remote.updateRole(_roleModel(role));

  @override
  Future<void> deleteRole(int id) => _remote.deleteRole(id);

  @override
  Future<void> bulkDeleteRoles(List<int> ids) => _remote.bulkDeleteRoles(ids);

  SystemUserModel _userModel(SystemUser user) => user is SystemUserModel
      ? user
      : SystemUserModel(
          id: user.id,
          name: user.name,
          email: user.email,
          avatarUrl: user.avatarUrl,
          roles: user.roles,
          permissions: user.permissions,
          createdAt: user.createdAt,
        );

  RoleModel _roleModel(Role role) => role is RoleModel
      ? role
      : RoleModel(
          id: role.id,
          name: role.name,
          guardName: role.guardName,
          permissions: role.permissions,
          permissionsCount: role.permissionsCount,
          updatedAt: role.updatedAt,
        );
}
