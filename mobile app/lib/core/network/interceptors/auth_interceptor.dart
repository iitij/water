import 'package:dio/dio.dart';

import '../secure_storage_service.dart';
import '../token_refresh_service.dart';

/// يضيف توكن Sanctum لكل طلب، ويحاول تجديده مرة واحدة عند 401 قبل تسليم
/// الخطأ للتطبيق. هذا هو المسار الوحيد المسموح لتجديد الجلسة.
class AuthInterceptor extends Interceptor {
  AuthInterceptor(
    this._storage, {
    TokenRefreshService? refreshService,
    Dio? retryClient,
  })  : _refreshService = refreshService,
        _retryClient = retryClient;

  static const String retriedFlag = 'authRetried';

  final SecureStorageService _storage;
  final TokenRefreshService? _refreshService;
  final Dio? _retryClient;

  @override
  Future<void> onRequest(
    RequestOptions options,
    RequestInterceptorHandler handler,
  ) async {
    if (options.extra['skipAuth'] == true) {
      handler.next(options);
      return;
    }

    final token = await _storage.readAccessToken();
    if (token != null && token.isNotEmpty) {
      options.headers['Authorization'] = 'Bearer $token';
    }
    options.headers.putIfAbsent('Accept', () => 'application/json');
    handler.next(options);
  }

  @override
  Future<void> onError(
    DioException err,
    ErrorInterceptorHandler handler,
  ) async {
    final options = err.requestOptions;
    final canRefresh = err.response?.statusCode == 401 &&
        _refreshService != null &&
        _retryClient != null &&
        options.extra['skipAuth'] != true &&
        options.extra['skipRefresh'] != true &&
        options.extra[retriedFlag] != true;

    if (!canRefresh) {
      handler.next(err);
      return;
    }

    final token = await _refreshService.refresh();
    if (token == null || token.isEmpty) {
      handler.next(err);
      return;
    }

    try {
      final retried = await _retryClient.fetch<dynamic>(
        options.copyWith(
          headers: <String, dynamic>{
            ...options.headers,
            'Authorization': 'Bearer $token',
          },
          extra: <String, dynamic>{
            ...options.extra,
            retriedFlag: true,
            // الطلب أُعيد يدوياً؛ لا نريد أن يضيف AuthInterceptor ترويسة أخرى.
            'skipAuth': false,
          },
        ),
      );
      handler.resolve(retried);
    } on DioException catch (retryError) {
      handler.next(retryError);
    }
  }
}
