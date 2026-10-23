import '../../../subscribers/domain/entities/subscriber.dart';
import '../../domain/entities/square.dart';
import '../../domain/repositories/squares_repository.dart';
import '../datasources/squares_remote_datasource.dart';
import '../models/square_model.dart';

class SquaresRepositoryImpl implements SquaresRepository {
  SquaresRepositoryImpl(this._remote);
  final SquaresRemoteDatasource _remote;

  @override
  Future<List<Square>> getSquares({String? search}) => _remote.getSquares(search: search);

  @override
  Future<({Square square, List<Subscriber> subscribers})> getSquare(int id) async {
    final r = await _remote.getSquare(id);
    return (square: r.square, subscribers: List<Subscriber>.from(r.subscribers));
  }

  @override
  Future<Square> createSquare(String name, String? description) =>
      _remote.create({'name': name, 'description': description});

  @override
  Future<Square> updateSquare(Square square) =>
      _remote.update(square.id, (square as SquareModel).toPayload());

  @override
  Future<void> deleteSquare(int id) => _remote.delete(id);

  @override
  Future<int> bulkDelete(List<int> ids) => _remote.bulkDelete(ids);
}
