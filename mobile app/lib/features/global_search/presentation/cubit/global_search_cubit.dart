import 'dart:async';

import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/fouad/api_client.dart';
import '../../data/global_search_repository.dart';
import '../../data/models/global_search_models.dart';

class GlobalSearchState extends Equatable {
  const GlobalSearchState({
    this.query = '',
    this.loading = false,
    this.groups = const <GlobalSearchGroup>[],
    this.error,
    this.searched = false,
  });

  final String query;
  final bool loading;
  final List<GlobalSearchGroup> groups;
  final String? error;

  /// هل أُرسل استعلام صالح مرة واحدة على الأقل؟
  final bool searched;

  bool get hasResults => groups.any((group) => group.items.isNotEmpty);

  bool get needsMoreCharacters => query.trim().isNotEmpty && query.trim().length < 2;

  int get resultCount => groups.fold<int>(0, (sum, group) => sum + group.items.length);

  GlobalSearchState copyWith({
    String? query,
    bool? loading,
    List<GlobalSearchGroup>? groups,
    String? error,
    bool clearError = false,
    bool? searched,
  }) {
    return GlobalSearchState(
      query: query ?? this.query,
      loading: loading ?? this.loading,
      groups: groups ?? this.groups,
      error: clearError ? null : (error ?? this.error),
      searched: searched ?? this.searched,
    );
  }

  @override
  List<Object?> get props => <Object?>[query, loading, groups, error, searched];
}

/// يبحث في كل موارد النظام عبر نقطة `/api/search` بعد توقف الكتابة 320ms،
/// ويُسقط الردود القديمة إذا تغيّر الاستعلام أثناء وصولها.
class GlobalSearchCubit extends Cubit<GlobalSearchState> {
  GlobalSearchCubit(this._repository) : super(const GlobalSearchState());

  final GlobalSearchRepository _repository;
  Timer? _debounce;
  int _requestId = 0;

  void search(String raw) {
    _debounce?.cancel();
    final trimmed = raw.trim();

    if (trimmed.length < 2) {
      _requestId++;
      emit(GlobalSearchState(query: raw));
      return;
    }

    emit(state.copyWith(query: raw, loading: true, clearError: true, searched: true));
    _debounce = Timer(const Duration(milliseconds: 320), () => unawaited(_load(trimmed)));
  }

  Future<void> retry() async {
    final trimmed = state.query.trim();
    if (trimmed.length < 2) return;
    emit(state.copyWith(loading: true, clearError: true, searched: true));
    await _load(trimmed);
  }

  Future<void> _load(String query) async {
    final requestId = ++_requestId;
    try {
      final response = await _repository.search(query);
      if (isClosed || requestId != _requestId) return;
      emit(
        state.copyWith(
          loading: false,
          groups: response.groups,
          clearError: true,
          searched: true,
        ),
      );
    } catch (error) {
      if (isClosed || requestId != _requestId) return;
      emit(
        state.copyWith(
          loading: false,
          groups: const <GlobalSearchGroup>[],
          error: friendlyError(error),
          searched: true,
        ),
      );
    }
  }

  @override
  Future<void> close() {
    _debounce?.cancel();
    return super.close();
  }
}
