/// جلسة المستخدم للوحدة المالية — غلاف رقيق فوق `SessionService` + `PermissionService`
/// عند Dev 1، يبقي واجهة Dev 3 (`isLoggedIn`/`has`/`canAny`/`canAll`) بلا تعديل الشاشات.
///
/// لا يخزّن أي توكن هنا: `SecureStorageService` هو مصدر الحقيقة (انظر `configureDependencies`).
import '../auth/session_service.dart';
import '../permissions/permission_service.dart';

class FinanceSession {
  const FinanceSession({
    required SessionService service,
    required PermissionService permissions,
  })  : _service = service,
        _permissions = permissions;

  final SessionService _service;
  final PermissionService _permissions;

  bool get isLoggedIn => _service.isAuthenticated;

  String? get bearerToken => _service.token;

  Map<String, dynamic>? get user => _service.user;

  int? get userId {
    final raw = _service.user?['id'];
    if (raw is int) return raw;
    return int.tryParse(raw?.toString() ?? '');
  }

  String? get displayName => _service.user?['name']?.toString();

  /// أول دور فعّال (`admin|super_admin|accountant|collector|manager`) — يحدد
  /// سلوك SCR-13 (المحصل يرى فواتيره فقط) كما في `InvoicePolicy::collectionList`.
  String? get role {
    final roles = _permissions.currentRoles;
    if (roles.isEmpty) return null;
    if (roles.contains('super_admin')) return 'super_admin';
    if (roles.contains('admin')) return 'admin';
    if (roles.contains('accountant')) return 'accountant';
    if (roles.contains('manager')) return 'manager';
    if (roles.contains('collector')) return 'collector';
    return roles.first;
  }

  Set<String> get permissions => _permissions.currentPermissions;

  bool has(String permission) => _permissions.hasPermission(permission);

  bool canAny(Iterable<String> permissions) =>
      _permissions.hasAnyPermission(permissions);

  bool canAll(Iterable<String> permissions) =>
      permissions.every(_permissions.hasPermission);

  /// `collector` يملك `invoices.view` + `payments.collect` فقط: يُمنع التعديل والحذف.
  bool get isCollectorOnly => role == 'collector';
}
