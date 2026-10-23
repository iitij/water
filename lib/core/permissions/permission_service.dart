import '../auth/session_service.dart';

class PermissionService {
  PermissionService(this._session);

  final SessionService _session;

  bool hasPermission(String permission) {
    final roles = currentRoles;
    if (roles.any((role) => const {'admin', 'super_admin'}.contains(role))) {
      return true;
    }
    return currentPermissions.contains(permission);
  }

  bool hasAnyPermission(Iterable<String> permissions) =>
      permissions.any(hasPermission);

  bool hasRole(String role) => currentRoles.contains(role);

  Set<String> get currentRoles => _stringSet(_session.user?['roles']);
  Set<String> get currentPermissions => _stringSet(_session.user?['permissions']);

  Set<String> _stringSet(dynamic value) {
    if (value is! Iterable) return <String>{};
    return value.map((item) => item.toString()).toSet();
  }
}
