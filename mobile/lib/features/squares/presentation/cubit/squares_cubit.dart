import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../../../../core/network/api_client.dart';
import '../../../subscribers/domain/entities/subscriber.dart';
import '../../domain/entities/square.dart';
import '../../domain/repositories/squares_repository.dart';

class SquaresState extends Equatable {
  const SquaresState({this.loading = false, this.squares = const [], this.selected, this.selectedSubscribers = const [], this.error, this.selectedIds = const {}});
  final bool loading;
  final List<Square> squares;
  final Square? selected;
  final List<Subscriber> selectedSubscribers;
  final String? error;
  final Set<int> selectedIds;

  SquaresState copyWith({bool? loading, List<Square>? squares, Square? selected, List<Subscriber>? selectedSubscribers, String? error, Set<int>? selectedIds, bool clearSelection = false}) =>
      SquaresState(
        loading: loading ?? this.loading,
        squares: squares ?? this.squares,
        selected: clearSelection ? null : (selected ?? this.selected),
        selectedSubscribers: clearSelection ? const [] : (selectedSubscribers ?? this.selectedSubscribers),
        error: error,
        selectedIds: selectedIds ?? this.selectedIds,
      );
  @override
  List<Object?> get props => [loading, squares, selected, selectedSubscribers, error, selectedIds];
}

class SquaresCubit extends Cubit<SquaresState> {
  SquaresCubit(this._repo) : super(const SquaresState());
  final SquaresRepository _repo;
  String _search = '';

  Future<void> load({String? search}) async {
    _search = search ?? _search;
    emit(state.copyWith(loading: true));
    try {
      emit(state.copyWith(loading: false, squares: await _repo.getSquares(search: _search)));
    } catch (e) {
      emit(state.copyWith(loading: false, error: friendlyError(e)));
    }
  }

  Future<void> select(Square square) async {
    emit(state.copyWith(loading: true));
    try {
      final r = await _repo.getSquare(square.id);
      emit(state.copyWith(loading: false, selected: r.square, selectedSubscribers: r.subscribers));
    } catch (e) {
      emit(state.copyWith(loading: false, error: friendlyError(e)));
    }
  }

  Future<void> save({int? id, required String name, String? description}) async {
    try {
      if (id == null) {
        await _repo.createSquare(name, description);
      } else {
        await _repo.updateSquare(Square(id: id, name: name, description: description));
      }
      await load();
    } catch (e) {
      emit(state.copyWith(error: friendlyError(e)));
    }
  }

  Future<void> delete(int id) async {
    try {
      await _repo.deleteSquare(id);
      emit(state.copyWith(clearSelection: state.selected?.id == id));
      await load();
    } catch (e) {
      emit(state.copyWith(error: friendlyError(e)));
    }
  }

  void toggleSelect(int id) {
    final ids = {...state.selectedIds};
    ids.contains(id) ? ids.remove(id) : ids.add(id);
    emit(state.copyWith(selectedIds: ids));
  }

  Future<void> bulkDelete() async {
    try {
      await _repo.bulkDelete(state.selectedIds.toList());
      emit(state.copyWith(selectedIds: {}));
      await load();
    } catch (e) {
      emit(state.copyWith(error: friendlyError(e)));
    }
  }
}
