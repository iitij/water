import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../../../../core/network/api_client.dart';
import '../../domain/entities/ledger_transaction.dart';
import '../../domain/repositories/transactions_repository.dart';

class TransactionsState extends Equatable {
  const TransactionsState({this.loading = false, this.transactions = const [], this.search = '', this.type, this.subscriberId, this.meterId, this.error});
  final bool loading;
  final List<LedgerTransaction> transactions;
  final String search;
  final String? type; // payment|invoice|adjustment
  final int? subscriberId;
  final String? meterId; // 'general' أو id
  final String? error;

  double get totalCredit => transactions.where((t) => t.isCredit).fold(0, (a, t) => a + t.amount);
  double get totalDebit => transactions.where((t) => !t.isCredit).fold(0, (a, t) => a + t.amount.abs());

  TransactionsState copyWith({bool? loading, List<LedgerTransaction>? transactions, String? search, String? type, bool clearType = false, int? subscriberId, bool clearSubscriber = false, String? meterId, bool clearMeter = false, String? error}) =>
      TransactionsState(
        loading: loading ?? this.loading,
        transactions: transactions ?? this.transactions,
        search: search ?? this.search,
        type: clearType ? null : (type ?? this.type),
        subscriberId: clearSubscriber ? null : (subscriberId ?? this.subscriberId),
        meterId: clearMeter ? null : (meterId ?? this.meterId),
        error: error,
      );
  @override
  List<Object?> get props => [loading, transactions, search, type, subscriberId, meterId, error];
}

/// دفتر الأستاذ العام (SCR-22) — قراءة فقط، بلا أي عمليات كتابة.
class TransactionsCubit extends Cubit<TransactionsState> {
  TransactionsCubit(this._repo) : super(const TransactionsState());
  final TransactionsRepository _repo;

  Future<void> load() async {
    emit(state.copyWith(loading: true));
    try {
      final list = await _repo.getTransactions(search: state.search, subscriberId: state.subscriberId, meterId: state.meterId, type: state.type);
      emit(state.copyWith(loading: false, transactions: list));
    } catch (e) {
      emit(state.copyWith(loading: false, error: friendlyError(e)));
    }
  }

  void search(String q) { emit(state.copyWith(search: q)); load(); }
  void filterType(String? t) { emit(t == null ? state.copyWith(clearType: true) : state.copyWith(type: t)); load(); }
  void filterSubscriber(int? id) { emit(id == null ? state.copyWith(clearSubscriber: true) : state.copyWith(subscriberId: id)); load(); }
  void filterMeter(String? m) { emit(m == null ? state.copyWith(clearMeter: true) : state.copyWith(meterId: m)); load(); }
}
