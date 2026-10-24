import 'package:dio/dio.dart';

import '../config/app_config.dart';
import '../diagnostics/api_log_manager.dart';
import '../diagnostics/error_reporter.dart';
import 'interceptors/api_log_interceptor.dart';
import 'interceptors/auth_interceptor.dart';
import 'interceptors/client_headers_interceptor.dart';
import 'interceptors/error_interceptor.dart';
import 'interceptors/offline_cache_interceptor.dart';
import 'secure_storage_service.dart';
import 'token_refresh_service.dart';

class DioClient {
  DioClient(
    SecureStorageService secureStorage, {
    ErrorReporter? errorReporter,
    ApiLogManager? apiLogManager,
    String? appVersion,
  })  : offlineCache = OfflineCacheInterceptor(),
        errorInterceptor = ErrorInterceptor(errorReporter: errorReporter),
        apiLogInterceptor = ApiLogInterceptor(logManager: apiLogManager),
        dio = Dio(
          BaseOptions(
            baseUrl: AppConfig.apiBaseUrl,
            connectTimeout: AppConfig.connectTimeout,
            receiveTimeout: AppConfig.receiveTimeout,
            sendTimeout: AppConfig.sendTimeout,
            responseType: ResponseType.json,
            contentType: Headers.jsonContentType,
            headers: const <String, dynamic>{'Accept': 'application/json'},
          ),
        ) {
    tokenRefresh = TokenRefreshService(dio: dio, secureStorage: secureStorage);
    dio.interceptors.addAll(<Interceptor>[
      apiLogInterceptor,
      ClientHeadersInterceptor(appVersion: appVersion),
      AuthInterceptor(
        secureStorage,
        refreshService: tokenRefresh,
        retryClient: dio,
      ),
      offlineCache,
      errorInterceptor,
    ]);
  }

  final Dio dio;
  final OfflineCacheInterceptor offlineCache;
  final ErrorInterceptor errorInterceptor;
  final ApiLogInterceptor apiLogInterceptor;
  late final TokenRefreshService tokenRefresh;

  void updateBaseUrl(String url) {
    final normalized = AppConfig.normalizeApiBaseUrl(url);
    AppConfig.configureServer(normalized);
    dio.options.baseUrl = normalized;
    tokenRefresh.reset();
    offlineCache.clear();
  }

  Future<Response<List<int>>> download(String path, {Map<String, dynamic>? query}) {
    return dio.get<List<int>>(
      path,
      queryParameters: query,
      options: Options(responseType: ResponseType.bytes),
    );
  }
}
