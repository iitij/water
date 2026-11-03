import '../../../subscribers/domain/entities/subscriber.dart';
import '../entities/square.dart';

abstract class SquaresRepository {
  Future<List<Square>> getSquares({String? search});
  Future<({Square square, List<Subscriber> subscribers})> getSquare(int id);
  Future<Square> createSquare(String name, String? description, {String? code});
  Future<Square> updateSquare(Square square);
  Future<void> deleteSquare(int id);
  Future<int> bulkDelete(List<int> ids);
}
