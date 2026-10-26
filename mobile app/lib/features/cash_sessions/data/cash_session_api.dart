import 'package:dio/dio.dart';

import '../../../core/error/failures.dart';
import '../../../core/l10n/app_strings.dart';
import '../../../core/network/api_endpoints.dart';
import '../domain/cash_session.dart';
import '../domain/cash_session_page.dart';

/// عميل واجهة صناديق المحصّل. لا يخزّن حالة؛ الحالة في `CashSessionService`.
class CashSessionApi {
  const CashSessionApi(this._dio);

  final Dio _dio;

  Future<CashSessionPage> list({
    CashSessionFilters filters = const CashSessionFilters(),
    int page = 1,
  }) async {
    try {
      final response = await _dio.get<dynamic>(
        ApiEndpoints.cashSessions,
        queryParameters: filters.toQuery(page: page),
      );
      final result = CashSessionPage.tryParse(response.data);
      if (result == null) {
        throw const ServerFailure(AppStringsDefaults.serverError);
      }
      return result;
    } on DioException catch (error) {
      throw _mapFailure(error);
    }
  }

  /// الصندوق المفتوح الحالي، أو `null` إن لم يفتح المحصّل صندوقاً.
  Future<CashSession?> current() async {
    try {
      final response = await _dio.get<dynamic>(ApiEndpoints.currentCashSession);
      final data = _asMap(response.data);
      return CashSession.tryParse(data?['cash_session']);
    } on DioException catch (error) {
      throw _mapFailure(error);
    }
  }

  Future<CashSession> open({
    double openingAmount = 0,
    int? squareId,
    String? notes,
  }) async {
    try {
      final response = await _dio.post<dynamic>(
        ApiEndpoints.cashSessions,
        data: <String, dynamic>{
          'opening_amount': openingAmount,
          if (squareId != null) 'square_id': squareId,
          if (notes != null && notes.trim().isNotEmpty) 'notes': notes.trim(),
        },
      );
      final session = CashSession.tryParse(
        _asMap(response.data)?['cash_session'],
      );
      if (session == null) {
        throw const ServerFailure('لم يُرجع الخادم بيانات الصندوق بعد الفتح.');
      }
      return session;
    } on DioException catch (error) {
      throw _mapFailure(error);
    }
  }

  Future<CashSession> close(
    int id, {
    required double countedAmount,
    String? notes,
  }) async {
    try {
      final response = await _dio.post<dynamic>(
        ApiEndpoints.closeCashSession(id),
        data: <String, dynamic>{
          'counted_amount': countedAmount,
          if (notes != null && notes.trim().isNotEmpty) 'notes': notes.trim(),
        },
      );
      final session = CashSession.tryParse(
        _asMap(response.data)?['cash_session'],
      );
      if (session == null) {
        throw const ServerFailure('لم يُرجع الخادم نتيجة إغلاق الصندوق.');
      }
      return session;
    } on DioException catch (error) {
      throw _mapFailure(error);
    }
  }

  Future<CashSession> approve(
    int id, {
    required bool approve,
    String? notes,
  }) async {
    try {
      final response = await _dio.post<dynamic>(
        ApiEndpoints.approveCashSession(id),
        data: <String, dynamic>{
          'approve': approve,
          if (notes != null && notes.trim().isNotEmpty) 'notes': notes.trim(),
        },
      );
      final session = CashSession.tryParse(
        _asMap(response.data)?['cash_session'],
      );
      if (session == null) {
        throw const ServerFailure('لم يُرجع الخادم نتيجة اعتماد الصندوق.');
      }
      return session;
    } on DioException catch (error) {
      throw _mapFailure(error);
    }
  }

  Future<CashSession> byId(int id) async {
    try {
      final response = await _dio.get<dynamic>(ApiEndpoints.cashSession(id));
      final session = CashSession.tryParse(
        _asMap(response.data)?['cash_session'],
      );
      if (session == null) {
        throw const ServerFailure('الصندوق المطلوب غير متاح.');
      }
      return session;
    } on DioException catch (error) {
      throw _mapFailure(error);
    }
  }

  Map<String, dynamic>? reportPayload(Object? raw) => _asMap(raw);

  static Map<String, dynamic>? _asMap(Object? value) {
    if (value is Map<String, dynamic>) return value;
    if (value is Map) return Map<String, dynamic>.from(value);
    return null;
  }

  AppFailure _mapFailure(DioException error) {
    final status = error.response?.statusCode;
    final body = _asMap(error.response?.data);
    final message = body?['message']?.toString();

    if (status == 422) {
      final errors = body?['errors'];
      return ValidationFailure(
        message?.trim().isNotEmpty == true
            ? message!.trim()
            : 'بيانات غير صحيحة.',
        errors is Map
            ? errors.map(
                (key, value) => MapEntry(
                  key.toString(),
                  value is List
                      ? value.map((e) => e.toString()).toList()
                      : <String>[],
                ),
              )
            : <String, List<String>>{},
      );
    }
    if (status == 401) {
      return const UnauthorizedFailure();
    }
    if (status == 403) {
      return ForbiddenFailure(
        message?.trim().isNotEmpty == true ? message!.trim() : null,
        'إدارة الصندوق',
      );
    }
    if (status == 404) {
      return const NotFoundFailure('الصندوق المطلوب غير موجود.');
    }
    if (status != null && status >= 500) {
      return const ServerFailure('خطأ مؤقت في الخادم. أعد المحاولة بعد قليل.');
    }
    return NetworkFailure(
      error.type == DioExceptionType.connectionTimeout ||
              error.type == DioExceptionType.receiveTimeout ||
              error.type == DioExceptionType.connectionError
          ? 'تعذر الاتصال بالخادم. تحقق من الشبكة.'
          : (message?.trim().isNotEmpty == true
                ? message!.trim()
                : 'تعذر تنفيذ الطلب.'),
    );
  }
}
