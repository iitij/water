import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../domain/entities/settings_entities.dart';
import '../../domain/repositories/settings_repository.dart';

class ReadingSettingsState extends Equatable {
  const ReadingSettingsState({
    this.settings,
    this.isLoading = false,
    this.isSaving = false,
    this.saved = false,
    this.errorMessage,
  });

  final ReadingSettings? settings;
  final bool isLoading;
  final bool isSaving;
  final bool saved;
  final String? errorMessage;

  ReadingSettingsState copyWith({
    ReadingSettings? settings,
    bool? isLoading,
    bool? isSaving,
    bool? saved,
    String? errorMessage,
    bool clearError = false,
  }) {
    return ReadingSettingsState(
      settings: settings ?? this.settings,
      isLoading: isLoading ?? this.isLoading,
      isSaving: isSaving ?? this.isSaving,
      saved: saved ?? false,
      errorMessage: clearError ? null : errorMessage ?? this.errorMessage,
    );
  }

  @override
  List<Object?> get props => [settings, isLoading, isSaving, saved, errorMessage];
}

class ReadingSettingsCubit extends Cubit<ReadingSettingsState> {
  ReadingSettingsCubit(this._repository) : super(const ReadingSettingsState());

  final SettingsRepository _repository;

  Future<void> load() async {
    if (state.isLoading) return;
    emit(state.copyWith(isLoading: true, clearError: true));
    try {
      final settings = await _repository.getReadingSettings();
      emit(ReadingSettingsState(settings: settings));
    } catch (error) {
      emit(state.copyWith(isLoading: false, errorMessage: error.toString()));
    }
  }

  void update(ReadingSettings settings) {
    emit(state.copyWith(settings: settings, saved: false, clearError: true));
  }

  Future<void> save() async {
    final settings = state.settings;
    if (settings == null || state.isSaving) return;
    if (settings.gracePeriod < 0 || settings.gracePeriod > 365) {
      emit(state.copyWith(errorMessage: 'مدة السماح يجب أن تكون بين 0 و365 يوماً.'));
      return;
    }
    const periods = {'weekly', 'ten_days', 'half_monthly', 'monthly'};
    if (!periods.contains(settings.readingPeriod)) {
      emit(state.copyWith(errorMessage: 'دورة القراءات المحددة غير صالحة.'));
      return;
    }
    final readingDay = int.tryParse(settings.readingDay);
    if (settings.readingPeriod == 'monthly' &&
        settings.readingDay != 'last_day' &&
        (readingDay == null || readingDay < 20 || readingDay > 28)) {
      emit(state.copyWith(errorMessage: 'يوم القراءة الشهري يجب أن يكون من 20 إلى 28.'));
      return;
    }
    emit(state.copyWith(isSaving: true, saved: false, clearError: true));
    try {
      await _repository.saveReadingSettings(settings);
      emit(state.copyWith(isSaving: false, saved: true));
    } catch (error) {
      emit(state.copyWith(isSaving: false, errorMessage: error.toString()));
    }
  }
}
