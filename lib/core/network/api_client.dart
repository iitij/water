import 'dart:typed_data';

import 'package:dio/dio.dart';

import '../config/app_config.dart';
import '../error/failures.dart';
import 'auth_token_provider.dart';

export 'auth_token_provider.dart' show AuthTokenProvider;

/// غلاف Dio موحّد (`DioClient`).
///
/// ⚠️ Dev 3: نسخة تعاقدية مبسطة تتيح بناء ووحدات الفواتير/السندات/التعرفة والعمل
/// بمعزل عن فرع Dev 1؛ عند الدمج يُستبدل بالملف الأصلي في
/// `lib/core/network/api_client.dart` بشرط الحفاظ على التواقيع أدناه
/// (`get/post/put/delete/downloadBytes`) فقط.
class DioClient {
  DioClient({
    required AppConfig config,
    required AuthTokenProvider tokenProvider,
    void Function()? onUnauthorized,
    Dio? dio,
  }) : dio = dio ??
            Dio(
              BaseOptions(
                baseUrl: config.baseUrl,
                connectTimeout: config.connectTimeout,
                receiveTimeout: config.receiveTimeout,
                headers: <String, dynamic>{'Accept': 'application/json'},
              ),
            ) {
    this.dio.interceptors.addAll(<Interceptor>[
      AuthInterceptor(tokenProvider: tokenProvider, onUnauthorized: onUnauthorized),
    ]);
  }

  final Dio dio;

  Future<T> get<T>(
    String path, {
    Map<String, dynamic>? query,
    CancelToken? cancelToken,
  }) =>
      _guard<T>(() async {
        final response = await dio.get<dynamic>(
          path,
          queryParameters: _clean(query),
          cancelToken: cancelToken,
        );
        return response.data as T;
      });

  Future<dynamic> getRaw(
    String path, {
    Map<String, dynamic>? query,
    CancelToken? cancelToken,
  }) =>
      _guardRaw(() async {
        final response = await dio.get<dynamic>(
          path,
          queryParameters: _clean(query),
          cancelToken: cancelToken,
        );
        return response.data;
      });

  Future<dynamic> post(
    String path, {
    Object? body,
    Map<String, dynamic>? query,
    CancelToken? cancelToken,
  }) =>
      _guardRaw(() async {
        final response = await dio.post<dynamic>(
          path,
          data: body,
          queryParameters: _clean(query),
          cancelToken: cancelToken,
        );
        return response.data;
      });

  Future<dynamic> put(
    String path, {
    Object? body,
    Map<String, dynamic>? query,
  }) =>
      _guardRaw(() async {
        final response = await dio.put<dynamic>(
          path,
          data: body,
          queryParameters: _clean(query),
        );
        return response.data;
      });

  Future<dynamic> delete(String path, {Object? body}) => _guardRaw(() async {
        final response = await dio.delete<dynamic>(path, data: body);
        return response.data;
      });

  /// تحميل ملف ثنائي (PDF الخاص بالفاتورة/السند أو ملف إكسل).
  Future<Uint8List> downloadBytes(
    String path, {
    Map<String, dynamic>? query,
    Duration? timeout,
  }) async {
    try {
      final response = await dio.get<dynamic>(
        path,
        queryParameters: _clean(query),
        options: Options(
          responseType: ResponseType.bytes,
          receiveTimeout: timeout,
        ),
      );
      final data = response.data;
      if (data is Uint8List) return data;
      if (data is List<int>) return Uint8List.fromList(data);
      throw const UnexpectedFailure('استجابة غير صالحة عند تحميل الملف.');
    } on DioException catch (error) {
      throw FailureMapper.from(error);
    }
  }

  static Map<String, dynamic>? _clean(Map<String, dynamic>? query) {
    if (query == null) return null;
    final cleaned = <String, dynamic>{};
    query.forEach((key, value) {
      if (value == null) return;
      if (value is String && value.trim().isEmpty) return;
      if (value is List && value.isEmpty) return;
      cleaned[key] = value;
    });
    return cleaned.isEmpty ? <String, dynamic>{} : cleaned;
  }

  Future<T> _guard<T>(Future<T> Function() action) => _guardRaw(action).then(
        (value) => value as T,
      );

  Future<dynamic> _guardRaw(Future<dynamic> Function() action) async {
    try {
      return await action();
    } on DioException catch (error) {
      throw FailureMapper.from(error);
    } on AppFailure {
      rethrow;
    } catch (error) {
      throw UnexpectedFailure(error.toString());
    }
  }
}

/// معترض إرفاق `Bearer Token` ومعالجة 401
/// (يعادل `core/network/interceptors/auth_interceptor.dart` في نواة Dev 1).

class AuthInterceptor extends Interceptor {
  AuthInterceptor({required this.tokenProvider, this.onUnauthorized});

  final AuthTokenProvider tokenProvider;
  final void Function()? onUnauthorized;

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    final token = tokenProvider.bearerToken;
    if (token != null && token.isNotEmpty) {
      options.headers['Authorization'] = 'Bearer $token';
    }
    handler.next(options);
  }

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    if (err.response?.statusCode == 401) onUnauthorized?.call();
    handler.next(err);
  }
}
