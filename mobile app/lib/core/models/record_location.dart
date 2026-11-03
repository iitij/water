import 'package:equatable/equatable.dart';
import 'square_label.dart';

/// Location of the linked meter, or all subscriber routes for general records.
class RecordLocation extends Equatable {
  const RecordLocation({
    this.squareId,
    this.squareName,
    this.squareCode,
    this.paths = const [],
  });
  final int? squareId;
  final String? squareName;
  final String? squareCode;
  String? get squareLabel => squareLabelOrNull(squareName, squareCode);
  final List<int> paths;
  @override
  List<Object?> get props => [squareId, squareName, squareCode, paths];

  int? get firstPath =>
      paths.isEmpty ? null : paths.reduce((a, b) => a < b ? a : b);

  factory RecordLocation.fromJson(Map<String, dynamic> json) {
    Map<dynamic, dynamic> map(dynamic value) => value is Map ? value : const {};
    int? number(dynamic value) => int.tryParse(value?.toString() ?? '');
    final meter = map(json['meter']);
    final subscriber = map(json['subscriber'] ?? meter['subscriber']);
    final square = map(
      json['square'] ?? subscriber['square'] ?? meter['square'],
    );
    final values = <dynamic>[
      if (meter['path_number'] != null)
        meter['path_number']
      else if (json['path_number'] != null)
        json['path_number']
      else if (json['meter_id'] == null && meter.isEmpty) ...[
        if (json['path_numbers'] is List) ...json['path_numbers'] as List,
        if (subscriber['meters'] is List)
          for (final item in subscriber['meters'] as List)
            map(item)['path_number'],
      ],
    ];
    final paths = {
      for (final value in values)
        if (number(value) case final int path) path,
    }.toList()..sort();
    return RecordLocation(
      squareId: number(
        json['square_id'] ?? subscriber['square_id'] ?? square['id'],
      ),
      squareName: (json['square_name'] ?? square['name'])?.toString(),
      squareCode: (json['square_code'] ?? square['code'])?.toString(),
      paths: List.unmodifiable(paths),
    );
  }
}
