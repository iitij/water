import 'package:dio/dio.dart';

import 'api_endpoints.dart';
import 'secure_storage_service.dart';

/// تدوير توكن Sanctum دون إخراج المستخدم.
///
/// * طلب واحد فقط في الوقت نفسه: كل 401 متزامن ينتظر نفس المحاولة، فلا تتفجر
///   عشرة طلبات تحديث وتُبطل التوكن الجديد بعضها.
/// * يُستخدم نفس `Dio` لكن بعلامة `skipAuth` + `skipRefresh` حتى لا يدخل
///   التحديث في حلقة لا نهائية.
///
/// مرجع: خطة الإصدار 2.0 — الفصل 9.1.
class TokenRefreshService {
  TokenRefreshService({
    required Dio dio,
    required SecureStorageService secureStorage,
  })  : _dio = dio,
        _storage = secureStorage;

  final Dio _dio;
  final SecureStorageService _storage;

  Future<String?>? _inFlight;
  DateTime? _lastFailureAt;

  /// لا نحاول أكثر من مرة كل 10 ثوانٍ بعد فشل، حتى لا نُغرق الخادم عند
  /// انقطاع كامل أو حساب معطّل.
  static const Duration failureCooldown = Duration(seconds: 10);

  bool get isRefreshing => _inFlight != null;

  /// يعيد التوكن الجديد، أو null إذا تعذّر التحديث (فتُنهى الجلسة).
  Future<String?> refresh() {
    final active = _inFlight;
    if (active != null) return active;

    final lastFailure = _lastFailureAt;
    if (lastFailure != null &&
        DateTime.now().difference(lastFailure) < failureCooldown) {
      return Future<String?>.value(null);
    }

    final future = _performRefresh();
    _inFlight = future.whenComplete(() => _inFlight = null);
    return _inFlight!;
  }

  Future<String?> _performRefresh() async {
    final current = await _storage.readAccessToken();
    if (current == null || current.isEmpty) return null;

    try {
      final response = await _dio.post<dynamic>(
        ApiEndpoints.refresh,
        options: Options(
          headers: <String, dynamic>{'Authorization': 'Bearer $current'},
          extra: const <String, dynamic>{
            'skipAuth': true,
            'skipRefresh': true,
            'skipOfflineCache': true,
          },
        ),
      );
      final data = response.data;
      final token = data is Map ? data['access_token']?.toString() : null;
      if (token == null || token.isEmpty) {
        _lastFailureAt = DateTime.now();
        return null;
      }

      await _storage.writeAccessToken(token);
      _lastFailureAt = null;
      return token;
    } on DioException {
      _lastFailureAt = DateTime.now();
      return null;
    }
  }

  /// يُستخدم في تسجيل الخروج لتصفير حالة التحديث.
  void reset() {
    _inFlight = null;
    _lastFailureAt = null;
  }
}
