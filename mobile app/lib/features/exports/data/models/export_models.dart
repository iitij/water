import '../../../admin_shared/data/admin_json.dart';
import '../../domain/entities/export_entities.dart';

class ExportSquareModel extends ExportSquare {
  const ExportSquareModel({required super.id, required super.name, super.code});

  factory ExportSquareModel.fromJson(dynamic value) {
    final json = adminMap(value);
    return ExportSquareModel(
      id: adminInt(json['id']),
      name: adminString(json['name'], 'مربع غير مسمى'),
      code: json['code']?.toString(),
    );
  }
}

class ExportSummaryModel extends ExportSummary {
  const ExportSummaryModel({
    required super.totalSubscribers,
    required super.totalInvoices,
    required super.totalReadings,
    required super.totalSquares,
  });

  factory ExportSummaryModel.fromJson(dynamic value) {
    final json = adminDataMap(value);
    return ExportSummaryModel(
      totalSubscribers: adminInt(json['totalSubscribers'] ?? json['total_subscribers']),
      totalInvoices: adminInt(json['totalInvoices'] ?? json['total_invoices']),
      totalReadings: adminInt(json['totalReadings'] ?? json['total_readings']),
      totalSquares: adminInt(json['totalSquares'] ?? json['total_squares']),
    );
  }
}
