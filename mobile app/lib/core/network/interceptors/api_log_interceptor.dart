import 'dart:convert';
import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import '../../diagnostics/api_log_manager.dart';
import '../../diagnostics/models/api_log_entry.dart';

/// معترض Dio لتسجيل ومراقبة جميع طلبات واستجابات الشبكة وأخطاء الـ API واللاق.
class ApiLogInterceptor extends Interceptor {
  ApiLogInterceptor({ApiLogManager? logManager})
      : _logManager = logManager ?? ApiLogManager.instance;

  final ApiLogManager _logManager;
  static const int slowThresholdMs = 1200;

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    final stopwatch = Stopwatch()..start();
    options.extra['_req_stopwatch'] = stopwatch;
    final logId = 'req-${DateTime.now().microsecondsSinceEpoch}';
    options.extra['_req_log_id'] = logId;

    if (kDebugMode) {
      debugPrint('🌐 [API ➡️ REQ] ${options.method} ${options.uri}');
    }

    handler.next(options);
  }

  @override
  void onResponse(Response response, ResponseInterceptorHandler handler) {
    final stopwatch = response.requestOptions.extra['_req_stopwatch'] as Stopwatch?;
    final durationMs = stopwatch?.elapsedMilliseconds ?? 0;
    final logId = response.requestOptions.extra['_req_log_id'] as String? ??
        'res-${DateTime.now().microsecondsSinceEpoch}';

    final isSlow = durationMs >= slowThresholdMs;
    final statusCode = response.statusCode ?? 200;
    final isError = statusCode >= 400;

    // استخراج معرّف الطلب وزمن المعالجة من الخادم إن وجدا
    final serverRequestId = response.headers.value('x-request-id');
    final serverTimeStr = response.headers.value('x-response-time-ms');
    final serverDurationMs = serverTimeStr != null ? int.tryParse(serverTimeStr) : null;

    final entry = ApiLogEntry(
      id: logId,
      timestamp: DateTime.now(),
      method: response.requestOptions.method,
      url: response.requestOptions.uri.toString(),
      endpoint: response.requestOptions.path,
      queryParams: _sanitizeMap(response.requestOptions.queryParameters),
      requestHeaders: _sanitizeHeaders(response.requestOptions.headers),
      requestBody: _sanitizePayload(response.requestOptions.data),
      statusCode: statusCode,
      statusMessage: response.statusMessage,
      responseHeaders: _headersToMap(response.headers),
      responseBody: _previewBody(response.data),
      durationMs: durationMs,
      isSlow: isSlow,
      isError: isError,
      requestId: serverRequestId,
      serverDurationMs: serverDurationMs,
    );

    _logManager.addEntry(entry);

    if (kDebugMode) {
      final tag = isSlow ? ' ⚠️ [بطء/لاق]' : '';
      final icon = isError ? '❌' : '✅';
      debugPrint(
        '$icon [API ⬅️ RES $statusCode] (${durationMs}ms$tag) '
        '${response.requestOptions.method} ${response.requestOptions.path}',
      );
    }

    handler.next(response);
  }

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    final stopwatch = err.requestOptions.extra['_req_stopwatch'] as Stopwatch?;
    final durationMs = stopwatch?.elapsedMilliseconds ?? 0;
    final logId = err.requestOptions.extra['_req_log_id'] as String? ??
        'err-${DateTime.now().microsecondsSinceEpoch}';

    final isSlow = durationMs >= slowThresholdMs;
    final statusCode = err.response?.statusCode;

    final serverRequestId = err.response?.headers.value('x-request-id');
    final serverTimeStr = err.response?.headers.value('x-response-time-ms');
    final serverDurationMs = serverTimeStr != null ? int.tryParse(serverTimeStr) : null;

    final errorTypeString = _errorTypeToString(err.type);
    final errorSummary = _extractErrorMessage(err);

    final entry = ApiLogEntry(
      id: logId,
      timestamp: DateTime.now(),
      method: err.requestOptions.method,
      url: err.requestOptions.uri.toString(),
      endpoint: err.requestOptions.path,
      queryParams: _sanitizeMap(err.requestOptions.queryParameters),
      requestHeaders: _sanitizeHeaders(err.requestOptions.headers),
      requestBody: _sanitizePayload(err.requestOptions.data),
      statusCode: statusCode,
      statusMessage: err.response?.statusMessage ?? err.message,
      responseHeaders: err.response != null ? _headersToMap(err.response!.headers) : null,
      responseBody: _previewBody(err.response?.data),
      durationMs: durationMs,
      isSlow: isSlow,
      isError: true,
      errorType: errorTypeString,
      errorMessage: errorSummary,
      stackTrace: err.stackTrace.toString(),
      requestId: serverRequestId,
      serverDurationMs: serverDurationMs,
    );

    _logManager.addEntry(entry);

    if (kDebugMode) {
      debugPrint(
        '❌ [API ❌ ERR ${statusCode ?? 'NETWORK'}] (${durationMs}ms) '
        '${err.requestOptions.method} ${err.requestOptions.path} -> $errorSummary',
      );
    }

    handler.next(err);
  }

  String _errorTypeToString(DioExceptionType type) {
    switch (type) {
      case DioExceptionType.connectionTimeout:
        return 'مهلة اتصال (Connect Timeout)';
      case DioExceptionType.sendTimeout:
        return 'مهلة إرسال (Send Timeout)';
      case DioExceptionType.receiveTimeout:
        return 'مهلة استجابة (Receive Timeout)';
      case DioExceptionType.badCertificate:
        return 'خطأ شهادة SSL/TLS';
      case DioExceptionType.badResponse:
        return 'استجابة خاطئة من الخادم (Bad Response)';
      case DioExceptionType.cancel:
        return 'تم إلغاء الطلب';
      case DioExceptionType.connectionError:
        return 'تعذر الاتصال بالخادم (Connection Error)';
      case DioExceptionType.unknown:
        return 'خطأ غير معروف (Unknown)';
    }
  }

  String _extractErrorMessage(DioException err) {
    final response = err.response;
    if (response?.data is Map) {
      final map = response!.data as Map;
      if (map['message'] != null && map['message'].toString().trim().isNotEmpty) {
        return map['message'].toString();
      }
      if (map['errors'] is Map) {
        final errors = map['errors'] as Map;
        for (final val in errors.values) {
          if (val is List && val.isNotEmpty) return val.first.toString();
          if (val is String) return val;
        }
      }
    }
    return err.message ?? err.toString();
  }

  Map<String, dynamic>? _sanitizeHeaders(Map<String, dynamic>? headers) {
    if (headers == null) return null;
    final clean = Map<String, dynamic>.from(headers);
    if (clean.containsKey('authorization')) {
      final auth = clean['authorization'].toString();
      clean['authorization'] = auth.startsWith('Bearer ') ? 'Bearer ********' : '********';
    }
    if (clean.containsKey('Authorization')) {
      final auth = clean['Authorization'].toString();
      clean['Authorization'] = auth.startsWith('Bearer ') ? 'Bearer ********' : '********';
    }
    return clean;
  }

  Map<String, dynamic>? _sanitizeMap(Map<String, dynamic>? map) {
    if (map == null) return null;
    return Map<String, dynamic>.from(map);
  }

  dynamic _sanitizePayload(dynamic data) {
    if (data == null) return null;
    if (data is FormData) {
      return '[FormData مع ${data.fields.length} حقول و ${data.files.length} ملفات]';
    }
    if (data is Map) {
      final clean = Map<String, dynamic>.from(data);
      final sensitive = <String>[
        'password',
        'password_confirmation',
        'pin',
        'token',
        'secret',
        'credit_card',
      ];
      for (final key in clean.keys.toList()) {
        if (sensitive.contains(key.toLowerCase())) {
          clean[key] = '******** (محمي)';
        }
      }
      return clean;
    }
    return data;
  }

  Map<String, dynamic> _headersToMap(Headers headers) {
    final map = <String, dynamic>{};
    headers.forEach((key, values) {
      map[key] = values.length == 1 ? values.first : values;
    });
    return map;
  }

  dynamic _previewBody(dynamic data) {
    if (data == null) return null;
    if (data is List<int>) {
      return '[بيانات ثنائية بحجم ${data.length} بايت]';
    }
    if (data is String) {
      try {
        return jsonDecode(data);
      } catch (_) {
        return data.length > 2000 ? '${data.substring(0, 2000)}… (مقتطع)' : data;
      }
    }
    return data;
  }
}
