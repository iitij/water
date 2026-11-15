import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../domain/entities/settings_entities.dart';
import '../../domain/repositories/settings_repository.dart';

class WhatsAppSettingsState extends Equatable {
  const WhatsAppSettingsState({
    this.settings,
    this.serverStatus,
    this.isLoading = false,
    this.isSaving = false,
    this.isServerBusy = false,
    this.saved = false,
    this.errorMessage,
    this.serverError,
    this.serverSuccessMessage,
  });

  final WhatsAppSettings? settings;
  final LocalServerStatus? serverStatus;
  final bool isLoading;
  final bool isSaving;
  final bool isServerBusy;
  final bool saved;
  final String? errorMessage;
  final String? serverError;
  final String? serverSuccessMessage;

  WhatsAppSettingsState copyWith({
    WhatsAppSettings? settings,
    LocalServerStatus? serverStatus,
    bool? isLoading,
    bool? isSaving,
    bool? isServerBusy,
    bool? saved,
    String? errorMessage,
    String? serverError,
    String? serverSuccessMessage,
    bool clearError = false,
    bool clearServerError = false,
    bool clearServerSuccess = false,
  }) {
    return WhatsAppSettingsState(
      settings: settings ?? this.settings,
      serverStatus: serverStatus ?? this.serverStatus,
      isLoading: isLoading ?? this.isLoading,
      isSaving: isSaving ?? this.isSaving,
      isServerBusy: isServerBusy ?? this.isServerBusy,
      saved: saved ?? false,
      errorMessage: clearError ? null : errorMessage ?? this.errorMessage,
      serverError: clearServerError ? null : serverError ?? this.serverError,
      serverSuccessMessage: clearServerSuccess
          ? null
          : serverSuccessMessage ?? this.serverSuccessMessage,
    );
  }

  @override
  List<Object?> get props => [
        settings,
        serverStatus,
        isLoading,
        isSaving,
        isServerBusy,
        saved,
        errorMessage,
        serverError,
        serverSuccessMessage,
      ];
}

class WhatsAppSettingsCubit extends Cubit<WhatsAppSettingsState> {
  WhatsAppSettingsCubit(this._repository) : super(const WhatsAppSettingsState());

  final SettingsRepository _repository;
  bool _pollInFlight = false;

  Future<void> load() async {
    if (state.isLoading) return;
    emit(state.copyWith(isLoading: true, clearError: true));
    try {
      final settings = await _repository.getWhatsAppSettings();
      emit(WhatsAppSettingsState(settings: settings));
      if (settings.enabled && settings.method == 'local_api') {
        await refreshLocalServerStatus();
      }
    } catch (error) {
      emit(state.copyWith(isLoading: false, errorMessage: error.toString()));
    }
  }

  void update(WhatsAppSettings settings) {
    emit(state.copyWith(settings: settings, saved: false, clearError: true));
  }

  Future<void> save() async {
    final settings = state.settings;
    if (settings == null || state.isSaving) return;
    if (settings.enabled && settings.method == 'meta_api') {
      if (settings.phoneNumberId.trim().isEmpty) {
        emit(state.copyWith(errorMessage: 'أدخل Phone Number ID لإتمام إعداد Meta API.'));
        return;
      }
    }
    if (settings.enabled && settings.method != 'meta_api') {
      final templates = [
        settings.invoiceMessage,
        settings.paymentMessage,
        settings.subscriptionMessage,
      ];
      if (templates.any((template) => template.trim().isEmpty)) {
        emit(state.copyWith(errorMessage: 'لا يمكن حفظ قالب رسالة فارغ.'));
        return;
      }
    }
    emit(state.copyWith(isSaving: true, saved: false, clearError: true));
    try {
      await _repository.saveWhatsAppSettings(settings);
      emit(state.copyWith(isSaving: false, saved: true));
    } catch (error) {
      emit(state.copyWith(isSaving: false, errorMessage: error.toString()));
    }
  }

  /// Safe for the 3-second panel timer: overlapping polls are discarded.
  Future<void> refreshLocalServerStatus() async {
    if (_pollInFlight || isClosed) return;
    _pollInFlight = true;
    try {
      final status = await _repository.getLocalServerStatus();
      if (!isClosed) emit(state.copyWith(serverStatus: status, clearServerError: true));
    } catch (error) {
      if (!isClosed) emit(state.copyWith(serverError: error.toString()));
    } finally {
      _pollInFlight = false;
    }
  }

  Future<void> startLocalServer() async {
    if (state.isServerBusy) return;
    emit(state.copyWith(isServerBusy: true, clearServerError: true));
    try {
      final status = await _repository.startLocalServer();
      emit(state.copyWith(serverStatus: status, isServerBusy: false));
    } catch (error) {
      emit(state.copyWith(isServerBusy: false, serverError: error.toString()));
    }
  }

  Future<void> stopLocalServer() async {
    if (state.isServerBusy) return;
    emit(state.copyWith(isServerBusy: true, clearServerError: true, clearServerSuccess: true));
    try {
      final status = await _repository.stopLocalServer();
      emit(state.copyWith(serverStatus: status, isServerBusy: false));
    } catch (error) {
      emit(state.copyWith(isServerBusy: false, serverError: error.toString()));
    }
  }

  Future<void> logoutLocalServer() async {
    if (state.isServerBusy) return;
    emit(state.copyWith(isServerBusy: true, clearServerError: true, clearServerSuccess: true));
    try {
      final status = await _repository.logoutLocalServer();
      emit(
        state.copyWith(
          serverStatus: status,
          isServerBusy: false,
          serverSuccessMessage: 'تم تسجيل الخروج وفصل رقم الواتساب المرتبط.',
        ),
      );
      await refreshLocalServerStatus();
    } catch (error) {
      emit(state.copyWith(isServerBusy: false, serverError: error.toString()));
    }
  }

  Future<bool> sendTestMessage({
    required String phone,
    required String message,
  }) async {
    if (state.isServerBusy) return false;
    emit(state.copyWith(isServerBusy: true, clearServerError: true, clearServerSuccess: true));
    try {
      final info = await _repository.sendWhatsAppTestMessage(
        phone: phone,
        message: message,
      );
      emit(
        state.copyWith(
          isServerBusy: false,
          serverSuccessMessage: info,
        ),
      );
      return true;
    } catch (error) {
      emit(state.copyWith(isServerBusy: false, serverError: error.toString()));
      return false;
    }
  }
}
