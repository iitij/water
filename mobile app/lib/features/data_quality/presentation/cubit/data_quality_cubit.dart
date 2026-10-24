import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../data/data_quality_remote_data_source.dart';
import '../../domain/data_quality_issue.dart';

enum DataQualityStatus { initial, loading, success, failure }

class DataQualityState extends Equatable {
  const DataQualityState({
    this.status = DataQualityStatus.initial,
    this.report,
    this.errorMessage,
    this.staleDays = 45,
  });

  final DataQualityStatus status;
  final DataQualityReport? report;
  final String? errorMessage;

  /// عتبة «قراءة قديمة» — يعدّلها المشرف من الشاشة.
  final int staleDays;

  List<DataQualityIssue> get problems => report?.problems ?? const <DataQualityIssue>[];
  int get totalProblems => report?.totalProblems ?? 0;
  bool get isClean => report?.isClean ?? false;

  DataQualityState copyWith({
    DataQualityStatus? status,
    DataQualityReport? report,
    String? errorMessage,
    int? staleDays,
    bool clearError = false,
  }) =>
      DataQualityState(
        status: status ?? this.status,
        report: report ?? this.report,
        errorMessage: clearError ? null : errorMessage ?? this.errorMessage,
        staleDays: staleDays ?? this.staleDays,
      );

  @override
  List<Object?> get props => <Object?>[status, report, errorMessage, staleDays];
}

/// تقرير جودة البيانات (S7-10).
class DataQualityCubit extends Cubit<DataQualityState> {
  DataQualityCubit(this._remote) : super(const DataQualityState());

  final DataQualityRemoteDataSource _remote;

  Future<void> load({int? staleDays}) async {
    final days = staleDays ?? state.staleDays;
    emit(state.copyWith(status: DataQualityStatus.loading, staleDays: days, clearError: true));
    try {
      final report = await _remote.getReport(staleDays: days);
      emit(state.copyWith(status: DataQualityStatus.success, report: report));
    } catch (error) {
      emit(state.copyWith(
        status: DataQualityStatus.failure,
        errorMessage: _messageFor(error),
      ));
    }
  }

  String _messageFor(Object error) {
    final text = error.toString();
    if (text.contains('SocketException') || text.contains('connectionError')) {
      return 'لا يوجد اتصال — تقرير جودة البيانات يحتاج الشبكة.';
    }
    if (text.contains('403')) return 'لا تملك صلاحية عرض المشتركين المطلوبة لهذا التقرير.';
    return 'تعذّر تحميل التقرير: $text';
  }
}
