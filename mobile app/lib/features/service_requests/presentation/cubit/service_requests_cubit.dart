import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../../../../core/fouad/api_client.dart';
import '../../domain/entities/service_request.dart';
import '../../domain/repositories/service_requests_repository.dart';

class ServiceRequestsState extends Equatable {
  const ServiceRequestsState({
    this.loading = false,
    this.requests = const [],
    this.totalSum = 0,
    this.status,
    this.from,
    this.until,
    this.selectedIds = const {},
    this.error,
  });
  final bool loading;
  final List<ServiceRequest> requests;
  final double totalSum;
  final TankerStatus? status;
  final String? from;
  final String? until;
  final Set<int> selectedIds;
  final String? error;

  ServiceRequestsState copyWith({
    bool? loading,
    List<ServiceRequest>? requests,
    double? totalSum,
    TankerStatus? status,
    bool clearStatus = false,
    String? from,
    bool clearFrom = false,
    String? until,
    bool clearUntil = false,
    Set<int>? selectedIds,
    String? error,
  }) => ServiceRequestsState(
    loading: loading ?? this.loading,
    requests: requests ?? this.requests,
    totalSum: totalSum ?? this.totalSum,
    status: clearStatus ? null : (status ?? this.status),
    from: clearFrom ? null : (from ?? this.from),
    until: clearUntil ? null : (until ?? this.until),
    selectedIds: selectedIds ?? this.selectedIds,
    error: error,
  );
  @override
  List<Object?> get props => [
    loading,
    requests,
    totalSum,
    status,
    from,
    until,
    selectedIds,
    error,
  ];
}

class ServiceRequestsCubit extends Cubit<ServiceRequestsState> {
  ServiceRequestsCubit(this._repo) : super(const ServiceRequestsState());
  final ServiceRequestsRepository _repo;
  int? subscriberId;

  Future<void> load() async {
    emit(state.copyWith(loading: true));
    try {
      final page = await _repo.getRequests(
        status: state.status,
        subscriberId: subscriberId,
        from: state.from,
        until: state.until,
      );
      emit(
        state.copyWith(
          loading: false,
          requests: page.requests,
          totalSum: page.totalSum,
        ),
      );
    } catch (e) {
      emit(state.copyWith(loading: false, error: friendlyError(e)));
    }
  }

  void filterStatus(TankerStatus? s) {
    emit(
      s == null ? state.copyWith(clearStatus: true) : state.copyWith(status: s),
    );
    load();
  }

  void filterRange(String? from, String? until) {
    emit(
      state.copyWith(
        from: from,
        clearFrom: from == null,
        until: until,
        clearUntil: until == null,
      ),
    );
    load();
  }

  void toggleSelect(int id) {
    final ids = {...state.selectedIds};
    ids.contains(id) ? ids.remove(id) : ids.add(id);
    emit(state.copyWith(selectedIds: ids));
  }

  void clearSelection() => emit(state.copyWith(selectedIds: const {}));

  Future<bool> create({
    required int subscriberId,
    required double amount,
    String? serviceDate,
    String? notes,
    TankerStatus status = TankerStatus.completed,
  }) async {
    try {
      await _repo.createRequest(
        subscriberId: subscriberId,
        amount: amount,
        serviceDate:
            serviceDate ?? DateTime.now().toIso8601String().substring(0, 10),
        status: status,
        notes: notes,
      );
    } catch (e) {
      if (!isClosed) emit(state.copyWith(error: friendlyError(e)));
      return false;
    }
    // The request exists even if refreshing the list cannot reach the server.
    if (!isClosed) await load();
    return true;
  }

  Future<void> updateRequest(ServiceRequest request) async {
    if (!request.canEdit) throw StateError('لا يمكن تعديل طلب مرتبط بفاتورة.');
    await _repo.updateRequest(request);
    await load();
  }

  Future<void> update(ServiceRequest request) async {
    try {
      await updateRequest(request);
    } catch (e) {
      emit(state.copyWith(error: friendlyError(e)));
    }
  }

  Future<void> markCompleted(int id) async {
    await _repo.markCompleted(id);
    await load();
  }

  Future<String> invoice(int id) async {
    final res = await _repo.invoiceRequest(id);
    await load();
    return (res['invoice'] as Map?)?['bill_number']?.toString() ?? '';
  }

  Future<void> delete(int id) async {
    try {
      await _repo.deleteRequest(id);
      final ids = {...state.selectedIds}..remove(id);
      emit(state.copyWith(selectedIds: ids));
      await load();
    } catch (e) {
      emit(state.copyWith(error: friendlyError(e)));
    }
  }

  Future<int> bulkDelete() async {
    if (state.selectedIds.isEmpty) return 0;
    try {
      final deleted = await _repo.bulkDelete(state.selectedIds.toList());
      emit(state.copyWith(selectedIds: const {}));
      await load();
      return deleted;
    } catch (e) {
      emit(state.copyWith(error: friendlyError(e)));
      return 0;
    }
  }
}
