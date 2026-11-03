/// عميل HTTP للوحدة المالية — يغلّف `Dio` المشترك الذي يبنيه Dev 1 في
/// `core/network/dio_client.dart` (نفس الـ baseUrl، نفس `AuthInterceptor`، نفس
/// `ErrorInterceptor` وذاكرة الطوارئ)، ويضيف تعيين الأخطاء إلى `AppFailure`.
///
/// لماذا لا نستعمل `DioClient` مباشرة؟ طبقة Dev 3 (مصادر البيانات والمستودعات)
/// بُنيت على واجهة `getRaw/post/put/delete/downloadBytes`؛ نحتفظ بتلك الواجهة حرفياً
/// حتى لا تتغير أي شاشة، مع تمرير كل الطلبات عبر `Dio` الخاص بـ Dev 1.
/// للتوحيد المستقبلي: انقل هذه الطريقة إلى `core/network/` عند Dev 1 أو اجعل
/// `DioClient` يطبّق `FinanceApiTransport`. راجع `docs/handoff/DEV3_FINANCE_MODULE.md` §9.
library;

import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';

import '../error/failures.dart';

/// يطبّّع مسارًا نسبيًا مقابل `BaseOptions.baseUrl` عند Dev 1 (منتهي بـ `/api/`).
///
/// مسارات Dev 3 مكتوبة begin_slash style (`/invoices/5`) لأنها كُتبت قبل اعتماد
/// بادئة `/api/` في النواة؛ الروابط المطلقة (جسر واتساب المحلي مثلاً) تُمرَّر كما هي.
String financeApiPath(String value) =>
    value.startsWith('http') ? value : value.replaceFirst(RegExp(r'^/+'), '');

/// رابط صفحة الطباعة في لوحة Filament (خارج بادئة `/api`) — للاحتياط عند تعذر
/// توليد PDF من الـ API (`InvoiceController::pdf` غير موجود بعد في `routes/api.php`).
String financeAdminPrintUrl(String path) {
  final apiBase = financeApiBaseUrl;
  if (apiBase.isEmpty) return path;
  final uri = Uri.tryParse(apiBase);
  if (uri == null || uri.host.isEmpty) return path;
  final port = uri.hasPort ? ':${uri.port}' : '';
  return '${uri.scheme}://${uri.host}$port${path.replaceFirst(RegExp(r'^/+'), '/')}';
}

/// يُحقن مرة واحدة من `FinanceModule.initialize()` ليبقى توليد الروابط خالياً من GetIt.
String financeApiBaseUrl = '';

class FinanceApiClient {
  FinanceApiClient(this.dio);

  final Dio dio;

  Future<T> get<T>(
    String path, {
    Map<String, dynamic>? query,
    CancelToken? cancelToken,
  }) => _guard<T>(() async {
    final response = await dio.get<dynamic>(
      financeApiPath(path),
      queryParameters: _clean(query),
      cancelToken: cancelToken,
    );
    return response.data as T;
  });

  Future<dynamic> getRaw(
    String path, {
    Map<String, dynamic>? query,
    CancelToken? cancelToken,
  }) => _guardRaw(() async {
    final response = await dio.get<dynamic>(
      financeApiPath(path),
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
  }) => _guardRaw(() async {
    final response = await dio.post<dynamic>(
      financeApiPath(path),
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
  }) => _guardRaw(() async {
    final response = await dio.put<dynamic>(
      financeApiPath(path),
      data: body,
      queryParameters: _clean(query),
    );
    return response.data;
  });

  Future<dynamic> delete(String path, {Object? body}) => _guardRaw(() async {
    final response = await dio.delete<dynamic>(
      financeApiPath(path),
      data: body,
    );
    return response.data;
  });

  /// إرسال مباشر إلى رابط مطلق (جسر واتساب المحلي) بلا طباعة مسارات.
  Future<dynamic> postAbsolute(String url, {Object? body}) =>
      _guardRaw(() async {
        final response = await dio.post<dynamic>(url, data: body);
        return response.data;
      });

  /// تحميل ملف ثنائي (PDF الفاتورة/السند).
  Future<Uint8List> downloadBytes(
    String path, {
    Map<String, dynamic>? query,
    Duration? timeout,
  }) async {
    try {
      final response = await dio.get<dynamic>(
        financeApiPath(path),
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
      // A byte download also receives JSON error bodies as bytes. Preserve the
      // server's Arabic permission/busy message instead of hiding it as HTTP 503.
      final response = error.response;
      final data = response?.data;
      if (response != null && data is List<int>) {
        try {
          final decoded = jsonDecode(utf8.decode(data));
          if (decoded is Map) {
            throw FailureMapper.fromResponse(Response<dynamic>(
              requestOptions: response.requestOptions,
              statusCode: response.statusCode,
              data: decoded,
            ));
          }
        } on FormatException {
          // HTML proxies and malformed bodies use the normal status fallback.
        }
      }
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

  Future<T> _guard<T>(Future<T> Function() action) =>
      _guardRaw(action).then((value) => value as T);

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
