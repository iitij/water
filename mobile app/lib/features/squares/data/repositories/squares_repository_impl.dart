import '../../../subscribers/domain/entities/subscriber.dart';
import '../../domain/entities/square.dart';
import '../../domain/repositories/squares_repository.dart';
import '../datasources/squares_remote_datasource.dart';
import '../models/square_model.dart';

class SquaresRepositoryImpl implements SquaresRepository {
  SquaresRepositoryImpl(this._remote);
  final SquaresRemoteDatasource _remote;

  @override
  Future<List<Square>> getSquares({String? search}) =>
      _remote.getSquares(search: search);

  @override
  Future<({Square square, List<Subscriber> subscribers})> getSquare(
    int id,
  ) async {
    final r = await _remote.getSquare(id);
    return (
      square: r.square,
      subscribers: List<Subscriber>.from(r.subscribers),
    );
  }

  @override
  Future<Square> createSquare(
    String name,
    String? description, {
    String? code,
  }) => _remote.create(
    SquareModel(
      id: 0,
      name: name,
      description: description,
      code: code,
    ).toPayload(),
  );

  @override
  Future<Square> updateSquare(Square square) => _remote.update(
    square.id,
    square is SquareModel
        ? square.toPayload()
        : SquareModel(
            id: square.id,
            name: square.name,
            description: square.description,
            code: square.code,
          ).toPayload(),
  );

  @override
  Future<void> deleteSquare(int id) => _remote.delete(id);

  @override
  Future<int> bulkDelete(List<int> ids) => _remote.bulkDelete(ids);
}
