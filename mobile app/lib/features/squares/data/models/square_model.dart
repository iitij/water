import '../../../../core/fouad/json_read.dart';
import '../../domain/entities/square.dart';

class SquareModel extends Square {
  const SquareModel({required super.id, required super.name, super.description, super.subscribersCount});

  factory SquareModel.fromJson(Map<String, dynamic> j) => SquareModel(
        id: jInt(j['id']),
        name: jStr(j['name']) ?? '',
        description: jStr(j['description']),
        subscribersCount: jInt(j['subscribers_count']),
      );

  Map<String, dynamic> toPayload() => {'name': name, 'description': description};
}
