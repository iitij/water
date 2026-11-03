import '../../../../core/fouad/json_read.dart';
import '../../domain/entities/square.dart';

class SquareModel extends Square {
  const SquareModel({
    required super.id,
    required super.name,
    super.description,
    super.code,
    super.subscribersCount,
    super.createdAt,
  });

  factory SquareModel.fromJson(Map<String, dynamic> j) => SquareModel(
    id: jInt(j['id'] ?? j['server_id']),
    createdAt: DateTime.tryParse(jStr(j['created_at']) ?? ''),
    name: jStr(j['name']) ?? '',
    code: jStr(j['code']),
    description: jStr(j['description']),
    subscribersCount: jInt(j['subscribers_count']),
  );

  Map<String, dynamic> toPayload() => {
    'name': name,
    'code': code?.trim().isEmpty ?? true ? null : code!.trim(),
    'description': description,
  };
}
