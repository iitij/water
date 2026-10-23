import 'package:shared_preferences/shared_preferences.dart';

import '../network/auth_token_provider.dart';

/// جلسة المستخدم: التوكن + الدور + مصفوفة الصلاحيات (Spatie/Shield).
///
/// ⚠️ نسخة تعاقدية مبسطة (النواة يملكها Dev 1 — `core/auth` + `flutter_secure_storage`).
/// Dev 3 يعتمد السطح البرمجي التالي فقط:
///   `bearerToken`, `has(permission)`, `canAny([...])`, `updateSession(...)`.
/// عند الدمج يُحذف هذا الملف ويُُسجَّل `SessionStore` الأصلي في DI.
class SessionStore implements AuthTokenProvider {
  SessionStore._(this._prefs, this._token, this._permissions, this.role);

  static const String _kToken = 'auth.token';
  static const String _kRole = 'auth.role';
  static const String _kPermissions = 'auth.permissions';

  final SharedPreferences _prefs;
  String? _token;
  Set<String> _permissions;
  final String? role;

  static Future<SessionStore> load() async {
    final prefs = await SharedPreferences.getInstance();
    final token = prefs.getString(_kToken);
    final role = prefs.getString(_kRole);
    final permissions = (prefs.getStringList(_kPermissions) ?? <String>[]).toSet();
    return SessionStore._(prefs, token, permissions, role);
  }

  @override
  String? get bearerToken => _token;

  bool get isLoggedIn => _token != null && _token!.isNotEmpty;

  /// الصلاحيات الكاملة (`*`) تمنح كل شيء، والدور `super_admin` كذلك.
  bool has(String permission) {
    if (_permissions.contains('*')) return true;
    if (role == 'super_admin') return true;
    return _permissions.contains(permission);
  }

  bool canAny(Iterable<String> permissions) =>
      permissions.any(has) || _permissions.contains('*') || role == 'super_admin';

  bool canAll(Iterable<String> permissions) => permissions.every(has);

  Set<String> get permissions => Set<String>.unmodifiable(_permissions);

  Future<void> updateSession({
    String? token,
    String? role,
    Iterable<String>? permissions,
  }) async {
    if (token != null) {
      _token = token;
      await _prefs.setString(_kToken, token);
    }
    if (role != null) await _prefs.setString(_kRole, role);
    if (permissions != null) {
      _permissions = permissions.toSet();
      await _prefs.setStringList(_kPermissions, _permissions.toList());
    }
  }

  Future<void> clear() async {
    _token = null;
    _permissions = <String>{};
    await _prefs.remove(_kToken);
    await _prefs.remove(_kRole);
    await _prefs.remove(_kPermissions);
  }
}
