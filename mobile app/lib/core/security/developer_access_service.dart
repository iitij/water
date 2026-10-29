import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// وضع المطور صلاحية مؤقتة يؤكدها Laravel. لا يُحفظ رقم الحماية أو المنحة محلياً.
enum DeveloperUnlockResult { success, wrongPin, rateLimited, unavailable }

class DeveloperAccessService extends ChangeNotifier {
  DeveloperAccessService({required Dio dio, DateTime Function()? now})
    : _dio = dio,
      _now = now ?? DateTime.now;

  final Dio _dio;
  final DateTime Function() _now;
  String? _grant;
  DateTime? _expiresAt;
  Timer? _expiryTimer;
  static const Map<String, bool> defaultFeatures = <String, bool>{
    'whatsapp_settings': false,
    'mobile_logs': true,
    'diagnostics': true,
    'api_logs': false,
  };
  final Map<String, bool> _features = Map<String, bool>.from(defaultFeatures);
  static const String _preferencePrefix = 'developer_visible.';

  bool get isUnlocked =>
      _grant != null && _expiresAt != null && _now().isBefore(_expiresAt!);

  String? get grant => isUnlocked ? _grant : null;

  Map<String, bool> get features => Map<String, bool>.unmodifiable(_features);

  bool isFeatureEnabled(String key) => _features[key] ?? false;

  /// مفاتيح الإظهار المحلية لا تمنح وصولاً إلى أدوات المطور دون منحة سارية.
  bool canOpenFeature(String key) => isUnlocked && isFeatureEnabled(key);

  Future<void> loadVisibility() async {
    final preferences = await SharedPreferences.getInstance();
    for (final entry in defaultFeatures.entries) {
      _features[entry.key] =
          preferences.getBool('$_preferencePrefix${entry.key}') ?? entry.value;
    }
    notifyListeners();
  }

  Future<void> setFeatureEnabled(String key, bool enabled) async {
    if (!isUnlocked) throw StateError('يلزم تفعيل وضع المطور.');
    if (!defaultFeatures.containsKey(key)) throw ArgumentError.value(key);
    final preferences = await SharedPreferences.getInstance();
    if (!await preferences.setBool('$_preferencePrefix$key', enabled)) {
      throw StateError('تعذر حفظ خيار العرض.');
    }
    _features[key] = enabled;
    notifyListeners();
  }

  /// يفشل مغلقاً عند انقطاع الخادم؛ إصلاح عنوان الخادم متاح من مساره المستقل.
  Future<DeveloperUnlockResult> unlock(String pin) async {
    lock();
    if (!RegExp(r'^\d{5,12}$').hasMatch(pin)) {
      return DeveloperUnlockResult.wrongPin;
    }
    try {
      final response = await _dio.post<dynamic>(
        'developer/verify-pin',
        data: <String, dynamic>{'pin': pin},
      );
      final data = response.data;
      if (data is! Map || data['authorized'] != true) {
        return DeveloperUnlockResult.unavailable;
      }
      final grant = data['grant'];
      final expiry = DateTime.tryParse(data['expires_at']?.toString() ?? '');
      if (grant is! String ||
          grant.isEmpty ||
          expiry == null ||
          !expiry.isAfter(_now())) {
        return DeveloperUnlockResult.unavailable;
      }
      _grant = grant;
      _expiresAt = expiry;
      _scheduleExpiry();
      notifyListeners();
      return DeveloperUnlockResult.success;
    } on DioException catch (error) {
      if (error.response?.statusCode == 429) {
        return DeveloperUnlockResult.rateLimited;
      }
      if (error.response?.statusCode == 401 ||
          error.response?.statusCode == 403 ||
          error.response?.statusCode == 422) {
        return DeveloperUnlockResult.wrongPin;
      }
      return DeveloperUnlockResult.unavailable;
    } catch (_) {
      return DeveloperUnlockResult.unavailable;
    }
  }

  /// يتحقق من صلاحية المنحة؛ تغيير PIN على الخادم يبطلها فور أول تحقق.
  /// يرجع false عند تعذر الاتصال وتبقى صلاحية المنحة الأصلية محدودة بمدتها.
  Future<bool> refreshConfig() async {
    if (!isUnlocked) {
      lock();
      return false;
    }
    try {
      final response = await _dio.get<dynamic>(
        'developer/config',
        options: grantOptions,
      );
      final data = response.data;
      return data is Map && data['authorized'] == true;
    } on DioException catch (error) {
      if (error.response?.statusCode == 401 ||
          error.response?.statusCode == 403) {
        lock();
      }
      return false;
    } catch (_) {
      return false;
    }
  }

  Options get grantOptions {
    final value = grant;
    if (value == null) throw StateError('وضع المطور غير مفعل.');
    return Options(headers: <String, String>{'X-Developer-Grant': value});
  }

  void lock() {
    if (_grant == null) return;
    _expiryTimer?.cancel();
    _expiryTimer = null;
    _grant = null;
    _expiresAt = null;
    notifyListeners();
  }

  void _scheduleExpiry() {
    _expiryTimer?.cancel();
    final expiry = _expiresAt;
    if (expiry == null) return;
    _expiryTimer = Timer(expiry.difference(_now()), () {
      // إعادة الجدولة إذا تغيّرت ساعة الجهاز إلى الخلف أثناء صلاحية المنحة.
      if (_now().isBefore(expiry)) {
        _scheduleExpiry();
      } else {
        lock();
      }
    });
  }

  @override
  void dispose() {
    _expiryTimer?.cancel();
    _expiryTimer = null;
    _grant = null;
    _expiresAt = null;
    super.dispose();
  }
}
