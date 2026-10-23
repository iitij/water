import 'dart:async';

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
    this.errorMessage,
    this.isMutating = false,
  });

  final MetersLoadStatus status;
  final List<Meter> meters;
  final List<SquareOption> squares;
  final String query;
  final String? statusFilter;
  final int? squareFilter;
  final String? errorMessage;
  final bool isMutating;

  MetersState copyWith({
    MetersLoadStatus? status,
    List<Meter>? meters,
    List<SquareOption>? squares,
    String? query,
    String? statusFilter,
    int? squareFilter,
    String? errorMessage,
    bool clearStatusFilter = false,
    bool clearSquareFilter = false,
    bool clearError = false,
    bool? isMutating,
  }) {
    return MetersState(
      status: status ?? this.status,
      meters: meters ?? this.meters,
      squares: squares ?? this.squares,
      query: query ?? this.query,
      statusFilter: clearStatusFilter ? null : statusFilter ?? this.statusFilter,
      squareFilter: clearSquareFilter ? null : squareFilter ?? this.squareFilter,
      errorMessage: clearError ? null : errorMessage ?? this.errorMessage,
      isMutating: isMutating ?? this.isMutating,
    );
  }
}

class MetersCubit extends Cubit<MetersState> {
  MetersCubit(this._repository) : super(const MetersState());

  final MetersRepository _repository;
  Timer? _searchTimer;

  Future<void> load() async {
    emit(state.copyWith(
      status: MetersLoadStatus.loading,
      clearError: true,
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
    emit(state.copyWith(isMutating: true, clearError: true));
    try {
      await _repository.deleteMeter(meter.id);
      emit(state.copyWith(isMutating: false));
      await load();
      return true;
    } catch (error) {
      emit(state.copyWith(isMutating: false, errorMessage: _messageFor(error)));
      return false;
    }
  }

  static String _messageFor(Object error) {
    if (error is StateError) return error.message.toString();
    return 'تعذر تحميل بيانات العدادات. تحقق من الاتصال وحاول مرة أخرى.';
  }

  @override
  Future<void> close() {
    _searchTimer?.cancel();
    return super.close();
  }
}
