import '../entities/export_entities.dart';

abstract class ExportRepository {
  Future<ExportCatalog> getCatalog();
  Future<ExcelDownload> download(ExportKind kind, ExportFilters filters);
}
