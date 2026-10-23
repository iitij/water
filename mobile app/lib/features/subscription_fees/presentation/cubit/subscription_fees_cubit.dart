import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../../../../core/fouad/api_client.dart';
import '../../domain/entities/subscription_fee.dart';
import '../../domain/repositories/subscription_fees_repository.dart';

class SubscriptionFeesState extends Equatable {
  const SubscriptionFeesState({
    this.loading = false,
    this.fees = const [],
    this.totalSum = 0,
    this.invoicedSum = 0,
    this.remainingSum = 0,
    this.status,
    this.selectedIds = const {},
    this.error,
  });
  final bool loading;
  final List<SubscriptionFee> fees;
  final double totalSum;
  final double invoicedSum;
  final double remainingSum;
  final FeeStatus? status;
  final Set<int> selectedIds;
  final String? error;

  SubscriptionFeesState copyWith({
    bool? loading,
    List<SubscriptionFee>? fees,
    double? totalSum,
    double? invoicedSum,
    double? remainingSum,
    FeeStatus? status,
    bool clearStatus = false,
    Set<int>? selectedIds,
    String? error,
  }) =>
      SubscriptionFeesState(
        loading: loading ?? this.loading,
        fees: fees ?? this.fees,
        totalSum: totalSum ?? this.totalSum,
        invoicedSum: invoicedSum ?? this.invoicedSum,
        remainingSum: remainingSum ?? this.remainingSum,
        status: clearStatus ? null : (status ?? this.status),
        selectedIds: selectedIds ?? this.selectedIds,
        error: error,
      );
  @override
  List<Object?> get props => [loading, fees, totalSum, invoicedSum, remainingSum, status, selectedIds, error];
}

class SubscriptionFeesCubit extends Cubit<SubscriptionFeesState> {
  SubscriptionFeesCubit(this._repo) : super(const SubscriptionFeesState());
  final SubscriptionFeesRepository _repo;
  int? subscriberId; // عند العمل داخل تبويب ملف 360°

  Future<void> load() async {
    emit(state.copyWith(loading: true));
    try {
      final page = await _repo.getFees(status: state.status, subscriberId: subscriberId);
      emit(state.copyWith(loading: false, fees: page.fees, totalSum: page.totalSum, invoicedSum: page.invoicedSum, remainingSum: page.remainingSum));
    } catch (e) {
      emit(state.copyWith(loading: false, error: friendlyError(e)));
    }
  }

  void filterStatus(FeeStatus? s) { emit(s == null ? state.copyWith(clearStatus: true) : state.copyWith(status: s)); load(); }

  void toggleSelect(int id) {
    final ids = {...state.selectedIds};
    ids.contains(id) ? ids.remove(id) : ids.add(id);
    emit(state.copyWith(selectedIds: ids));
  }

  void clearSelection() => emit(state.copyWith(selectedIds: const {}));

  Future<void> create({required int subscriberId, int? meterId, required double amount, FeeStatus status = FeeStatus.uninvoiced, String? notes}) async {
    try {
      await _repo.createFee(subscriberId: subscriberId, meterId: meterId, amount: amount, status: status, notes: notes);
      await load();
    } catch (e) {
      emit(state.copyWith(error: friendlyError(e)));
    }
  }

  Future<void> update(SubscriptionFee fee) async {
    try {
      await _repo.updateFee(fee);
      await load();
    } catch (e) {
      emit(state.copyWith(error: friendlyError(e)));
    }
  }

  Future<String> invoice(int feeId) async {
    final res = await _repo.invoiceFee(feeId);
    await load();
    return (res['invoice'] as Map?)?['bill_number']?.toString() ?? '';
  }

  Future<void> delete(int id) async {
    try {
      await _repo.deleteFee(id);
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
