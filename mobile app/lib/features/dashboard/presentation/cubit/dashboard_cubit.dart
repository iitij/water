import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../domain/entities/dashboard_entities.dart';
import '../../domain/repositories/dashboard_repository.dart';

enum DashboardRequestStatus { initial, loading, success, failure }

class DashboardState extends Equatable {
  const DashboardState({
    this.status = DashboardRequestStatus.initial,
    this.snapshot,
    this.errorMessage,
    this.isRefreshing = false,
  });

  final DashboardRequestStatus status;
  final DashboardSnapshot? snapshot;
  final String? errorMessage;
  final bool isRefreshing;

  DashboardState copyWith({
    DashboardRequestStatus? status,
    DashboardSnapshot? snapshot,
    String? errorMessage,
    bool clearError = false,
    bool? isRefreshing,
  }) {
    return DashboardState(
      status: status ?? this.status,
      snapshot: snapshot ?? this.snapshot,
      errorMessage: clearError ? null : errorMessage ?? this.errorMessage,
      isRefreshing: isRefreshing ?? this.isRefreshing,
    );
  }

  @override
  List<Object?> get props => [status, snapshot, errorMessage, isRefreshing];
}

class DashboardCubit extends Cubit<DashboardState> {
  DashboardCubit(this._repository) : super(const DashboardState());

  final DashboardRepository _repository;

  Future<void> load({bool forceRefresh = false}) async {
    if (state.isRefreshing || state.status == DashboardRequestStatus.loading) return;
    emit(
      state.copyWith(
        status: state.snapshot == null
            ? DashboardRequestStatus.loading
            : DashboardRequestStatus.success,
        isRefreshing: state.snapshot != null,
        clearError: true,
      ),
    );
    try {
      final snapshot = await _repository.getDashboard(forceRefresh: forceRefresh);
      emit(
        DashboardState(
          status: DashboardRequestStatus.success,
          snapshot: snapshot,
        ),
      );
    } catch (error) {
      emit(
        state.copyWith(
          status: state.snapshot == null
              ? DashboardRequestStatus.failure
              : DashboardRequestStatus.success,
          errorMessage: error.toString(),
          isRefreshing: false,
        ),
      );
    }
  }

  Future<void> refresh() => load(forceRefresh: true);
}
