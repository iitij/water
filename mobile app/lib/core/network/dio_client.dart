import 'package:dio/dio.dart';

import '../config/app_config.dart';
import 'interceptors/auth_interceptor.dart';
import 'interceptors/error_interceptor.dart';
import 'interceptors/offline_cache_interceptor.dart';
import 'secure_storage_service.dart';

class DioClient {
  DioClient(SecureStorageService secureStorage)
      : offlineCache = OfflineCacheInterceptor(),
        errorInterceptor = ErrorInterceptor(),
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
    dio.interceptors.addAll(<Interceptor>[
      AuthInterceptor(secureStorage),
      offlineCache,
      errorInterceptor,
    ]);
  }

  final Dio dio;
  final OfflineCacheInterceptor offlineCache;
  final ErrorInterceptor errorInterceptor;

  void updateBaseUrl(String url) {
    final normalized = AppConfig.normalizeApiBaseUrl(url);
    AppConfig.configureServer(normalized);
    dio.options.baseUrl = normalized;
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
