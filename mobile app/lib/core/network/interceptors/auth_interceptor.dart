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
  static const String sessionGenerationKey = 'authSessionGeneration';
  static const String sessionServerKey = 'authSessionServer';

  bool _isStale(RequestOptions options) {
    final generation = options.extra[sessionGenerationKey];
    final server = options.extra[sessionServerKey];
    return (generation != null && generation != _storage.sessionGeneration) ||
        (server != null && (server != options.baseUrl ||
            (_retryClient != null && server != _retryClient.options.baseUrl)));
  }

  DioException _staleError(RequestOptions options) => DioException(
    requestOptions: options,
    type: DioExceptionType.cancel,
    message: 'Session changed while the request was pending.',
  );

  final SecureStorageService _storage;
  final TokenRefreshService? _refreshService;
  final Dio? _retryClient;

  @override
  Future<void> onRequest(
    RequestOptions options,
    RequestInterceptorHandler handler,
  ) async {
    if (_isStale(options)) {
      handler.reject(_staleError(options));
      return;
    }
    if (options.extra['skipAuth'] == true) {
      handler.next(options);
      return;
    }

    options.extra[sessionGenerationKey] = _storage.sessionGeneration;
    final token = await _storage.readAccessToken();
    if (_isStale(options)) {
      handler.reject(_staleError(options));
      return;
    }
    if (token != null && token.isNotEmpty) {
      options.headers['Authorization'] = 'Bearer $token';
    }
    options.headers.putIfAbsent('Accept', () => 'application/json');
    handler.next(options);
  }

  @override
  void onResponse(Response<dynamic> response, ResponseInterceptorHandler handler) {
    if (_isStale(response.requestOptions)) {
      handler.reject(_staleError(response.requestOptions));
      return;
    }
    handler.next(response);
  }

  @override
  Future<void> onError(
    DioException err,
    ErrorInterceptorHandler handler,
  ) async {
    final options = err.requestOptions;
    if (_isStale(options)) {
      handler.reject(_staleError(options));
      return;
    }
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
    if (_isStale(options)) {
      handler.reject(_staleError(options));
      return;
    }
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
      if (_isStale(options)) {
        handler.reject(_staleError(options));
        return;
      }
      handler.resolve(retried);
    } on DioException catch (retryError) {
      if (_isStale(options)) {
        handler.reject(_staleError(options));
        return;
      }
      handler.next(retryError);
    }
  }
}
