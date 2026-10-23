import 'dart:async';

import 'package:dio/dio.dart';

class ErrorInterceptor extends Interceptor {
  Future<void> Function()? onUnauthorized;
  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    if (err.response?.statusCode == 401) {
      final callback = onUnauthorized;
      if (callback != null) unawaited(callback());
    }
    final message = _messageFor(err);
    handler.reject(err.copyWith(message: message));
  }

  String _messageFor(DioException error) {
    final response = error.response;
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
        return serverMessage ?? 'حدث خطأ غير متوقع. حاول مرة أخرى.';
    }
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
