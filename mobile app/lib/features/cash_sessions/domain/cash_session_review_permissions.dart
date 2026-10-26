import '../../../core/permissions/permission_service.dart';

/// Match CashSessionController's list and approval abilities independently.
abstract final class CashSessionReviewPermissions {
  static bool canDecide(PermissionService permissions) =>
      permissions.isAdmin ||
      permissions.currentPermissions.contains('cash_sessions.approve') ||
      permissions.currentPermissions.contains('Approve:CashSession');

  static bool canView(PermissionService permissions) =>
      canDecide(permissions) || permissions.can('ViewAny:CashSession');
}
