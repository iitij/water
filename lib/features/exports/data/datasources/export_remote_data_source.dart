import '../../../admin_shared/data/admin_api_client.dart';
import '../../../admin_shared/data/admin_api_endpoints.dart';
import '../../../admin_shared/data/admin_json.dart';
import '../../domain/entities/export_entities.dart';
import '../models/export_models.dart';

class ExportRemoteDataSource {
  const ExportRemoteDataSource(this._api);

  final AdminApiClient _api;

  Future<ExportSummaryModel> getSummary() async {
    final response = await _api.get(AdminApiEndpoints.exportsSummary);
    return ExportSummaryModel.fromJson(response);
  }

  Future<List<ExportSquareModel>> getSquares() async {
    final response = await _api.get(AdminApiEndpoints.squares);
    return adminList(response, const ['squares'])
        .map(ExportSquareModel.fromJson)
        .where((square) => square.id > 0)
        .toList(growable: false);
  }

  Future<AdminDownload> download(ExportKind kind, ExportFilters filters) {
    final path = switch (kind) {
      ExportKind.subscribers => AdminApiEndpoints.exportSubscribers,
      ExportKind.invoices => AdminApiEndpoints.exportInvoices,
      ExportKind.readings => AdminApiEndpoints.exportReadings,
    };
    final fallback = switch (kind) {
      ExportKind.subscribers => AdminApiEndpoints.legacyExportSubscribers,
      ExportKind.invoices => AdminApiEndpoints.legacyExportInvoices,
      ExportKind.readings => AdminApiEndpoints.legacyExportReadings,
    };
    return _api.download(path, fallbackPath: fallback, query: filters.toQuery(kind));
  }
}
