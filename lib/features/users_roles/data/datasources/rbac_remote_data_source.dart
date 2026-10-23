import '../../../admin_shared/data/admin_api_client.dart';
import '../../../admin_shared/data/admin_api_endpoints.dart';
import '../../../admin_shared/data/admin_json.dart';
import '../../domain/entities/rbac_entities.dart';
import '../models/rbac_models.dart';

class RbacRemoteDataSource {
  const RbacRemoteDataSource(this._api);

  final AdminApiClient _api;

  Future<List<SystemUserModel>> getUsers({String? role}) async {
    final response = await _api.get(
      AdminApiEndpoints.users,
      query: {if (role != null && role.isNotEmpty) 'role': role},
    );
    return adminList(response, const ['users'])
        .map(SystemUserModel.fromJson)
        .toList(growable: false);
  }

  Future<List<RoleModel>> getRoles() async {
    final response = await _api.get(AdminApiEndpoints.roles);
    return adminList(response, const ['roles'])
        .map(RoleModel.fromJson)
        .toList(growable: false);
  }

  Future<ShieldPermissionCatalog> getPermissionCatalog() async {
    final response = await _api.get(
      AdminApiEndpoints.permissionsMatrix,
      fallbackPath: AdminApiEndpoints.permissions,
    );
    return ShieldPermissionCatalogModel.fromJson(response);
  }

  Future<SystemUserModel> createUser(SystemUserModel user, String password) async {
    final response = await _api.post(
      AdminApiEndpoints.users,
      data: user.toJson(password: password),
    );
    return _userResponse(response, user);
  }

  Future<SystemUserModel> updateUser(SystemUserModel user, {String? password}) async {
    final response = await _api.put(
      '${AdminApiEndpoints.users}/${user.id}',
      data: user.toJson(password: password),
    );
    return _userResponse(response, user);
  }

  Future<void> deleteUser(int id) => _api.delete('${AdminApiEndpoints.users}/$id');

  Future<void> bulkDeleteUsers(List<int> ids) => _api.post(
        AdminApiEndpoints.usersBulkDelete,
        data: {'ids': ids},
      );

  Future<RoleModel> createRole(RoleModel role) async {
    final response = await _api.post(AdminApiEndpoints.roles, data: role.toJson());
    return _roleResponse(response, role);
  }

  Future<RoleModel> updateRole(RoleModel role) async {
    final response = await _api.put(
      '${AdminApiEndpoints.roles}/${role.id}',
      data: role.toJson(),
    );
    return _roleResponse(response, role);
  }

  Future<void> deleteRole(int id) => _api.delete('${AdminApiEndpoints.roles}/$id');

  SystemUserModel _userResponse(dynamic response, SystemUserModel fallback) {
    final root = adminMap(adminUnwrap(response));
    final json = root.containsKey('user') ? adminMap(root['user']) : root;
    return json.containsKey('id') ? SystemUserModel.fromJson(json) : fallback;
  }

  RoleModel _roleResponse(dynamic response, RoleModel fallback) {
    final root = adminMap(adminUnwrap(response));
    final json = root.containsKey('role') ? adminMap(root['role']) : root;
    return json.containsKey('id') ? RoleModel.fromJson(json) : fallback;
  }
}
