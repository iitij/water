import 'package:dio/dio.dart';
import 'package:get_it/get_it.dart';

import '../error/failures.dart';
import '../network/dio_client.dart';
import 'app_config.dart';

/// يطبّع المسار النسبي ليتوافق مع `BaseOptions.baseUrl` في `DioClient` (المنتهي بـ `/api/`).
String fouadApiPath(String path, {required String baseUrl}) {
  if (path.startsWith('http')) return path;
  if (baseUrl.endsWith('/')) {
    return path.replaceFirst(RegExp(r'^/+'), '');
  }
  return path.startsWith('/') ? path : '/$path';
}

/// عميل Dio موحد لوحدات فؤاد (Dev 4) — يغلّف `GetIt.I<Dio>()` المشترك عند توفره.
class ApiClient {
  ApiClient({Dio? dio}) : _dio = dio ?? _resolveDefaultDio() {
    if (dio == null &&
        !GetIt.I.isRegistered<Dio>() &&
        !GetIt.I.isRegistered<DioClient>() &&
        AppConfig.authToken.isNotEmpty) {
      _dio.options.headers['Authorization'] = 'Bearer ${AppConfig.authToken}';
    }
  }

  static Dio _resolveDefaultDio() {
    if (GetIt.I.isRegistered<Dio>()) {
      return GetIt.I<Dio>();
    }
    if (GetIt.I.isRegistered<DioClient>()) {
      return GetIt.I<DioClient>().dio;
    }
    return Dio(
      BaseOptions(
        baseUrl: AppConfig.baseUrl,
        connectTimeout: const Duration(seconds: 15),
        receiveTimeout: const Duration(seconds: 20),
        headers: {'Accept': 'application/json'},
      ),
    );
  }

  final Dio _dio;
  Dio get dio => _dio;

  String _path(String path) =>
      fouadApiPath(path, baseUrl: _dio.options.baseUrl);

  Future<dynamic> get(String path, {Map<String, dynamic>? query}) async {
    final res = await _dio.get<dynamic>(_path(path), queryParameters: query);
    return res.data;
  }

  Future<dynamic> post(String path, {Object? data}) async =>
      (await _dio.post<dynamic>(_path(path), data: data)).data;

  Future<dynamic> put(String path, {Object? data}) async =>
      (await _dio.put<dynamic>(_path(path), data: data)).data;

  Future<dynamic> patch(String path, {Object? data}) async =>
      (await _dio.patch<dynamic>(_path(path), data: data)).data;

  Future<dynamic> delete(String path) async =>
      (await _dio.delete<dynamic>(_path(path))).data;
}

/// رسالة خطأ عربية مفهومة من استجابات Laravel (422/404/شبكة).
String friendlyError(Object e) {
  if (e is AppFailure) return e.message;
  if (e is DioException) {
    final err = e.error;
    if (err is AppFailure) return err.message;
    final data = e.response?.data;
    if (data is Map && data['message'] != null) return '${data['message']}';
    if (e.type == DioExceptionType.connectionError ||
        e.type == DioExceptionType.connectionTimeout) {
      return 'تعذر الاتصال بالخادم، تحقق من الشبكة وحاول مجدداً';
    }
  }
  return 'حدث خطأ غير متوقع';
}
