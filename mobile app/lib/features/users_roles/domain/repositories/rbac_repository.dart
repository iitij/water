import 'dart:typed_data';

import '../entities/rbac_entities.dart';

abstract class RbacRepository {
  Future<List<SystemUser>> getUsers({String? role});
  Future<List<Role>> getRoles();
  Future<ShieldPermissionCatalog> getPermissionCatalog();

  Future<SystemUser> createUser(
    SystemUser user,
    String password, {
    Uint8List? avatarBytes,
    String? avatarFileName,
  });
  Future<SystemUser> updateUser(
    SystemUser user, {
    String? password,
    Uint8List? avatarBytes,
    String? avatarFileName,
    bool removeAvatar = false,
  });
  Future<void> deleteUser(int id);
  Future<void> bulkDeleteUsers(List<int> ids);

  Future<Role> createRole(Role role);
  Future<Role> updateRole(Role role);
  Future<void> deleteRole(int id);
  Future<void> bulkDeleteRoles(List<int> ids);
}
