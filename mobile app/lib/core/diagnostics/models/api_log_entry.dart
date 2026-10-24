import 'dart:convert';

/// يمثل سجلاً كاملاً لطلب واستجابة API بين تطبيق الهاتف والخادم.
///
/// يحفظ كل ما يلزم لتشخيص أي خطأ أو لاق أو تعذّر جلب بيانات أو فشل عملية.
class ApiLogEntry {
  ApiLogEntry({
    required this.id,
    required this.timestamp,
    required this.method,
    required this.url,
    required this.endpoint,
    this.queryParams,
    this.requestHeaders,
    this.requestBody,
    this.statusCode,
    this.statusMessage,
    this.responseHeaders,
    this.responseBody,
    this.durationMs = 0,
    this.isSlow = false,
    this.isError = false,
    this.errorType,
    this.errorMessage,
    this.stackTrace,
    this.requestId,
    this.serverDurationMs,
    this.context,
  });

  final String id;
  final DateTime timestamp;
  final String method;
  final String url;
  final String endpoint;
  final Map<String, dynamic>? queryParams;
  final Map<String, dynamic>? requestHeaders;
  final dynamic requestBody;
  final int? statusCode;
  final String? statusMessage;
  final Map<String, dynamic>? responseHeaders;
  final dynamic responseBody;
  final int durationMs;
  final bool isSlow;
  final bool isError;
  final String? errorType;
  final String? errorMessage;
  final String? stackTrace;
  final String? requestId;
  final int? serverDurationMs;
  final String? context;

  bool get isSuccess => !isError && statusCode != null && statusCode! >= 200 && statusCode! < 300;

  String get displayStatus {
    if (statusCode != null) return '$statusCode';
    if (errorType != null) return errorType!;
    return isError ? 'ERROR' : 'OK';
  }

  String get durationLabel {
    if (durationMs < 1000) return '${durationMs}ms';
    final seconds = (durationMs / 1000).toStringAsFixed(1);
    return '${seconds}s';
  }

  /// تحويل السجل إلى أمر cURL لسهولة تكرار الطلب في الطرفية أو Postman
  String toCurl() {
    final buffer = StringBuffer('curl -X $method "$url"');

    if (requestHeaders != null) {
      requestHeaders!.forEach((key, value) {
        if (key.toLowerCase() != 'content-length') {
          buffer.write(' \\\n  -H "$key: $value"');
        }
      });
    }

    if (requestBody != null) {
      try {
        final bodyString = requestBody is String
            ? requestBody as String
            : jsonEncode(requestBody);
        final escaped = bodyString.replaceAll('"', '\\"');
        buffer.write(' \\\n  -d "$escaped"');
      } catch (_) {
        buffer.write(' \\\n  -d "${requestBody.toString()}"');
      }
    }

    return buffer.toString();
  }

  /// تقرير تفصيلي نصي جاهز للنسخ والإرسال عبر واتساب أو للمطورين
  String toDetailedReport() {
    final buffer = StringBuffer()
      ..writeln('=== تقرير طلب API ===')
      ..writeln('الوقت: ${timestamp.toIso8601String()}')
      ..writeln('المسار: $method $endpoint')
      ..writeln('الرابط الكامل: $url')
      ..writeln('الحالة: $displayStatus (${durationLabel}${isSlow ? ' - لاق/بطء' : ''})')
      ..writeln('معرف الطلب بالخادم: ${requestId ?? 'غير متوفر'}')
      ..writeln('زمن معالجة الخادم: ${serverDurationMs != null ? '${serverDurationMs}ms' : 'غير متوفر'}');

    if (queryParams != null && queryParams!.isNotEmpty) {
      buffer.writeln('\n-- متغيرات الاستعلام (Query) --');
      buffer.writeln(_prettyJson(queryParams));
    }

    if (requestHeaders != null && requestHeaders!.isNotEmpty) {
      buffer.writeln('\n-- ترويسات الطلب (Headers) --');
      buffer.writeln(_prettyJson(requestHeaders));
    }

    if (requestBody != null) {
      buffer.writeln('\n-- بيانات الطلب المُرسلة (Payload) --');
      buffer.writeln(_prettyJson(requestBody));
    }

    if (errorMessage != null || errorType != null) {
      buffer.writeln('\n-- تفاصيل الخطأ (Error Details) --');
      if (errorType != null) buffer.writeln('النوع: $errorType');
      if (errorMessage != null) buffer.writeln('الرسالة: $errorMessage');
      if (stackTrace != null) buffer.writeln('التتبع:\n$stackTrace');
    }

    if (responseBody != null) {
      buffer.writeln('\n-- استجابة الخادم (Response Body) --');
      buffer.writeln(_prettyJson(responseBody));
    }

    buffer.writeln('\n=== أمر cURL للمطابقة ===\n${toCurl()}');

    return buffer.toString();
  }

  static String _prettyJson(dynamic data) {
    if (data == null) return 'null';
    try {
      if (data is String) {
        final decoded = jsonDecode(data);
        return const JsonEncoder.withIndent('  ').convert(decoded);
      }
      return const JsonEncoder.withIndent('  ').convert(data);
    } catch (_) {
      return data.toString();
    }
  }

  Map<String, dynamic> toJson() => <String, dynamic>{
        'id': id,
        'timestamp': timestamp.toIso8601String(),
        'method': method,
        'url': url,
        'endpoint': endpoint,
        'query_params': queryParams,
        'request_headers': requestHeaders,
        'request_body': requestBody,
        'status_code': statusCode,
        'status_message': statusMessage,
        'response_headers': responseHeaders,
        'response_body': responseBody,
        'duration_ms': durationMs,
        'is_slow': isSlow,
        'is_error': isError,
        'error_type': errorType,
        'error_message': errorMessage,
        'stack_trace': stackTrace,
        'request_id': requestId,
        'server_duration_ms': serverDurationMs,
        'context': context,
      };

  factory ApiLogEntry.fromJson(Map<String, dynamic> json) {
    return ApiLogEntry(
      id: json['id']?.toString() ?? '',
      timestamp: json['timestamp'] != null
          ? DateTime.tryParse(json['timestamp'].toString()) ?? DateTime.now()
          : DateTime.now(),
      method: json['method']?.toString() ?? 'GET',
      url: json['url']?.toString() ?? '',
      endpoint: json['endpoint']?.toString() ?? '',
      queryParams: json['query_params'] is Map
          ? Map<String, dynamic>.from(json['query_params'] as Map)
          : null,
      requestHeaders: json['request_headers'] is Map
          ? Map<String, dynamic>.from(json['request_headers'] as Map)
          : null,
      requestBody: json['request_body'],
      statusCode: json['status_code'] is int ? json['status_code'] as int : null,
      statusMessage: json['status_message']?.toString(),
      responseHeaders: json['response_headers'] is Map
          ? Map<String, dynamic>.from(json['response_headers'] as Map)
          : null,
      responseBody: json['response_body'],
      durationMs: json['duration_ms'] is int ? json['duration_ms'] as int : 0,
      isSlow: json['is_slow'] == true,
      isError: json['is_error'] == true,
      errorType: json['error_type']?.toString(),
      errorMessage: json['error_message']?.toString(),
      stackTrace: json['stack_trace']?.toString(),
      requestId: json['request_id']?.toString(),
      serverDurationMs: json['server_duration_ms'] is int
          ? json['server_duration_ms'] as int
          : null,
      context: json['context']?.toString(),
    );
  }
}
