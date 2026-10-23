import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../../../../core/fouad/api_client.dart';
import '../../domain/entities/subscription_fee.dart';
import '../../domain/repositories/subscription_fees_repository.dart';

class SubscriptionFeesState extends Equatable {
  const SubscriptionFeesState({this.loading = false, this.fees = const [], this.totalSum = 0, this.invoicedSum = 0, this.remainingSum = 0, this.status, this.error});
  final bool loading;
  final List<SubscriptionFee> fees;
  final double totalSum;
  final double invoicedSum;
  final double remainingSum;
  final FeeStatus? status;
  final String? error;

  SubscriptionFeesState copyWith({bool? loading, List<SubscriptionFee>? fees, double? totalSum, double? invoicedSum, double? remainingSum, FeeStatus? status, bool clearStatus = false, String? error}) =>
      SubscriptionFeesState(
        loading: loading ?? this.loading,
        fees: fees ?? this.fees,
        totalSum: totalSum ?? this.totalSum,
        invoicedSum: invoicedSum ?? this.invoicedSum,
        remainingSum: remainingSum ?? this.remainingSum,
        status: clearStatus ? null : (status ?? this.status),
        error: error,
      );
  @override
  List<Object?> get props => [loading, fees, totalSum, invoicedSum, remainingSum, status, error];
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

  Future<void> create({required int subscriberId, int? meterId, required double amount, String? notes}) async {
    await _repo.createFee(subscriberId: subscriberId, meterId: meterId, amount: amount, notes: notes);
    await load();
  }

  Future<String> invoice(int feeId) async {
    final res = await _repo.invoiceFee(feeId);
    await load();
    return (res['invoice'] as Map?)?['bill_number']?.toString() ?? '';
  }

  Future<void> delete(int id) async { await _repo.deleteFee(id); await load(); }
}
