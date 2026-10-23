import 'package:dio/dio.dart';
import '../config/app_config.dart';

/// عميل Dio موحد لوحدات فؤاد (يُستبدل بـ DioClient المهندس محمد عند الدمج).
class ApiClient {
  ApiClient({Dio? dio})
      : _dio = dio ??
            Dio(BaseOptions(
              baseUrl: AppConfig.baseUrl,
              connectTimeout: const Duration(seconds: 15),
              receiveTimeout: const Duration(seconds: 20),
              headers: {'Accept': 'application/json'},
            )) {
    if (AppConfig.authToken.isNotEmpty) {
      _dio.options.headers['Authorization'] = 'Bearer ${AppConfig.authToken}';
    }
  }

  final Dio _dio;
  Dio get dio => _dio;

  Future<dynamic> get(String path, {Map<String, dynamic>? query}) async {
    final res = await _dio.get(path, queryParameters: query);
    return res.data;
  }

  Future<dynamic> post(String path, {Object? data}) async =>
      (await _dio.post(path, data: data)).data;
  Future<dynamic> put(String path, {Object? data}) async =>
      (await _dio.put(path, data: data)).data;
  Future<dynamic> patch(String path, {Object? data}) async =>
      (await _dio.patch(path, data: data)).data;
  Future<dynamic> delete(String path) async => (await _dio.delete(path)).data;
}

/// رسالة خطأ عربية مفهومة من استجابات Laravel (422/404/شبكة).
String friendlyError(Object e) {
  if (e is DioException) {
    final data = e.response?.data;
    if (data is Map && data['message'] != null) return '${data['message']}';
    if (e.type == DioExceptionType.connectionError ||
        e.type == DioExceptionType.connectionTimeout) {
      return 'تعذر الاتصال بالخادم، تحقق من الشبكة وحاول مجدداً';
    }
  }
  return 'حدث خطأ غير متوقع';
}
