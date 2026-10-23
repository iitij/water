import 'dart:io';

import 'package:dio/dio.dart';
import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/auth/session_service.dart';

class AuthCubit extends Cubit<AuthState> {
  AuthCubit(this._session) : super(const AuthState());

  final SessionService _session;

  Future<void> login({required String email, required String password, bool rememberSession = true}) async {
    emit(const AuthState(status: AuthStatus.loading, operation: AuthOperation.login));
    try {
      await _session.login(email: email, password: password, rememberSession: rememberSession);
      emit(const AuthState(status: AuthStatus.success, operation: AuthOperation.login));
    } catch (error) {
      emit(AuthState(
        status: AuthStatus.failure,
        operation: AuthOperation.login,
        message: _errorMessage(error),
      ));
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
    } catch (error) {
      emit(AuthState(
        status: AuthStatus.failure,
        operation: AuthOperation.profile,
        message: _errorMessage(error),
      ));
    }
  }

  Future<void> logout() async {
    emit(const AuthState(status: AuthStatus.loading, operation: AuthOperation.logout));
    try {
      await _session.logout();
      emit(const AuthState(status: AuthStatus.success, operation: AuthOperation.logout));
    } catch (error) {
      emit(AuthState(
        status: AuthStatus.failure,
        operation: AuthOperation.logout,
        message: _errorMessage(error),
      ));
    }
  }

  String _errorMessage(Object error) {
    if (error is DioException) return error.message ?? 'تعذر إكمال الطلب.';
    if (error is FormatException) return error.message;
    return 'حدث خطأ غير متوقع. حاول مرة أخرى.';
  }
}

enum AuthStatus { initial, loading, success, failure }
enum AuthOperation { none, login, profile, logout }

class AuthState extends Equatable {
  const AuthState({
    this.status = AuthStatus.initial,
    this.operation = AuthOperation.none,
    this.message,
  });

  final AuthStatus status;
  final AuthOperation operation;
  final String? message;

  @override
  List<Object?> get props => <Object?>[status, operation, message];
}
