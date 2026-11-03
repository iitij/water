import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../domain/entities/dashboard_entities.dart';
import '../../domain/repositories/dashboard_repository.dart';

enum ReportsRequestStatus { initial, loading, success, failure }

class ReportsState extends Equatable {
  const ReportsState({
    this.status = ReportsRequestStatus.initial,
    this.filter = const ReportFilter(),
    this.snapshot,
    this.errorMessage,
  });

  final ReportsRequestStatus status;
  final ReportFilter filter;
  final ReportSnapshot? snapshot;
  final String? errorMessage;

  ReportsState copyWith({
    ReportsRequestStatus? status,
    ReportFilter? filter,
    ReportSnapshot? snapshot,
    String? errorMessage,
    bool clearError = false,
    bool clearSnapshot = false,
  }) {
    return ReportsState(
      status: status ?? this.status,
      filter: filter ?? this.filter,
      snapshot: clearSnapshot ? null : snapshot ?? this.snapshot,
      errorMessage: clearError ? null : errorMessage ?? this.errorMessage,
    );
  }

  @override
  List<Object?> get props => [status, filter, snapshot, errorMessage];
}

class ReportsCubit extends Cubit<ReportsState> {
  ReportsCubit(this._repository, {DateTime Function()? now})
    : super(ReportsState(filter: currentMonth((now ?? DateTime.now)())));

  static ReportFilter currentMonth(DateTime now) => ReportFilter(
    from: DateTime(now.year, now.month),
    to: DateTime(now.year, now.month, now.day),
  );

  final DashboardRepository _repository;

  Future<void> load([ReportFilter? filter]) async {
    if (state.status == ReportsRequestStatus.loading) return;
    final effectiveFilter = filter ?? state.filter;
    if (!effectiveFilter.isValid) {
      emit(
        state.copyWith(
          status: ReportsRequestStatus.failure,
          filter: effectiveFilter,
          errorMessage: 'تاريخ البداية يجب ألا يكون بعد تاريخ النهاية.',
        ),
      );
      return;
    }
    emit(
      state.copyWith(
        status: ReportsRequestStatus.loading,
        filter: effectiveFilter,
        clearError: true,
        clearSnapshot: effectiveFilter != state.filter,
      ),
    );
    try {
      final snapshot = await _repository.getReport(effectiveFilter);
      if (isClosed) return;
      emit(
        ReportsState(
          status: ReportsRequestStatus.success,
          filter: effectiveFilter,
          snapshot: snapshot,
        ),
      );
    } catch (error) {
      if (isClosed) return;
      emit(
        state.copyWith(
          status: ReportsRequestStatus.failure,
          errorMessage: error.toString(),
        ),
      );
    }
  }

  Future<void> refresh() => load(state.filter);
}
