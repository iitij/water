import 'dart:io';

import 'package:dio/dio.dart';
import 'package:equatable/equatable.dart';

import '../l10n/app_strings.dart';
import '../network/interceptors/error_interceptor.dart';

/// فشل طبقة البيانات موحّد لكافة الوحدات — يعالج رسائل التحقق العربية للـ Laravel (422).
/// المرجع: `FLUTTER_MOBILE_MASTER_PLAN.md` §3.1 (`core/error/failures.dart`).
sealed class AppFailure extends Equatable {
  const AppFailure(this.message, {this.statusCode});

  /// الرسالة العربية الجاهزة للعرض في `SnackBar`.
  final String message;
  final int? statusCode;

  @override
  List<Object?> get props => <Object?>[message, statusCode];
}

class NetworkFailure extends AppFailure {
  const NetworkFailure([super.message = AppStringsDefaults.networkError])
      : super(statusCode: 0);
}

class TimeoutFailure extends AppFailure {
  const TimeoutFailure([super.message = AppStringsDefaults.timeoutError])
      : super(statusCode: 408);
}

class UnauthorizedFailure extends AppFailure {
  const UnauthorizedFailure([super.message = AppStringsDefaults.unauthorizedError])
      : super(statusCode: 401);
}

class ForbiddenFailure extends AppFailure {
  const ForbiddenFailure([
    String? message,
    this.permission,
  ]) : super(
          message ??
              (permission != null
                  ? '${AppStringsDefaults.forbiddenError} ($permission)'
                  : AppStringsDefaults.forbiddenError),
          statusCode: 403,
        );

  /// الصلاحية الناقصة (Spatie/Shield) إن عرفها الخادم.
  final String? permission;

  @override
  List<Object?> get props => <Object?>[message, statusCode, permission];
}

class NotFoundFailure extends AppFailure {
  const NotFoundFailure([super.message = AppStringsDefaults.notFoundError])
      : super(statusCode: 404);
}

/// خطأ تحقق من الخادم (422) مع خرائط الحقول المعربة.
class ValidationFailure extends AppFailure {
  const ValidationFailure(super.message, this.fieldErrors)
      : super(statusCode: 422);

  final Map<String, List<String>> fieldErrors;

  String? errorFor(String field) {
    final errors = fieldErrors[field];
    return (errors == null || errors.isEmpty) ? null : errors.first;
  }

  @override
  List<Object?> get props => <Object?>[message, statusCode, fieldErrors];
}

/// خطأ خادم عام (500+/استثناء Laravel) — يعرض رسالة `message` العربية.
class ServerFailure extends AppFailure {
  const ServerFailure(super.message, {super.statusCode});
}

class UnexpectedFailure extends AppFailure {
  const UnexpectedFailure([super.message = AppStringsDefaults.unknownError]);
}

/// يحوّل `DioException` إلى `AppFailure` بالعربية.
class FailureMapper {
  FailureMapper._();

  static AppFailure from(DioException error) {
    switch (error.type) {
      case DioExceptionType.connectionTimeout:
      case DioExceptionType.sendTimeout:
      case DioExceptionType.receiveTimeout:
      case DioExceptionType.transformTimeout:
        return const TimeoutFailure();
      case DioExceptionType.connectionError:
      case DioExceptionType.cancel:
        return const NetworkFailure();
      case DioExceptionType.badCertificate:
        return const NetworkFailure(
          'تعذر التحقق من شهادة أمان الخادم.',
        );
      case DioExceptionType.badResponse:
        return _fromResponse(error.response);
      case DioExceptionType.unknown:
        final inner = error.error;
        if (inner is TlsException ||
            inner is HandshakeException ||
            inner is CertificateException ||
            inner is SocketException ||
            inner is HttpException) {
          return NetworkFailure(ErrorInterceptor.messageForUnknownError(inner));
        }
        if (inner is FormatException) {
          return ServerFailure(
            AppStringsDefaults.invalidResponse,
            statusCode: error.response?.statusCode,
          );
        }
        return _fromResponse(error.response);
    }
  }

  static AppFailure fromResponse(Response<dynamic>? response) =>
      _fromResponse(response);

  static AppFailure _fromResponse(Response<dynamic>? response) {
    if (response == null) return const NetworkFailure();
    final status = response.statusCode ?? 0;
    final data = response.data;

    String? serverMessage;
    final Map<String, List<String>> fieldErrors = <String, List<String>>{};

    if (data is Map) {
      final message = data['message'];
      if (message is String) serverMessage = message;

      final errors = data['errors'];
      if (errors is Map) {
        errors.forEach((key, value) {
          final field = key.toString();
          if (value is List) {
            fieldErrors[field] = value.map((e) => e.toString()).toList();
          } else if (value is String) {
            fieldErrors[field] = <String>[value];
          }
        });
      }
    }

    switch (status) {
      case 401:
        return const UnauthorizedFailure();
      case 403:
        final permission = data is Map ? data['required_permission'] : null;
        return ForbiddenFailure(
          serverMessage ?? AppStringsDefaults.forbiddenError,
          permission?.toString(),
        );
      case 404:
        return NotFoundFailure(serverMessage ?? 'السجل غير موجود أو تم حذفه.');
      case 422:
        final first = fieldErrors.values.expand((e) => e).firstOrNull;
        return ValidationFailure(
          serverMessage ?? first ?? 'تحقق من الحقول المطلوبة.',
          fieldErrors,
        );
      default:
        if (status >= 500) {
          return ServerFailure(
            serverMessage ?? 'خطأ في الخادم ($status) — حاول لاحقاً.',
            statusCode: status,
          );
        }
        return ServerFailure(
          serverMessage ?? 'تعذّر تنفيذ الطلب ($status).',
          statusCode: status,
        );
    }
  }
}
