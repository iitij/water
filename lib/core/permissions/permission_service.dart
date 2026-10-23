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
    'tariffs.view': <String>{'ViewAny:Tariff', 'View:Tariff', 'view_any_tariff'},
    'tariffs.manage': <String>{'Create:Tariff', 'Update:Tariff', 'Delete:Tariff'},
    'print.pdf': <String>{'ViewAny:Invoice', 'ViewAny:Payment', 'view_any_payment', 'view_any_invoice'},
    'print.thermal': <String>{'ViewAny:Invoice', 'ViewAny:Payment', 'view_any_payment', 'view_any_invoice'},
    'whatsapp.send': <String>{'ViewAny:Invoice', 'ViewAny:Payment', 'view_any_payment', 'view_any_invoice'},
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
    if (granted.contains('*') || granted.contains(permission)) {
      return true;
    }

    final aliases = _abilityAliases[permission];
    if (aliases != null && aliases.any(granted.contains)) {
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
