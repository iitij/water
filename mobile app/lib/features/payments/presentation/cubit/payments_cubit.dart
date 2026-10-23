import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/error/failures.dart';
import '../../../invoices/domain/accounting/finance_enums.dart';
import '../../data/models/payment_model.dart';
import '../../domain/repositories/payments_repository.dart';

enum PaymentsLoadStatus { idle, loading, ready, refreshing, failure }

class PaymentsState {
  const PaymentsState({
    this.query = const PaymentQuery(),
    this.payments = const <PaymentModel>[],
    this.status = PaymentsLoadStatus.idle,
    this.errorMessage,
    this.busyId,
    this.selectedIds = const <int>{},
  });

  final PaymentQuery query;
  final List<PaymentModel> payments;
  final PaymentsLoadStatus status;
  final String? errorMessage;
  final int? busyId;
  final Set<int> selectedIds;

  bool get isLoading =>
      status == PaymentsLoadStatus.loading || status == PaymentsLoadStatus.refreshing;
  bool get isEmpty => !isLoading && payments.isEmpty && errorMessage == null;

  double get totalSum => payments.fold<double>(0, (sum, p) => sum + p.amount);

  double get cashSum => payments
      .where((p) => p.method == PaymentMethod.cash)
      .fold<double>(0, (sum, p) => sum + p.amount);

  PaymentsState copyWith({
    PaymentQuery? query,
    List<PaymentModel>? payments,
    PaymentsLoadStatus? status,
    String? errorMessage,
    bool clearError = false,
    int? busyId,
    Set<int>? selectedIds,
  }) =>
      PaymentsState(
        query: query ?? this.query,
        payments: payments ?? this.payments,
        status: status ?? this.status,
        errorMessage: clearError ? null : (errorMessage ?? this.errorMessage),
        busyId: busyId ?? this.busyId,
        selectedIds: selectedIds ?? this.selectedIds,
      );
}

/// `PaymentsCubit` — سجل سندات القبض (`SCR-17`) مع الفلترة والتعديل والحذف.
class PaymentsCubit extends Cubit<PaymentsState> {
  PaymentsCubit({required PaymentsRepository repository, PaymentQuery? initialQuery})
      : _repository = repository,
        super(PaymentsState(query: initialQuery ?? const PaymentQuery()));

  final PaymentsRepository _repository;
  String? _lastSignature;

  Future<void> load({bool refresh = false}) async {
    final signature = state.query.signature;
    if (!refresh && signature == _lastSignature && state.payments.isNotEmpty) return;

    emit(state.copyWith(
      status: state.payments.isEmpty ? PaymentsLoadStatus.loading : PaymentsLoadStatus.refreshing,
      clearError: true,
    ));

    try {
      final payments = await _repository.list(state.query);
      _lastSignature = signature;
      emit(state.copyWith(payments: payments, status: PaymentsLoadStatus.ready));
    } on AppFailure catch (failure) {
      emit(state.copyWith(status: PaymentsLoadStatus.failure, errorMessage: failure.message));
    } catch (error) {
      emit(state.copyWith(status: PaymentsLoadStatus.failure, errorMessage: '$error'));
    }
  }

  Future<void> search(String value) async {
    emit(state.copyWith(query: state.query.copyWith(search: value)));
    await load(refresh: true);
  }

  Future<void> applyFilters(PaymentQuery query) async {
    emit(state.copyWith(query: query));
    await load(refresh: true);
  }

  Future<bool> deletePayment(int id) async {
    emit(state.copyWith(busyId: id, clearError: true));
    try {
      await _repository.delete(id);
      emit(state.copyWith(
        payments: state.payments.where((p) => p.id != id).toList(),
        selectedIds: state.selectedIds.where((s) => s != id).toSet(),
        busyId: null,
      ));
      return true;
    } on AppFailure catch (failure) {
      emit(state.copyWith(busyId: null, errorMessage: failure.message));
      return false;
    }
  }

  Future<int> bulkDelete() async {
    if (state.selectedIds.isEmpty) return 0;
    try {
      final count = await _repository.bulkDelete(state.selectedIds.toList());
      await load(refresh: true);
      emit(state.copyWith(selectedIds: const <int>{}));
      return count;
    } on AppFailure catch (failure) {
      emit(state.copyWith(errorMessage: failure.message));
      return 0;
    }
  }

  void toggleSelection(int id) {
    final selected = state.selectedIds.toSet();
    if (!selected.remove(id)) selected.add(id);
    emit(state.copyWith(selectedIds: selected));
  }

  void selectAll() {
    emit(state.copyWith(selectedIds: state.payments.map((p) => p.id).toSet()));
  }

  void clearSelection() {
    emit(state.copyWith(selectedIds: const <int>{}));
  }

  /// يعيد تحميل سند واحد بعد التعديل/الدفع ودمجه في القائمة.
  Future<void> upsert(PaymentModel payment) async {
    final exists = state.payments.any((p) => p.id == payment.id);
    emit(state.copyWith(
      payments: exists
          ? state.payments.map((p) => p.id == payment.id ? payment : p).toList()
          : <PaymentModel>[payment, ...state.payments],
    ));
  }
}
