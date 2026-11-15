import '../auth/session_service.dart';

class PermissionService {
  PermissionService(this._session);

  final SessionService _session;

  static const Map<String, Set<String>> _abilityAliases = <String, Set<String>>{
    'invoices.view': <String>{'ViewAny:Invoice', 'View:Invoice', 'view_any_invoice', 'view_invoice'},
    'invoices.create': <String>{'Create:Invoice', 'create_invoice'},
    'invoices.edit': <String>{'Update:Invoice', 'update_invoice'},
    'invoices.delete': <String>{'Delete:Invoice', 'DeleteAny:Invoice', 'delete_invoice'},
    'invoices.recalculate': <String>{'Update:Invoice', 'Create:Invoice', 'update_invoice'},
    'payments.view': <String>{'ViewAny:Payment', 'View:Payment', 'view_any_payment', 'view_payment'},
    'payments.collect': <String>{'Create:Payment', 'create_payment'},
    'payments.edit': <String>{'Update:Payment', 'update_payment'},
    'payments.delete': <String>{'Delete:Payment', 'DeleteAny:Payment', 'delete_payment'},
    // اعتماد/رفض طلبات إلغاء السندات — نفس الأسماء التي يقبلها الخادم
    // (PaymentController::authorizeVoidApprover).
    'payments.void.approve': <String>{'Approve:Payment', 'approve_payment', 'payments.void.approve'},
    'tariffs.view': <String>{'ViewAny:Tariff', 'View:Tariff', 'view_any_tariff'},
    'tariffs.manage': <String>{'Create:Tariff', 'Update:Tariff', 'Delete:Tariff'},
    'print.pdf': <String>{'ViewAny:Invoice', 'ViewAny:Payment', 'view_any_payment', 'view_any_invoice'},
    'print.thermal': <String>{'ViewAny:Invoice', 'ViewAny:Payment', 'view_any_payment', 'view_any_invoice'},
    'whatsapp.send': <String>{'ViewAny:Invoice', 'ViewAny:Payment', 'view_any_payment', 'view_any_invoice'},
    // Shield PascalCase <-> legacy snake_case bridges
    'ViewAny:Reading': <String>{'view_any_reading', 'readings.view'},
    'View:Reading': <String>{'view_reading', 'view_any_reading'},
    'Create:Reading': <String>{'create_reading', 'readings.create'},
    'Update:Reading': <String>{'update_reading', 'readings.edit'},
    'ViewAny:Meter': <String>{'view_any_meter', 'meters.view'},
    'View:Meter': <String>{'view_meter', 'view_any_meter'},
    'Create:Meter': <String>{'create_meter', 'meters.create'},
    'ViewAny:Invoice': <String>{'view_any_invoice', 'invoices.view'},
    'View:Invoice': <String>{'view_invoice', 'view_any_invoice'},
    'Create:Invoice': <String>{'create_invoice', 'invoices.create'},
    'ViewAny:Payment': <String>{'view_any_payment', 'payments.view'},
    'View:Payment': <String>{'view_payment', 'view_any_payment'},
    'Create:Payment': <String>{'create_payment', 'payments.collect'},
    'ViewAny:Tariff': <String>{'view_any_tariff', 'tariffs.view'},
    'View:Tariff': <String>{'view_tariff', 'view_any_tariff'},
    'ViewAny:Subscriber': <String>{'view_any_subscriber', 'subscribers.view'},
    'View:Subscriber': <String>{'view_subscriber', 'view_any_subscriber'},
    'ViewAny:Square': <String>{'view_any_square', 'squares.view'},
    'View:Square': <String>{'view_square', 'view_any_square'},
    'ViewAny:SubscriptionFee': <String>{'view_any_subscription_fee'},
    'View:SubscriptionFee': <String>{'view_subscription_fee', 'view_any_subscription_fee'},
    'ViewAny:Transaction': <String>{'view_any_transaction'},
    'View:Transaction': <String>{'view_transaction', 'view_any_transaction'},
    'ViewAny:User': <String>{'view_any_user'},
    'ViewAny:Role': <String>{'view_any_role'},
    'ViewAny:Setting': <String>{'view_any_setting'},
  };

  static const Set<String> _collectorDefaultPermissions = <String>{
    'invoices.view',
    'payments.view',
    'payments.collect',
    'payments.edit',
    'print.pdf',
    'print.thermal',
    'whatsapp.send',
    'ViewAny:Invoice',
    'View:Invoice',
    'ViewAny:Payment',
    'View:Payment',
    'Create:Payment',
    'Update:Payment',
    'ViewAny:Reading',
    'View:Reading',
    'ViewAny:Subscriber',
    'View:Subscriber',
    'ViewAny:Meter',
    'View:Meter',
    'ViewAny:Square',
    'View:Square',
  };

  static const Set<String> _readerDefaultPermissions = <String>{
    'ViewAny:Reading',
    'View:Reading',
    'Create:Reading',
    'Update:Reading',
    'ViewAny:Meter',
    'View:Meter',
    'ViewAny:Subscriber',
    'View:Subscriber',
    'ViewAny:Square',
    'View:Square',
    'print.pdf',
    'print.thermal',
    'whatsapp.send',
  };

  bool can(String permission) => hasPermission(permission);

  bool hasPermission(String permission) {
    final roles = currentRoles;
    if (roles.any((role) => const {'admin', 'super_admin'}.contains(role))) {
      return true;
    }
    if (permission == '*' || permission.isEmpty) {
      return false;
    }

    final granted = currentPermissions;
    bool isGranted(String name) => granted.contains(name) ||
        granted.contains(_legacyPermission(name));
    if (granted.contains('*') || isGranted(permission)) {
      return true;
    }

    final aliases = _abilityAliases[permission];
    if (aliases != null && aliases.any(isGranted)) {
      return true;
    }

    if (roles.contains('collector') && _collectorDefaultPermissions.contains(permission)) {
      return true;
    }
    if (roles.any((role) => const {'reader', 'meter_reader'}.contains(role)) &&
        _readerDefaultPermissions.contains(permission)) {
      return true;
    }

    return false;
  }

  // Match Laravel's authorizeApi/authorizeSystem snake_case compatibility.
  // ViewAny:SubscriptionFee -> view_any_subscription_fee.
  static String _legacyPermission(String permission) => permission
      .replaceAll(':', '_')
      .replaceAllMapped(RegExp(r'(?<=[a-z0-9])[A-Z]'), (match) => '_${match[0]}')
      .toLowerCase();

  bool hasAnyPermission(Iterable<String> permissions) =>
      permissions.any(hasPermission);

  bool hasRole(String role) => currentRoles.contains(role);

  bool get isAdmin =>
      currentRoles.any((role) => const {'admin', 'super_admin'}.contains(role));

  bool get isCollector => currentRoles.contains('collector');

  bool get isReader =>
      currentRoles.any((role) => const {'reader', 'meter_reader'}.contains(role));

  Set<String> get currentRoles {
    final set = _stringSet(_session.user?['roles']);
    final singleRole = _session.user?['role']?.toString();
    if (singleRole != null && singleRole.trim().isNotEmpty) {
      set.add(singleRole.trim());
    }
    return set;
  }

  Set<String> get currentPermissions => _stringSet(_session.user?['permissions']);

  Set<String> _stringSet(dynamic value) {
    if (value is! Iterable) return <String>{};
    return value.map((item) => item.toString()).toSet();
  }
}
