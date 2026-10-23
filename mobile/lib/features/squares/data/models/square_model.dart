import '../../domain/entities/square.dart';

class SquareModel extends Square {
  const SquareModel({required super.id, required super.name, super.description, super.subscribersCount});

  factory SquareModel.fromJson(Map<String, dynamic> j) => SquareModel(
        id: j['id'] as int,
        name: j['name'] as String,
        description: j['description'] as String?,
        subscribersCount: (j['subscribers_count'] as num?)?.toInt() ?? 0,
      );

  Map<String, dynamic> toPayload() => {'name': name, 'description': description};
}
