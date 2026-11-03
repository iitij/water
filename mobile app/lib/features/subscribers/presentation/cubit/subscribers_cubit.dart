import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/fouad/api_client.dart';
import '../../../../core/utils/compound_search_parser.dart';
import '../../../squares/domain/entities/square.dart';
import '../../domain/entities/subscriber.dart';
import '../../domain/repositories/subscribers_repository.dart';

class SubscribersState extends Equatable {
  const SubscribersState({
    this.loading = false,
    this.subscribers = const [],
    this.squares = const [],
    this.query = '',
    this.squareId,
    this.status,
    this.pathNumber,
    this.selected,
    this.selectedIds = const {},
    this.error,
  });
  final bool loading;
  final List<Subscriber> subscribers;
  final List<Square> squares;
  final String query;
  final int? squareId;
  final SubscriberStatus? status;
  final int? pathNumber;
  final Subscriber? selected;
  final Set<int> selectedIds;
  final String? error;

  SubscribersState copyWith({
    bool? loading,
    List<Subscriber>? subscribers,
    List<Square>? squares,
    String? query,
    int? squareId,
    bool clearSquare = false,
    SubscriberStatus? status,
    bool clearStatus = false,
    int? pathNumber,
    bool clearPath = false,
    Subscriber? selected,
    bool clearSelected = false,
    Set<int>? selectedIds,
    String? error,
  }) => SubscribersState(
    loading: loading ?? this.loading,
    subscribers: subscribers ?? this.subscribers,
    squares: squares ?? this.squares,
    query: query ?? this.query,
    squareId: clearSquare ? null : (squareId ?? this.squareId),
    status: clearStatus ? null : (status ?? this.status),
    pathNumber: clearPath ? null : (pathNumber ?? this.pathNumber),
    selected: clearSelected ? null : (selected ?? this.selected),
    selectedIds: selectedIds ?? this.selectedIds,
    error: error,
  );
  @override
  List<Object?> get props => [
    loading,
    subscribers,
    squares,
    query,
    squareId,
    status,
    pathNumber,
    selected,
    selectedIds,
    error,
  ];
}

class SubscribersCubit extends Cubit<SubscribersState> {
  SubscribersCubit(this._repo, {SubscriberStatus? initialStatus})
      : super(SubscribersState(status: initialStatus));
  final SubscribersRepository _repo;
  int _loadVersion = 0;
  Set<int>? _visibleIds;
  void setVisibleSubscribers(List<Subscriber> rows) {
    _visibleIds = rows.map((row) => row.id).toSet();
  }

  Future<void> init() async {
    emit(state.copyWith(loading: true));
    try {
      final squares = await _repo.getSquaresLookup();
      if (isClosed) return;
      emit(state.copyWith(squares: squares));
      await load();
    } catch (e) {
      if (isClosed) return;
      emit(state.copyWith(loading: false, error: friendlyError(e)));
    }
  }

  Future<void> load() async {
    if (isClosed) return;
    final version = ++_loadVersion;
    final filters = state;
    emit(state.copyWith(loading: true));
    try {
      final list = await _repo.getSubscribers(
        query: CompoundSearchQuery.parse(filters.query).rawQuery,
        squareId: filters.squareId,
        status: filters.status,
      );
      if (isClosed || version != _loadVersion) return;
      emit(
        state.copyWith(
          loading: false,
          subscribers: filters.pathNumber == null
              ? list
              : list
                    .where(
                      (subscriber) =>
                          subscriber.pathNumbers.contains(filters.pathNumber),
                    )
                    .toList(),
        ),
      );
    } catch (e) {
      if (isClosed || version != _loadVersion) return;
      emit(state.copyWith(loading: false, error: friendlyError(e)));
    }
  }

  void search(String q) {
    emit(state.copyWith(query: q));
    load();
  }

  void filterSquare(int? id) {
    emit(
      id == null
          ? state.copyWith(clearSquare: true)
          : state.copyWith(squareId: id),
    );
    load();
  }

  void filterStatus(SubscriberStatus? s) {
    emit(
      s == null ? state.copyWith(clearStatus: true) : state.copyWith(status: s),
    );
    load();
  }

  void applyFilters({
    int? squareId,
    SubscriberStatus? status,
    int? pathNumber,
  }) {
    emit(
      state.copyWith(
        squareId: squareId,
        clearSquare: squareId == null,
        status: status,
        clearStatus: status == null,
        pathNumber: pathNumber,
        clearPath: pathNumber == null,
      ),
    );
    load();
  }

  void clearFilters({bool clearQuery = false}) {
    emit(
      state.copyWith(
        clearSquare: true,
        clearStatus: true,
        clearPath: true,
        query: clearQuery ? '' : null,
      ),
    );
    load();
  }

  void select(Subscriber s) => emit(state.copyWith(selected: s));

  void toggleSelect(int id) {
    final ids = {...state.selectedIds};
    ids.contains(id) ? ids.remove(id) : ids.add(id);
    emit(state.copyWith(selectedIds: ids));
  }

  void selectAllVisible() {
    if (state.loading) return;
    emit(
      state.copyWith(
        selectedIds: _visibleIds ?? state.subscribers.map((s) => s.id).toSet(),
      ),
    );
  }

  void clearSelection() {
    emit(state.copyWith(selectedIds: const {}));
  }

  Future<bool> deleteSubscriber(int id) async {
    try {
      await _repo.deleteSubscriber(id);
      final ids = {...state.selectedIds}..remove(id);
      emit(
        state.copyWith(
          selectedIds: ids,
          clearSelected: state.selected?.id == id,
        ),
      );
      await load();
      return true;
    } catch (e) {
      emit(state.copyWith(error: friendlyError(e)));
      return false;
    }
  }

  Future<int> bulkDelete() async {
    if (state.selectedIds.isEmpty) return 0;
    try {
      final deleted = await _repo.bulkDelete(state.selectedIds.toList());
      emit(
        state.copyWith(
          selectedIds: const {},
          clearSelected:
              state.selected != null &&
              state.selectedIds.contains(state.selected!.id),
        ),
      );
      await load();
      return deleted;
    } catch (e) {
      emit(state.copyWith(error: friendlyError(e)));
      return 0;
    }
  }

  /// زر "طلب وايت" السريع: السعر الافتراضي من آخر طلب للمشترك أو 5000 (SCR-05).
  Future<double> quickTankerDefaultPrice(int subscriberId) async =>
      await _repo.lastTankerAmount(subscriberId) ?? 5000;

  Future<String> collectPayment(
    int subscriberId,
    double amount, {
    String? reason,
  }) async {
    final res = await _repo.collectPayment(
      subscriberId,
      amount: amount,
      reason: reason,
    );
    await load();
    return (res['payment'] as Map?)?['receipt_number']?.toString() ?? '';
  }
}
