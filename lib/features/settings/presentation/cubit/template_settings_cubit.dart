import 'dart:typed_data';

import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../domain/entities/settings_entities.dart';
import '../../domain/repositories/settings_repository.dart';

class TemplateSettingsState extends Equatable {
  const TemplateSettingsState({
    this.settings,
    this.isLoading = false,
    this.isSaving = false,
    this.saved = false,
    this.uploadingImages = const {},
    this.errorMessage,
  });

  final TemplateSettings? settings;
  final bool isLoading;
  final bool isSaving;
  final bool saved;
  final Set<String> uploadingImages;
  final String? errorMessage;

  TemplateSettingsState copyWith({
    TemplateSettings? settings,
    bool? isLoading,
    bool? isSaving,
    bool? saved,
    Set<String>? uploadingImages,
    String? errorMessage,
    bool clearError = false,
  }) {
    return TemplateSettingsState(
      settings: settings ?? this.settings,
      isLoading: isLoading ?? this.isLoading,
      isSaving: isSaving ?? this.isSaving,
      saved: saved ?? false,
      uploadingImages: uploadingImages ?? this.uploadingImages,
      errorMessage: clearError ? null : errorMessage ?? this.errorMessage,
    );
  }

  @override
  List<Object?> get props => [settings, isLoading, isSaving, saved, uploadingImages, errorMessage];
}

class TemplateSettingsCubit extends Cubit<TemplateSettingsState> {
  TemplateSettingsCubit(this._repository, this.kind)
      : super(const TemplateSettingsState());

  final SettingsRepository _repository;
  final TemplateKind kind;

  Future<void> load() async {
    if (state.isLoading) return;
    emit(state.copyWith(isLoading: true, clearError: true));
    try {
      final settings = await _repository.getTemplateSettings(kind);
      emit(TemplateSettingsState(settings: settings));
    } catch (error) {
      emit(state.copyWith(isLoading: false, errorMessage: error.toString()));
    }
  }

  void updateValue(String key, Object? value) {
    final settings = state.settings;
    if (settings == null) return;
    emit(
      state.copyWith(
        settings: settings.copyWith(key, value),
        saved: false,
        clearError: true,
      ),
    );
  }

  Future<void> save() async {
    final settings = state.settings;
    if (settings == null || state.isSaving || state.uploadingImages.isNotEmpty) return;
    final emptyRequired = settings.values.keys.where(
      (key) => (key.contains('_lbl_') ||
              key.endsWith('_title_main') ||
              key.endsWith('_title_details') ||
              key == 'inv_title_subscriber' ||
              key == 'inv_title_consumption' ||
              key == 'inv_title_total' ||
              key == 'rect_title_subscriber' ||
              key == 'rect_title_payment') &&
          settings.text(key).trim().isEmpty,
    );
    if (emptyRequired.isNotEmpty) {
      emit(state.copyWith(errorMessage: 'أكمل جميع المسميات والعناوين المطلوبة قبل الحفظ.'));
      return;
    }
    emit(state.copyWith(isSaving: true, saved: false, clearError: true));
    try {
      await _repository.saveTemplateSettings(settings);
      emit(state.copyWith(isSaving: false, saved: true));
    } catch (error) {
      emit(state.copyWith(isSaving: false, errorMessage: error.toString()));
    }
  }

  Future<void> uploadImage(String key, Uint8List bytes, String fileName) async {
    if (bytes.length > 5 * 1024 * 1024) {
      emit(state.copyWith(errorMessage: 'حجم الصورة يجب ألا يتجاوز 5 ميجابايت.'));
      return;
    }
    final extension = fileName.split('.').last.toLowerCase();
    if (!{'jpg', 'jpeg', 'png', 'webp'}.contains(extension)) {
      emit(state.copyWith(errorMessage: 'صيغ الصور المدعومة: JPG، PNG، WEBP.'));
      return;
    }
    emit(
      state.copyWith(
        uploadingImages: {...state.uploadingImages, key},
        saved: false,
        clearError: true,
      ),
    );
    try {
      final path = await _repository.uploadImage(
        section: kind == TemplateKind.invoice
            ? SettingsSection.invoice
            : SettingsSection.receipt,
        key: key,
        bytes: bytes,
        fileName: fileName,
      );
      final settings = state.settings;
      emit(
        state.copyWith(
          settings: settings?.copyWith(key, path),
          uploadingImages: {...state.uploadingImages}..remove(key),
        ),
      );
    } catch (error) {
      emit(
        state.copyWith(
          uploadingImages: {...state.uploadingImages}..remove(key),
          errorMessage: error.toString(),
        ),
      );
    }
  }
}
