import 'package:equatable/equatable.dart';
import '../../../../core/models/square_label.dart';

class Square extends Equatable {
  const Square({
    required this.id,
    required this.name,
    this.description,
    this.code,
    this.subscribersCount = 0,
    this.createdAt,
  });
  final int id;
  final String name;
  final String? code;
  String get displayName => squareDisplayName(name, code);
  final String? description;
  final int subscribersCount;
  final DateTime? createdAt;
  @override
  List<Object?> get props => [id, name, code, description, subscribersCount];
}
