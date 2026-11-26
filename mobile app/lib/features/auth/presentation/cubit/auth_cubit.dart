import 'dart:io';

import 'package:dio/dio.dart';
import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/auth/session_service.dart';
import '../../../../core/diagnostics/api_log_manager.dart';
import '../../../../core/diagnostics/error_reporter.dart';

class AuthCubit extends Cubit<AuthState> {
  AuthCubit(
    this._session, {
    ErrorReporter? errorReporter,
    ApiLogManager? apiLogManager,
  }) : _errorReporter = errorReporter,
       _apiLogManager = apiLogManager,
       super(const AuthState());

  final SessionService _session;
  final ErrorReporter? _errorReporter;
  final ApiLogManager? _apiLogManager;

  Future<void> login({required String email, required String password, bool rememberSession = true}) async {
    emit(const AuthState(status: AuthStatus.loading, operation: AuthOperation.login));
    try {
      await _session.login(email: email, password: password, rememberSession: rememberSession);
      emit(const AuthState(status: AuthStatus.success, operation: AuthOperation.login));
    } catch (error, stackTrace) {
      _emitFailure(AuthOperation.login, error, stackTrace);
    }
  }

  Future<void> updateProfile({
    required String name,
    required String email,
    String? password,
    File? avatar,
  }) async {
    emit(const AuthState(status: AuthStatus.loading, operation: AuthOperation.profile));
    try {
      await _session.updateProfile(
        name: name,
        email: email,
        password: password,
        avatar: avatar,
      );
      emit(const AuthState(status: AuthStatus.success, operation: AuthOperation.profile));
    } catch (error, stackTrace) {
      _emitFailure(AuthOperation.profile, error, stackTrace);
    }
  }

  Future<void> logout() async {
    emit(const AuthState(status: AuthStatus.loading, operation: AuthOperation.logout));
    try {
      await _session.logout();
      emit(const AuthState(status: AuthStatus.success, operation: AuthOperation.logout));
    } catch (error, stackTrace) {
      _emitFailure(AuthOperation.logout, error, stackTrace);
    }
  }

  void _emitFailure(
    AuthOperation operation,
    Object error,
    StackTrace stackTrace,
  ) {
    final context = 'auth.${operation.name}';
    if (error is! DioException && error is! FormatException) {
      _errorReporter?.report(error, stackTrace: stackTrace, context: context);
      (_apiLogManager ?? ApiLogManager.instance).logAppError(
        context,
        error,
        stackTrace: stackTrace,
      );
    }
    emit(AuthState(
      status: AuthStatus.failure,
      operation: operation,
      message: _errorMessage(error),
      diagnosticDetail: _diagnosticDetail(error),
    ));
  }

  String _errorMessage(Object error) {
    if (error is DioException) return error.message ?? 'تعذر إكمال الطلب.';
    if (error is FormatException) return error.message;
    return 'حدث خطأ غير متوقع. حاول مرة أخرى.';
  }

  String? _diagnosticDetail(Object error) {
    if (error is FormatException) return null;
    if (error is DioException && error.type != DioExceptionType.unknown) {
      return null;
    }
    final raw = error is DioException ? (error.error ?? error) : error;
    final sanitized = redact(raw.toString()).replaceAll(RegExp(r'\s+'), ' ').trim();
    if (sanitized.isEmpty) return error.runtimeType.toString();
    return sanitized.length <= 160 ? sanitized : '${sanitized.substring(0, 160)}…';
  }
}

enum AuthStatus { initial, loading, success, failure }
enum AuthOperation { none, login, profile, logout }

class AuthState extends Equatable {
  const AuthState({
    this.status = AuthStatus.initial,
    this.operation = AuthOperation.none,
    this.message,
    this.diagnosticDetail,
  });

  final AuthStatus status;
  final AuthOperation operation;
  final String? message;
  final String? diagnosticDetail;

  @override
  List<Object?> get props => <Object?>[
    status,
    operation,
    message,
    diagnosticDetail,
  ];
}
