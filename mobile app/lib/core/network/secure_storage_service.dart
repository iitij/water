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

  Future<String?> readAccessToken() async =>
      await _storage.read(key: _accessTokenKey) ?? _volatileAccessToken;

  Future<void> writeAccessToken(String token, {bool persist = true}) async {
    _volatileAccessToken = token;
    if (persist) {
      await _storage.write(key: _accessTokenKey, value: token);
    } else {
      await _storage.delete(key: _accessTokenKey);
    }
  }

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

  Future<void> clearSession() async {
    _volatileAccessToken = null;
    await _storage.delete(key: _accessTokenKey);
    await _storage.delete(key: _userIdKey);
    await _storage.delete(key: _cachedUserKey);
  }
}
