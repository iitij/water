import 'package:equatable/equatable.dart';

class Square extends Equatable {
  const Square({
    required this.id,
    required this.name,
    this.description,
    this.subscribersCount = 0,
    this.createdAt,
  });
  final int id;
  final String name;
  final String? description;
  final int subscribersCount;
  final DateTime? createdAt;
  @override
  List<Object?> get props => [id, name, description, subscribersCount];
}
