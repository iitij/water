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
  int? _stateGeneration;
  int _resetGeneration = 0;
  DateTime? _lastFailureAt;

  /// آخر توكن ناتج عن تدوير ناجح. الطلبات التي كانت في الطريق وقت التدوير
  /// ترجع 401 بالتوكن القديم؛ نعطيها الجديد مباشرة بدل تدوير ثانٍ يُبطل
  /// التوكن الصالح للتو.
  String? _recentToken;
  DateTime? _recentAt;

  /// لا نحاول أكثر من مرة كل 10 ثوانٍ بعد فشل، حتى لا نُغرق الخادم عند
  /// انقطاع كامل أو حساب معطّل.
  static const Duration failureCooldown = Duration(seconds: 10);

  /// نافذة إعادة استخدام التوكن المُدوَّر حديثاً.
  static const Duration rotationGrace = Duration(seconds: 15);

  bool get isRefreshing => _inFlight != null;

  /// يعيد التوكن الجديد، أو null إذا تعذّر التحديث (فتُنهى الجلسة).
  Future<String?> refresh() {
    final generation = _storage.sessionGeneration;
    if (_stateGeneration != generation) {
      reset();
      _stateGeneration = generation;
    }
    final active = _inFlight;
    if (active != null) return active;

    final lastFailure = _lastFailureAt;
    if (lastFailure != null &&
        DateTime.now().difference(lastFailure) < failureCooldown) {
      return Future<String?>.value(null);
    }

    final resetGeneration = _resetGeneration;
    late final Future<String?> future;
    future = _performRefresh(generation, resetGeneration).whenComplete(() {
      if (identical(_inFlight, future)) _inFlight = null;
    });
    _inFlight = future;
    return future;
  }

  bool _isCurrent(int generation, int resetGeneration) =>
      _storage.sessionGeneration == generation && _resetGeneration == resetGeneration;

  Future<String?> _performRefresh(int generation, int resetGeneration) async {
    final current = await _storage.readAccessToken();
    if (!_isCurrent(generation, resetGeneration) || current == null || current.isEmpty) return null;

    final recent = _recentToken;
    final recentAt = _recentAt;
    if (recent != null &&
        recentAt != null &&
        recent == current &&
        DateTime.now().difference(recentAt) < rotationGrace) {
      return recent;
    }

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
      if (!_isCurrent(generation, resetGeneration)) return null;
      final data = response.data;
      final token = data is Map ? data['access_token']?.toString() : null;
      if (token == null || token.isEmpty) {
        _lastFailureAt = DateTime.now();
        return null;
      }

      final replaced = await _storage.replaceAccessTokenIfCurrent(
        generation: generation,
        expected: current,
        replacement: token,
      );
      if (!replaced || !_isCurrent(generation, resetGeneration)) return null;
      _recentToken = token;
      _recentAt = DateTime.now();
      _lastFailureAt = null;
      return token;
    } on DioException {
      if (!_isCurrent(generation, resetGeneration)) return null;
      _lastFailureAt = DateTime.now();
      return null;
    }
  }

  /// يُستخدم في تسجيل الخروج لتصفير حالة التحديث.
  void reset() {
    ++_resetGeneration;
    _stateGeneration = null;
    _inFlight = null;
    _lastFailureAt = null;
    _recentToken = null;
    _recentAt = null;
  }
}
