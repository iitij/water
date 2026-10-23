import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../../../../core/network/api_client.dart';
import '../../../squares/domain/entities/square.dart';
import '../../domain/entities/subscriber.dart';
import '../../domain/repositories/subscribers_repository.dart';

class SubscribersState extends Equatable {
  const SubscribersState({this.loading = false, this.subscribers = const [], this.squares = const [], this.query = '', this.squareId, this.status, this.selected, this.error});
  final bool loading;
  final List<Subscriber> subscribers;
  final List<Square> squares;
  final String query;
  final int? squareId;
  final SubscriberStatus? status;
  final Subscriber? selected;
  final String? error;

  SubscribersState copyWith({bool? loading, List<Subscriber>? subscribers, List<Square>? squares, String? query, int? squareId, bool clearSquare = false, SubscriberStatus? status, bool clearStatus = false, Subscriber? selected, bool clearSelected = false, String? error}) =>
      SubscribersState(
        loading: loading ?? this.loading,
        subscribers: subscribers ?? this.subscribers,
        squares: squares ?? this.squares,
        query: query ?? this.query,
        squareId: clearSquare ? null : (squareId ?? this.squareId),
        status: clearStatus ? null : (status ?? this.status),
        selected: clearSelected ? null : (selected ?? this.selected),
        error: error,
      );
  @override
  List<Object?> get props => [loading, subscribers, squares, query, squareId, status, selected, error];
}

class SubscribersCubit extends Cubit<SubscribersState> {
  SubscribersCubit(this._repo) : super(const SubscribersState());
  final SubscribersRepository _repo;

  Future<void> init() async {
    emit(state.copyWith(loading: true));
    try {
      final squares = await _repo.getSquaresLookup();
      emit(state.copyWith(squares: squares));
      await load();
    } catch (e) {
      emit(state.copyWith(loading: false, error: friendlyError(e)));
    }
  }

  Future<void> load() async {
    emit(state.copyWith(loading: true));
    try {
      final list = await _repo.getSubscribers(query: state.query, squareId: state.squareId, status: state.status);
      emit(state.copyWith(loading: false, subscribers: list));
    } catch (e) {
      emit(state.copyWith(loading: false, error: friendlyError(e)));
    }
  }

  void search(String q) { emit(state.copyWith(query: q)); load(); }
  void filterSquare(int? id) { emit(id == null ? state.copyWith(clearSquare: true) : state.copyWith(squareId: id)); load(); }
  void filterStatus(SubscriberStatus? s) { emit(s == null ? state.copyWith(clearStatus: true) : state.copyWith(status: s)); load(); }
  void select(Subscriber s) => emit(state.copyWith(selected: s));

  /// زر "طلب وايت" السريع: السعر الافتراضي من آخر طلب للمشترك أو 5000 (SCR-05).
  Future<double> quickTankerDefaultPrice(int subscriberId) async =>
      await _repo.lastTankerAmount(subscriberId) ?? 5000;

  Future<String> collectPayment(int subscriberId, double amount, {String? reason}) async {
    final res = await _repo.collectPayment(subscriberId, amount: amount, reason: reason);
    await load();
    return (res['payment'] as Map?)?['receipt_number']?.toString() ?? '';
  }
}
