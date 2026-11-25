import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

class SecureStorageService {
  SecureStorageService({FlutterSecureStorage? storage})
      : _storage = storage ?? const FlutterSecureStorage();

  static const _accessTokenKey = 'water.access_token';
  static const _userIdKey = 'water.user_id';
  static const _cachedUserKey = 'water.cached_user';
  final FlutterSecureStorage _storage;
  String? _volatileAccessToken;
  bool _persistAccessToken = true;
  int _sessionGeneration = 0;
  Future<void> _pending = Future<void>.value();

  /// Changes immediately when a login or logout begins; refresh preserves it.
  int get sessionGeneration => _sessionGeneration;

  Future<T> _serialize<T>(Future<T> Function() operation) {
    final next = _pending.then((_) => operation());
    _pending = next.then<void>((_) {}, onError: (Object _, StackTrace __) {});
    return next;
  }

  Future<String?> _readToken() async =>
      await _storage.read(key: _accessTokenKey) ?? _volatileAccessToken;

  Future<String?> readAccessToken() => _serialize(_readToken);

  Future<void> writeAccessToken(String token, {bool persist = true}) {
    ++_sessionGeneration;
    return _serialize(() => _writeToken(token, persist: persist));
  }

  Future<void> _writeToken(String token, {required bool persist}) async {
    _persistAccessToken = persist;
    _volatileAccessToken = token;
    if (persist) {
      await _storage.write(key: _accessTokenKey, value: token);
    } else {
      await _storage.delete(key: _accessTokenKey);
    }
  }

  /// Compare-and-replace within the same serialized storage operation.
  /// A new login/logout invalidates an in-flight rotation, including ABA tokens.
  Future<bool> replaceAccessTokenIfCurrent({
    required int generation,
    required String expected,
    required String replacement,
  }) => _serialize(() async {
    if (generation != _sessionGeneration) return false;
    final current = await _readToken();
    if (generation != _sessionGeneration || current != expected) return false;
    await _writeToken(replacement, persist: _persistAccessToken);
    return generation == _sessionGeneration;
  });

  Future<void> writeUserId(String id) => _storage.write(key: _userIdKey, value: id);

  Future<void> clearSessionDataOnly() async {
    await _storage.delete(key: _userIdKey);
    await _storage.delete(key: _cachedUserKey);
  }

  Future<void> writeCachedUser(Map<String, dynamic> user) =>
      _storage.write(key: _cachedUserKey, value: jsonEncode(user));

  Future<Map<String, dynamic>?> readCachedUser() async {
    final value = await _storage.read(key: _cachedUserKey);
    if (value == null) return null;
    try {
      final decoded = jsonDecode(value);
      return decoded is Map ? Map<String, dynamic>.from(decoded) : null;
    } on FormatException {
      await _storage.delete(key: _cachedUserKey);
      return null;
    }
  }

  Future<void> clearSession() {
    ++_sessionGeneration;
    return _serialize(() async {
      _volatileAccessToken = null;
      _persistAccessToken = true;
      await _storage.delete(key: _accessTokenKey);
      await _storage.delete(key: _userIdKey);
      await _storage.delete(key: _cachedUserKey);
    });
  }
}
