import 'package:intl/intl.dart';

import '../../../admin_shared/data/admin_api_client.dart';
import '../../domain/entities/export_entities.dart';
import '../../domain/repositories/export_repository.dart';
import '../datasources/export_remote_data_source.dart';

class ExportRepositoryImpl implements ExportRepository {
  const ExportRepositoryImpl(this._remote, {DateTime Function()? now})
      : _now = now ?? DateTime.now;

  final ExportRemoteDataSource _remote;
  final DateTime Function() _now;

  @override
  Future<ExportCatalog> getCatalog() async {
    final results = await Future.wait<dynamic>([_remote.getSummary(), _remote.getSquares()]);
    return ExportCatalog(
      summary: results[0] as ExportSummary,
      squares: results[1] as List<ExportSquare>,
    );
  }

  @override
  Future<ExcelDownload> download(ExportKind kind, ExportFilters filters) async {
    if (!filters.hasValidDates) {
      throw const AdminApiException('تاريخ البداية يجب ألا يكون بعد تاريخ النهاية.');
    }
    final response = await _remote.download(kind, filters);
    if (!_isXlsx(response.bytes)) {
      throw const AdminApiException(
        'الرد المستلم ليس ملف Excel صالحاً. تحقق من الجلسة ومسار التصدير.',
      );
    }
    final date = DateFormat('yyyy-MM-dd', 'en_US').format(_now());
    final fallbackName = switch (kind) {
      ExportKind.subscribers => 'المشتركون_$date.xlsx',
      ExportKind.invoices => 'فواتير_المشتركين_$date.xlsx',
      ExportKind.readings => 'قراءات_العدادات_$date.xlsx',
    };
    final headerName = response.fileName?.trim();
    return ExcelDownload(
      kind: kind,
      fileName: headerName != null && headerName.toLowerCase().endsWith('.xlsx')
          ? headerName
          : fallbackName,
      bytes: response.bytes,
    );
  }

  bool _isXlsx(List<int> bytes) {
    // XLSX is a ZIP container: normal, empty, or spanned archive signature.
    if (bytes.length < 4 || bytes[0] != 0x50 || bytes[1] != 0x4B) return false;
    return (bytes[2] == 0x03 && bytes[3] == 0x04) ||
        (bytes[2] == 0x05 && bytes[3] == 0x06) ||
        (bytes[2] == 0x07 && bytes[3] == 0x08);
  }
}
