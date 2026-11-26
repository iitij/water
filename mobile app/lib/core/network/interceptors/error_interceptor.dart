import 'dart:async';
import 'dart:io';

import 'package:dio/dio.dart';

import '../../diagnostics/error_reporter.dart';

class ErrorInterceptor extends Interceptor {
  ErrorInterceptor({ErrorReporter? errorReporter}) : _reporter = errorReporter;

  Future<void> Function()? onUnauthorized;
  final ErrorReporter? _reporter;

  /// رمز الخادم للجهاز المُبطَل (خطة 2.0 — الفصل 9.7).
  static const String deviceRevokedCode = 'DEVICE_REVOKED';

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    // 401 يعني إمّا انتهاء الجلسة أو إبطال الجهاز من لوحة الإدارة؛ في الحالتين
    // لا مفر من الخروج وتسجيل الدخول من جديد.
    if (err.response?.statusCode == 401) {
      final callback = onUnauthorized;
      if (callback != null) unawaited(callback());
    }
    final message = _messageFor(err);
    _reporter?.report(
      err,
      stackTrace: err.stackTrace,
      context: 'http',
      statusCode: err.response?.statusCode,
      endpoint: err.requestOptions.path,
    );
    handler.reject(err.copyWith(message: message));
  }

  String _messageFor(DioException error) {
    final response = error.response;
    final body = response?.data;
    if (body is Map && body['code']?.toString() == deviceRevokedCode) {
      return 'تم إيقاف هذا الجهاز من لوحة الإدارة. سجّل الدخول من جهاز مصرّح.';
    }
    final statusCode = response?.statusCode;
    final serverMessage = _firstServerMessage(response?.data);

    if (statusCode == 422) {
      final message = serverMessage;
      if (message == null || RegExp(r'^(the|a |an )', caseSensitive: false).hasMatch(message)) {
        return 'يرجى مراجعة البيانات المدخلة.';
      }
      return message;
    }
    if (statusCode == 401) return 'انتهت الجلسة. سجّل الدخول من جديد.';
    if (statusCode == 403) return 'ليس لديك صلاحية لتنفيذ هذا الإجراء.';
    if (statusCode == 404) return serverMessage ?? 'العنصر المطلوب غير موجود.';
    if (statusCode == 409) return serverMessage ?? 'تعذر إكمال العملية بسبب تعارض في البيانات.';
    if (statusCode == 429) return 'تم إرسال طلبات كثيرة. انتظر قليلاً ثم أعد المحاولة.';
    if (statusCode != null && statusCode >= 500) {
      return serverMessage ?? 'حدث خطأ في الخادم. حاول مرة أخرى لاحقاً.';
    }

    switch (error.type) {
      case DioExceptionType.connectionTimeout:
      case DioExceptionType.sendTimeout:
      case DioExceptionType.receiveTimeout:
      case DioExceptionType.transformTimeout:
        return 'انتهت مهلة الاتصال. تحقق من الشبكة ثم أعد المحاولة.';
      case DioExceptionType.connectionError:
        return 'لا يوجد اتصال بالخادم. يمكنك متابعة العمل ومزامنة التغييرات لاحقاً.';
      case DioExceptionType.cancel:
        return 'تم إلغاء الطلب.';
      case DioExceptionType.badCertificate:
        return 'تعذر التحقق من شهادة أمان الخادم.';
      case DioExceptionType.badResponse:
        return serverMessage ?? 'تعذر معالجة الطلب.';
      case DioExceptionType.unknown:
        return serverMessage ?? messageForUnknownError(error.error);
    }
  }

  /// Classifies underlying transport, TLS, or payload errors when Dio emits
  /// [DioExceptionType.unknown] (for example Windows `SocketException` after
  /// `openUrl`, `HttpException` stream drops, `TlsException`, or non-JSON 2xx).
  static String messageForUnknownError(Object? cause) {
    if (cause is TlsException ||
        cause is HandshakeException ||
        cause is CertificateException) {
      return 'تعذر إنشاء اتصال آمن (TLS) مع الخادم. تحقق من شهادة الأمان أو إعدادات الشبكة والـ VPN ثم أعد المحاولة.';
    }
    if (cause is SocketException || cause is HttpException) {
      return 'تعذر الاتصال بالخادم أو انقطع الاتصال أثناء الطلب. تحقق من الشبكة أو الـ VPN ثم أعد المحاولة.';
    }
    if (cause is FormatException) {
      return 'استجاب الخادم بصيغة غير متوقعة. تحقق من عنوان الخادم أو حالة الاتصال ثم أعد المحاولة.';
    }
    return 'حدث خطأ غير متوقع. حاول مرة أخرى.';
  }

  String? _firstServerMessage(dynamic data) {
    if (data is! Map) return null;
    final message = data['message'];
    if (message is String && message.trim().isNotEmpty) return message;

    final errors = data['errors'];
    if (errors is Map) {
      for (final value in errors.values) {
        if (value is String && value.trim().isNotEmpty) return value;
        if (value is List && value.isNotEmpty) return value.first.toString();
      }
    }
    return null;
  }
}
