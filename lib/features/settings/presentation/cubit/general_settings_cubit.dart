import 'dart:typed_data';

import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../domain/entities/settings_entities.dart';
import '../../domain/repositories/settings_repository.dart';

class GeneralSettingsState extends Equatable {
  const GeneralSettingsState({
    this.items = const [],
    this.query = '',
    this.isLoading = false,
    this.isSaving = false,
    this.deletingIds = const {},
    this.errorMessage,
  });

  final List<GeneralSetting> items;
  final String query;
  final bool isLoading;
  final bool isSaving;
  final Set<int> deletingIds;
  final String? errorMessage;

  List<GeneralSetting> get visibleItems {
    final needle = query.trim().toLowerCase();
    if (needle.isEmpty) return items;
    return items
        .where(
          (item) => item.key.toLowerCase().contains(needle) ||
              item.label.toLowerCase().contains(needle) ||
              item.value.toLowerCase().contains(needle),
        )
        .toList(growable: false);
  }

  GeneralSettingsState copyWith({
    List<GeneralSetting>? items,
    String? query,
    bool? isLoading,
    bool? isSaving,
    Set<int>? deletingIds,
    String? errorMessage,
    bool clearError = false,
  }) {
    return GeneralSettingsState(
      items: items ?? this.items,
      query: query ?? this.query,
      isLoading: isLoading ?? this.isLoading,
      isSaving: isSaving ?? this.isSaving,
      deletingIds: deletingIds ?? this.deletingIds,
      errorMessage: clearError ? null : errorMessage ?? this.errorMessage,
    );
  }

  @override
  List<Object?> get props => [items, query, isLoading, isSaving, deletingIds, errorMessage];
}

class GeneralSettingsCubit extends Cubit<GeneralSettingsState> {
  GeneralSettingsCubit(this._repository) : super(const GeneralSettingsState());

  final SettingsRepository _repository;

  Future<void> load() async {
    if (state.isLoading) return;
    emit(state.copyWith(isLoading: true, clearError: true));
    try {
      final items = await _repository.getGeneralSettings();
      emit(GeneralSettingsState(items: items, query: state.query));
    } catch (error) {
      emit(state.copyWith(isLoading: false, errorMessage: error.toString()));
    }
  }

  void search(String query) => emit(state.copyWith(query: query));

  Future<bool> save(GeneralSetting setting) async {
    if (state.isSaving) return false;
    if (setting.key.trim().isEmpty || setting.label.trim().isEmpty) {
      emit(state.copyWith(errorMessage: 'المفتاح البرمجي واسم الإعداد مطلوبان.'));
      return false;
    }
    emit(state.copyWith(isSaving: true, clearError: true));
    try {
      final saved = setting.id == 0
          ? await _repository.createGeneralSetting(setting)
          : await _repository.updateGeneralSetting(setting);
      final items = [...state.items];
      final index = items.indexWhere((item) => item.id == saved.id);
      if (index >= 0) {
        items[index] = saved;
      } else {
        items.add(saved);
      }
      items.sort((a, b) => a.label.compareTo(b.label));
      emit(state.copyWith(items: items, isSaving: false));
      return true;
    } catch (error) {
      emit(state.copyWith(isSaving: false, errorMessage: error.toString()));
      return false;
    }
  }

  Future<void> delete(GeneralSetting setting) async {
    if (state.deletingIds.contains(setting.id)) return;
    emit(state.copyWith(deletingIds: {...state.deletingIds, setting.id}, clearError: true));
    try {
      await _repository.deleteGeneralSetting(setting);
      emit(
        state.copyWith(
          items: state.items.where((item) => item.id != setting.id).toList(),
          deletingIds: {...state.deletingIds}..remove(setting.id),
        ),
      );
    } catch (error) {
      emit(
        state.copyWith(
          deletingIds: {...state.deletingIds}..remove(setting.id),
          errorMessage: error.toString(),
        ),
      );
    }
  }

  Future<String?> uploadImage(String key, Uint8List bytes, String fileName) async {
    try {
      return await _repository.uploadImage(
        section: SettingsSection.general,
        key: key,
        bytes: bytes,
        fileName: fileName,
      );
    } catch (error) {
      emit(state.copyWith(errorMessage: error.toString()));
      return null;
    }
  }
}
