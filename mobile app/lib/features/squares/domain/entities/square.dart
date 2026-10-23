import 'package:equatable/equatable.dart';

class Square extends Equatable {
  const Square({required this.id, required this.name, this.description, this.subscribersCount = 0});
  final int id;
  final String name;
  final String? description;
  final int subscribersCount;
  @override
  List<Object?> get props => [id, name, description, subscribersCount];
}
