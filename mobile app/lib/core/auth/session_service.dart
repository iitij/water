import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import '../network/api_endpoints.dart';
import '../network/interceptors/offline_cache_interceptor.dart';
import '../network/secure_storage_service.dart';

class SessionService extends ChangeNotifier {
  SessionService({
    required Dio dio,
    required SecureStorageService secureStorage,
    required OfflineCacheInterceptor offlineCache,
  })  : _dio = dio,
        _secureStorage = secureStorage,
        _offlineCache = offlineCache;

  final Dio _dio;
  final SecureStorageService _secureStorage;
  final OfflineCacheInterceptor _offlineCache;

  String? _token;
  Map<String, dynamic>? _user;
  bool _isReady = false;
  bool _bootstrapping = false;
  bool _rememberSession = true;

  bool get isReady => _isReady;
  bool get isAuthenticated => _token != null && _user != null;
  String? get token => _token;
  Map<String, dynamic>? get user => _user == null ? null : Map<String, dynamic>.unmodifiable(_user!);

  Future<void> bootstrap() async {
    if (_isReady || _bootstrapping) return;
    _bootstrapping = true;
    try {
      _token = await _secureStorage.readAccessToken();
      if (_token == null || _token!.isEmpty) {
        await _clearLocalSession();
      } else {
        _user = await _secureStorage.readCachedUser();
        try {
          final response = await _dio.get<dynamic>(ApiEndpoints.me);
          _user = _extractUser(response.data);
          if (_user == null) await _clearLocalSession();
        } on DioException catch (error) {
          if (error.response?.statusCode == 401 || error.response?.statusCode == 403) {
            await _clearLocalSession();
          }
          // Keep the encrypted last-known user on transient outages for offline access.
        }
      }
    } catch (_) {
      _token = null;
      _user = null;
      try {
        await _secureStorage.clearSession();
      } catch (_) {
        // Continue to the login screen even if the platform secure store is unavailable.
      }
    } finally {
      _isReady = true;
      _bootstrapping = false;
      notifyListeners();
    }
  }

  Future<void> login({required String email, required String password, bool rememberSession = true}) async {
    _offlineCache.clear();
    final response = await _dio.post<dynamic>(
      ApiEndpoints.login,
      data: <String, dynamic>{'email': email.trim(), 'password': password},
      options: Options(extra: const <String, dynamic>{'skipAuth': true}),
    );
    final body = _asMap(response.data);
    final token = body['access_token']?.toString();
    if (token == null || token.isEmpty) {
      throw const FormatException('استجابة تسجيل الدخول لا تحتوي على رمز دخول صالح.');
    }

    // Do not expose the previous user with the newly stored token while
    // login resolves the new account identity.
    _user = null;
    _token = token;
    _rememberSession = rememberSession;
    await _secureStorage.writeAccessToken(token, persist: rememberSession);
    if (!rememberSession) await _secureStorage.clearSessionDataOnly();
    final user = _extractUser(body['user']);
    _user = user ?? _extractUser((await _dio.get<dynamic>(ApiEndpoints.me)).data);
    if (_user == null) {
      await _clearLocalSession();
      throw const FormatException('تعذر تحميل بيانات المستخدم بعد تسجيل الدخول.');
    }
    final id = _user!['id'];
    if (_rememberSession && id != null) await _secureStorage.writeUserId(id.toString());
    if (_rememberSession) await _secureStorage.writeCachedUser(_user!);
    _isReady = true;
    notifyListeners();
  }

  Future<void> updateProfile({
    required String name,
    required String email,
    String? password,
    File? avatar,
  }) async {
    final fields = <String, dynamic>{'name': name.trim(), 'email': email.trim()};
    if (password != null && password.isNotEmpty) {
      fields['password'] = password;
      fields['password_confirmation'] = password;
    }
    if (avatar != null) {
      fields['avatar'] = await MultipartFile.fromFile(
        avatar.path,
        filename: avatar.uri.pathSegments.isEmpty ? 'avatar.jpg' : avatar.uri.pathSegments.last,
      );
    }
    final response = await _dio.post<dynamic>(
      ApiEndpoints.profile,
      data: FormData.fromMap(fields),
      options: Options(contentType: Headers.multipartFormDataContentType),
    );
    final nextUser = _extractUser(_asMap(response.data)['user']);
    if (nextUser != null) {
      _user = nextUser;
      final id = nextUser['id'];
      if (_rememberSession && id != null) await _secureStorage.writeUserId(id.toString());
      if (_rememberSession) await _secureStorage.writeCachedUser(_user!);
      notifyListeners();
    }
  }

  Future<void> logout() async {
    try {
      if (_token != null) await _dio.post<dynamic>(ApiEndpoints.logout);
    } on DioException {
      // Local sign-out must still complete if the server is unreachable.
    } finally {
      try {
        await _clearLocalSession();
      } finally {
        _isReady = true;
        notifyListeners();
      }
    }
  }

  Future<void> expireSession() async {
    try {
      await _clearLocalSession();
    } finally {
      _isReady = true;
      notifyListeners();
    }
  }

  /// مستمعون يُبلَّغون عند مسح الجلسة (تسجيل خروج/انتهاء) — تُستخدم لتصفير
  /// حالة الصندوق وذاكرة التحديث بدل ترك بيانات حساب سابق ظاهرة.
  final List<VoidCallback> _clearListeners = <VoidCallback>[];

  void addSessionClearedListener(VoidCallback listener) =>
      _clearListeners.add(listener);

  void removeSessionClearedListener(VoidCallback listener) =>
      _clearListeners.remove(listener);

  Future<void> _clearLocalSession() async {
    _token = null;
    _user = null;
    _offlineCache.clear();
    await _secureStorage.clearSession();
    for (final listener in List<VoidCallback>.of(_clearListeners)) {
      try {
        listener();
      } catch (_) {
        // مستمع معطوب لا يجوز أن يمنع تسجيل الخروج.
      }
    }
  }

  Map<String, dynamic>? _extractUser(dynamic source) {
    final map = _asMap(source);
    final candidate = map['user'] is Map ? _asMap(map['user']) : map;
    if (candidate.isEmpty || candidate['id'] == null) return null;
    return Map<String, dynamic>.from(candidate);
  }

  Map<String, dynamic> _asMap(dynamic value) =>
      value is Map ? Map<String, dynamic>.from(value) : <String, dynamic>{};
}
