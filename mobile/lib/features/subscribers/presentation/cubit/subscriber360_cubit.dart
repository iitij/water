import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../../../../core/network/api_client.dart';
import '../../domain/entities/subscriber.dart';
import '../../domain/repositories/subscribers_repository.dart';

class Subscriber360State extends Equatable {
  const Subscriber360State({
    this.loading = false,
    this.detail,
    this.statement = const [],
    this.party = const PartyAll(),
    this.statementLoading = false,
    this.error,
  });
  final bool loading;
  final Subscriber360? detail;
  final List<LedgerEntry> statement;
  final PartyFilter party;
  final bool statementLoading;
  final String? error;

  Subscriber360State copyWith({bool? loading, Subscriber360? detail, List<LedgerEntry>? statement, PartyFilter? party, bool? statementLoading, String? error}) =>
      Subscriber360State(
        loading: loading ?? this.loading,
        detail: detail ?? this.detail,
        statement: statement ?? this.statement,
        party: party ?? this.party,
        statementLoading: statementLoading ?? this.statementLoading,
        error: error,
      );
  @override
  List<Object?> get props => [loading, detail, statement, party, statementLoading, error];
}

/// كيوبت ملف 360°: الملخص المالي المعزول + فلترة كشف الحساب بالجهة.
class Subscriber360Cubit extends Cubit<Subscriber360State> {
  Subscriber360Cubit(this._repo) : super(const Subscriber360State());
  final SubscribersRepository _repo;

  Future<void> load(int subscriberId) async {
    emit(state.copyWith(loading: true));
    try {
      final detail = await _repo.getSubscriber360(subscriberId);
      emit(state.copyWith(loading: false, detail: detail));
      await loadStatement();
    } catch (e) {
      emit(state.copyWith(loading: false, error: friendlyError(e)));
    }
  }

  /// فلتر الجهة: الكل / الحساب العام / عداد محدد (مطابق SubscriberTransactionsTable.php).
  Future<void> filterParty(PartyFilter party) async {
    emit(state.copyWith(party: party));
    await loadStatement();
  }

  Future<void> loadStatement() async {
    final id = state.detail?.subscriber.id;
    if (id == null) return;
    emit(state.copyWith(statementLoading: true));
    try {
      final entries = await _repo.getStatement(id, party: state.party);
      emit(state.copyWith(statementLoading: false, statement: entries));
    } catch (e) {
      emit(state.copyWith(statementLoading: false, error: friendlyError(e)));
    }
  }
}
