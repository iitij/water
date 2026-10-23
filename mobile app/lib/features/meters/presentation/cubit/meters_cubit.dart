import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../data/models/meter.dart';
import '../../domain/meters_repository.dart';

enum MetersLoadStatus { initial, loading, success, failure }

class MetersState {
  const MetersState({
    this.status = MetersLoadStatus.initial,
    this.meters = const [],
    this.squares = const [],
    this.query = '',
    this.statusFilter,
    this.squareFilter,
    this.selectedIds = const <int>{},
    this.errorMessage,
    this.actionMessage,
    this.isMutating = false,
  });

  final MetersLoadStatus status;
  final List<Meter> meters;
  final List<SquareOption> squares;
  final String query;
  final String? statusFilter;
  final int? squareFilter;
  final Set<int> selectedIds;
  final String? errorMessage;
  final String? actionMessage;
  final bool isMutating;

  bool get isSelecting => selectedIds.isNotEmpty;

  MetersState copyWith({
    MetersLoadStatus? status,
    List<Meter>? meters,
    List<SquareOption>? squares,
    String? query,
    String? statusFilter,
    int? squareFilter,
    Set<int>? selectedIds,
    String? errorMessage,
    String? actionMessage,
    bool clearStatusFilter = false,
    bool clearSquareFilter = false,
    bool clearError = false,
    bool clearActionMessage = false,
    bool? isMutating,
  }) {
    return MetersState(
      status: status ?? this.status,
      meters: meters ?? this.meters,
      squares: squares ?? this.squares,
      query: query ?? this.query,
      statusFilter: clearStatusFilter ? null : statusFilter ?? this.statusFilter,
      squareFilter: clearSquareFilter ? null : squareFilter ?? this.squareFilter,
      selectedIds: selectedIds ?? this.selectedIds,
      errorMessage: clearError ? null : errorMessage ?? this.errorMessage,
      actionMessage: clearActionMessage ? null : actionMessage ?? this.actionMessage,
      isMutating: isMutating ?? this.isMutating,
    );
  }
}

class MetersCubit extends Cubit<MetersState> {
  MetersCubit(this._repository) : super(const MetersState());

  final MetersRepository _repository;
  Timer? _searchTimer;

  Future<void> load({bool keepSelection = false}) async {
    emit(state.copyWith(
      status: MetersLoadStatus.loading,
      clearError: true,
      selectedIds: keepSelection ? state.selectedIds : const <int>{},
    ));
    try {
      final results = await Future.wait<Object>([
        _repository.getMeters(
          query: state.query,
          squareId: state.squareFilter,
          status: state.statusFilter,
        ),
        _repository.getSquares(),
      ]);
      emit(state.copyWith(
        status: MetersLoadStatus.success,
        meters: results[0] as List<Meter>,
        squares: results[1] as List<SquareOption>,
      ));
    } catch (error) {
      emit(state.copyWith(
        status: state.meters.isEmpty ? MetersLoadStatus.failure : MetersLoadStatus.success,
        errorMessage: _messageFor(error),
      ));
    }
  }

  void setQuery(String value) {
    emit(state.copyWith(query: value));
    _searchTimer?.cancel();
    _searchTimer = Timer(const Duration(milliseconds: 300), load);
  }

  void setSquareFilter(int? squareId) {
    emit(state.copyWith(
      squareFilter: squareId,
      clearSquareFilter: squareId == null,
    ));
    load();
  }

  void setStatusFilter(String? status) {
    emit(state.copyWith(
      statusFilter: status,
      clearStatusFilter: status == null,
    ));
    load();
  }

  void toggleSelection(int meterId) {
    final next = Set<int>.from(state.selectedIds);
    if (!next.add(meterId)) next.remove(meterId);
    emit(state.copyWith(selectedIds: next));
  }

  void selectAllVisible() {
    final next = <int>{...state.selectedIds, ...state.meters.map((m) => m.id)};
    emit(state.copyWith(selectedIds: next));
  }

  void clearSelection() => emit(state.copyWith(selectedIds: const <int>{}));

  Future<Meter?> nextPath(Meter current) async {
    final squareId = current.squareId ?? state.squareFilter;
    if (squareId == null || current.pathNumber == null) return null;
    final meters = await _repository.getMeters(squareId: squareId, status: 'active');
    final next = meters
        .where((meter) => meter.pathNumber != null && meter.pathNumber! > current.pathNumber!)
        .toList()
      ..sort((a, b) => a.pathNumber!.compareTo(b.pathNumber!));
    return next.isEmpty ? null : next.first;
  }

  Future<bool> deleteMeter(Meter meter) async {
    emit(state.copyWith(isMutating: true, clearError: true, clearActionMessage: true));
    try {
      await _repository.deleteMeter(meter.id);
      final nextSelected = Set<int>.from(state.selectedIds)..remove(meter.id);
      emit(state.copyWith(
        isMutating: false,
        selectedIds: nextSelected,
        actionMessage: 'تم حذف العداد ${meter.uniqueCode} بنجاح.',
      ));
      await load(keepSelection: true);
      return true;
    } catch (error) {
      emit(state.copyWith(isMutating: false, errorMessage: _messageFor(error)));
      return false;
    }
  }

  Future<bool> deleteSelectedMeters() async {
    final ids = state.selectedIds.toList(growable: false);
    if (ids.isEmpty) return false;
    emit(state.copyWith(isMutating: true, clearError: true, clearActionMessage: true));
    try {
      final result = await _repository.bulkDeleteMeters(ids);
      final summary = result.skippedCount > 0
          ? 'تم حذف ${result.deletedCount} عداد، وتعذر حذف ${result.skippedCount} لوجود سجل قراءات أو فواتير مرتبط.'
          : 'تم حذف ${result.deletedCount} عداد بنجاح.';
      emit(state.copyWith(
        isMutating: false,
        selectedIds: const <int>{},
        actionMessage: summary,
      ));
      await load();
      return true;
    } catch (error) {
      emit(state.copyWith(isMutating: false, errorMessage: _messageFor(error)));
      return false;
    }
  }

  static String _messageFor(Object error) {
    if (error is StateError) return error.message.toString();
    if (error is DioException) {
      final data = error.response?.data;
      if (data is Map) {
        final errors = data['errors'];
        if (errors is Map && errors.isNotEmpty) {
          final first = errors.values.first;
          if (first is List && first.isNotEmpty) return first.first.toString();
          return first.toString();
        }
        if (data['message'] != null) return data['message'].toString();
      }
      if (error.response == null) {
        return 'لا يوجد اتصال بالخادم. تحقق من الإنترنت وحاول مرة أخرى.';
      }
    }
    return 'تعذر تنفيذ العملية على بيانات العدادات. تحقق من الاتصال وحاول مرة أخرى.';
  }

  @override
  Future<void> close() {
    _searchTimer?.cancel();
    return super.close();
  }
}
