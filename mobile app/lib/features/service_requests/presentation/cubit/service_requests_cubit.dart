import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../../../../core/fouad/api_client.dart';
import '../../domain/entities/service_request.dart';
import '../../domain/repositories/service_requests_repository.dart';

class ServiceRequestsState extends Equatable {
  const ServiceRequestsState({this.loading = false, this.requests = const [], this.totalSum = 0, this.status, this.from, this.until, this.error});
  final bool loading;
  final List<ServiceRequest> requests;
  final double totalSum;
  final TankerStatus? status;
  final String? from;
  final String? until;
  final String? error;

  ServiceRequestsState copyWith({bool? loading, List<ServiceRequest>? requests, double? totalSum, TankerStatus? status, bool clearStatus = false, String? from, bool clearFrom = false, String? until, bool clearUntil = false, String? error}) =>
      ServiceRequestsState(
        loading: loading ?? this.loading,
        requests: requests ?? this.requests,
        totalSum: totalSum ?? this.totalSum,
        status: clearStatus ? null : (status ?? this.status),
        from: clearFrom ? null : (from ?? this.from),
        until: clearUntil ? null : (until ?? this.until),
        error: error,
      );
  @override
  List<Object?> get props => [loading, requests, totalSum, status, from, until, error];
}

class ServiceRequestsCubit extends Cubit<ServiceRequestsState> {
  ServiceRequestsCubit(this._repo) : super(const ServiceRequestsState());
  final ServiceRequestsRepository _repo;
  int? subscriberId;

  Future<void> load() async {
    emit(state.copyWith(loading: true));
    try {
      final page = await _repo.getRequests(status: state.status, subscriberId: subscriberId, from: state.from, until: state.until);
      emit(state.copyWith(loading: false, requests: page.requests, totalSum: page.totalSum));
    } catch (e) {
      emit(state.copyWith(loading: false, error: friendlyError(e)));
    }
  }

  void filterStatus(TankerStatus? s) { emit(s == null ? state.copyWith(clearStatus: true) : state.copyWith(status: s)); load(); }
  void filterRange(String? from, String? until) { emit(state.copyWith(from: from, clearFrom: from == null, until: until, clearUntil: until == null)); load(); }

  Future<void> create({required int subscriberId, required double amount, String? notes, TankerStatus status = TankerStatus.completed}) async {
    await _repo.createRequest(
      subscriberId: subscriberId,
      amount: amount,
      serviceDate: DateTime.now().toIso8601String().substring(0, 10),
      status: status,
      notes: notes,
    );
    await load();
  }

  Future<void> updateRequest(ServiceRequest request) async {
    if (!request.canEdit) throw StateError('لا يمكن تعديل طلب مرتبط بفاتورة.');
    await _repo.updateRequest(request);
    await load();
  }

  Future<void> markCompleted(int id) async { await _repo.markCompleted(id); await load(); }

  Future<String> invoice(int id) async {
    final res = await _repo.invoiceRequest(id);
    await load();
    return (res['invoice'] as Map?)?['bill_number']?.toString() ?? '';
  }

  Future<void> delete(int id) async { await _repo.deleteRequest(id); await load(); }
}
