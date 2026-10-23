import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';

import 'admin_json.dart';

class AdminApiException implements Exception {
  const AdminApiException(
    this.message, {
    this.statusCode,
    this.validationErrors = const {},
    this.cause,
  });

  final String message;
  final int? statusCode;
  final Map<String, List<String>> validationErrors;
  final Object? cause;

  bool get isMissingEndpoint => statusCode == 404 || statusCode == 405;
  bool get isValidation => statusCode == 422;

  factory AdminApiException.fromDio(DioException error) {
    final response = error.response;
    final body = adminMap(response?.data);
    final errors = <String, List<String>>{};
    final rawErrors = adminMap(body['errors']);
    for (final entry in rawErrors.entries) {
      final value = entry.value;
      errors[entry.key] = value is List
          ? value.map(adminString).toList(growable: false)
          : [adminString(value)];
    }
    final validationMessages = errors.values.expand((items) => items).toList();
    final firstValidation = validationMessages.isEmpty ? null : validationMessages.first;
    return AdminApiException(
      firstValidation ??
          adminNullableString(body['message']) ??
          _friendlyDioMessage(error),
      statusCode: response?.statusCode,
      validationErrors: errors,
      cause: error,
    );
  }

  static String _friendlyDioMessage(DioException error) {
    return switch (error.type) {
      DioExceptionType.connectionTimeout ||
      DioExceptionType.sendTimeout ||
      DioExceptionType.receiveTimeout => 'انتهت مهلة الاتصال بالخادم. حاول مجدداً.',
      DioExceptionType.connectionError =>
        'تعذر الاتصال بالخادم. تحقق من الشبكة ثم أعد المحاولة.',
      DioExceptionType.badCertificate => 'شهادة الاتصال الآمن بالخادم غير صالحة.',
      _ => 'تعذر إكمال الطلب. حاول مجدداً.',
    };
  }

  @override
  String toString() => message;
}

/// Cache is an offline/compatibility aid, never a way to hide auth or
/// validation failures behind stale financial data.
bool adminAllowsCacheFallback(Object error) {
  if (error is! AdminApiException) return true;
  final status = error.statusCode;
  return status == null || status == 404 || status == 405 || status >= 500;
}

class AdminDownload {
  const AdminDownload({
    required this.bytes,
    this.fileName,
    this.contentType,
  });

  final Uint8List bytes;
  final String? fileName;
  final String? contentType;
}

/// Transport contract keeps data sources independent from the core ApiClient.
/// Inject the Dio instance owned by Dev 1 into [DioAdminApiClient] so auth,
/// retry, cache and logging interceptors remain centralized in the core.
abstract class AdminApiClient {
  Future<dynamic> get(
    String path, {
    Map<String, dynamic>? query,
    String? fallbackPath,
  });

  Future<dynamic> post(
    String path, {
    dynamic data,
    Map<String, dynamic>? query,
    String? fallbackPath,
  });

  Future<dynamic> put(
    String path, {
    dynamic data,
    Map<String, dynamic>? query,
  });

  Future<dynamic> delete(String path, {dynamic data});

  Future<dynamic> upload(
    String path, {
    required String field,
    required Uint8List bytes,
    required String fileName,
    Map<String, dynamic> fields = const {},
    String? fallbackPath,
  });

  Future<AdminDownload> download(
    String path, {
    Map<String, dynamic>? query,
    String? fallbackPath,
  });
}

final class DioAdminApiClient implements AdminApiClient {
  DioAdminApiClient(this._dio);

  final Dio _dio;

  String _resolvePath(String path) {
    if (path.startsWith('http://') || path.startsWith('https://')) return path;
    if (_dio.options.baseUrl.endsWith('/') && path.startsWith('/')) {
      return path.replaceFirst(RegExp(r'^/+'), '');
    }
    return path;
  }

  @override
  Future<dynamic> get(
    String path, {
    Map<String, dynamic>? query,
    String? fallbackPath,
  }) {
    return _jsonRequest(
      () => _dio.get<dynamic>(
        _resolvePath(path),
        queryParameters: _cleanQuery(query),
      ),
      () => fallbackPath == null
          ? null
          : _dio.get<dynamic>(
              _resolvePath(fallbackPath),
              queryParameters: _cleanQuery(query),
            ),
    );
  }

  @override
  Future<dynamic> post(
    String path, {
    dynamic data,
    Map<String, dynamic>? query,
    String? fallbackPath,
  }) {
    return _jsonRequest(
      () => _dio.post<dynamic>(
        _resolvePath(path),
        data: data,
        queryParameters: _cleanQuery(query),
      ),
      () => fallbackPath == null
          ? null
          : _dio.post<dynamic>(
              _resolvePath(fallbackPath),
              data: data,
              queryParameters: _cleanQuery(query),
            ),
    );
  }

  @override
  Future<dynamic> put(
    String path, {
    dynamic data,
    Map<String, dynamic>? query,
  }) async {
    try {
      final response = await _dio.put<dynamic>(
        _resolvePath(path),
        data: data,
        queryParameters: _cleanQuery(query),
      );
      return response.data;
    } on DioException catch (error) {
      throw AdminApiException.fromDio(error);
    }
  }

  @override
  Future<dynamic> delete(String path, {dynamic data}) async {
    try {
      final response = await _dio.delete<dynamic>(_resolvePath(path), data: data);
      return response.data;
    } on DioException catch (error) {
      throw AdminApiException.fromDio(error);
    }
  }

  @override
  Future<dynamic> upload(
    String path, {
    required String field,
    required Uint8List bytes,
    required String fileName,
    Map<String, dynamic> fields = const {},
    String? fallbackPath,
  }) {
    FormData form() => FormData.fromMap({
          ...fields,
          field: MultipartFile.fromBytes(bytes, filename: fileName),
        });

    return _jsonRequest(
      () => _dio.post<dynamic>(_resolvePath(path), data: form()),
      () => fallbackPath == null
          ? null
          : _dio.post<dynamic>(_resolvePath(fallbackPath), data: form()),
    );
  }

  @override
  Future<AdminDownload> download(
    String path, {
    Map<String, dynamic>? query,
    String? fallbackPath,
  }) async {
    Future<Response<dynamic>> request(String target) => _dio.get<dynamic>(
          _resolvePath(target),
          queryParameters: _cleanQuery(query),
          options: Options(responseType: ResponseType.bytes),
        );

    try {
      Response<dynamic> response;
      try {
        response = await request(path);
      } on DioException catch (error) {
        final exception = AdminApiException.fromDio(error);
        if (!exception.isMissingEndpoint || fallbackPath == null) {
          throw exception;
        }
        response = await request(fallbackPath);
      }
      return AdminDownload(
        bytes: _responseBytes(response.data),
        fileName: _fileNameFromHeaders(
          response.headers.value('content-disposition'),
        ),
        contentType: response.headers.value('content-type'),
      );
    } on DioException catch (error) {
      throw AdminApiException.fromDio(error);
    }
  }

  Future<dynamic> _jsonRequest(
    Future<Response<dynamic>> Function() primary,
    Future<Response<dynamic>>? Function() fallback,
  ) async {
    try {
      try {
        return (await primary()).data;
      } on DioException catch (error) {
        final exception = AdminApiException.fromDio(error);
        if (!exception.isMissingEndpoint) throw exception;
        final fallbackRequest = fallback();
        if (fallbackRequest == null) throw exception;
        return (await fallbackRequest).data;
      }
    } on DioException catch (error) {
      throw AdminApiException.fromDio(error);
    }
  }

  static Map<String, dynamic>? _cleanQuery(Map<String, dynamic>? query) {
    if (query == null) return null;
    final result = Map<String, dynamic>.from(query)
      ..removeWhere((key, value) => value == null || value == '');
    return result.isEmpty ? null : result;
  }

  static Uint8List _responseBytes(dynamic data) {
    if (data is Uint8List) return data;
    if (data is List<int>) return Uint8List.fromList(data);
    if (data is String) return Uint8List.fromList(utf8.encode(data));
    throw const AdminApiException('استلم التطبيق ملفاً غير صالح من الخادم.');
  }

  static String? _fileNameFromHeaders(String? header) {
    if (header == null || header.isEmpty) return null;
    final encoded = RegExp(
      "filename\\*=UTF-8''([^;]+)",
      caseSensitive: false,
    ).firstMatch(header);
    if (encoded != null) {
      return Uri.decodeComponent(encoded.group(1)!.trim().replaceAll('"', ''));
    }
    final regular = RegExp(
      'filename="?([^";]+)"?',
      caseSensitive: false,
    ).firstMatch(header);
    return regular?.group(1)?.trim();
  }
}
