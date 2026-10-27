import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

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
  Map<String, bool> _features = const <String, bool>{};

  bool get isUnlocked =>
      _grant != null && _expiresAt != null && _now().isBefore(_expiresAt!);

  String? get grant => isUnlocked ? _grant : null;

  Map<String, bool> get features => isUnlocked
      ? Map<String, bool>.unmodifiable(_features)
      : const <String, bool>{};

  bool isFeatureEnabled(String key) => isUnlocked && (_features[key] ?? false);

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
      final rawFeatures = data['features'];
      _features = rawFeatures is Map
          ? rawFeatures.map<String, bool>(
              (key, value) =>
                  MapEntry<String, bool>(key.toString(), value == true),
            )
          : const <String, bool>{};
      _grant = grant;
      _expiresAt = expiry;
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

  /// يجلب المفاتيح الحالية؛ تغيير PIN على الخادم يبطل المنحة فور أول تحقق.
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
      if (data is! Map || data['features'] is! Map) return false;
      _features = (data['features'] as Map).map<String, bool>(
        (key, value) => MapEntry<String, bool>(key.toString(), value == true),
      );
      notifyListeners();
      return true;
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
    if (_grant == null && _features.isEmpty) return;
    _grant = null;
    _expiresAt = null;
    _features = const <String, bool>{};
    notifyListeners();
  }
}
