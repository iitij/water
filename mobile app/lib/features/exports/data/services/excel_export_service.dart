import 'dart:io';

import 'package:open_filex/open_filex.dart';
import 'package:path/path.dart' as path;
import 'package:path_provider/path_provider.dart';
import 'package:share_plus/share_plus.dart';

import '../../domain/entities/export_entities.dart';
import '../../domain/repositories/export_repository.dart';

abstract class ExcelFileGateway {
  Future<ExportedExcelFile> save(ExcelDownload download);
  Future<void> open(ExportedExcelFile file);
  Future<void> share(ExportedExcelFile file);
}

class PlatformExcelFileGateway implements ExcelFileGateway {
  static const excelMime =
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

  @override
  Future<ExportedExcelFile> save(ExcelDownload download) async {
    final documents = await getApplicationDocumentsDirectory();
    final directory = Directory(path.join(documents.path, 'exports'));
    await directory.create(recursive: true);
    final safeName = download.fileName
        .replaceAll(RegExp(r'[\\/:*?"<>|]'), '_')
        .replaceAll(RegExp(r'^\.+'), '');
    final effectiveName = safeName.isEmpty ? 'water_export.xlsx' : safeName;
    final file = File(path.join(directory.path, effectiveName));
    await file.writeAsBytes(download.bytes, flush: true);
    return ExportedExcelFile(
      kind: download.kind,
      path: file.path,
      fileName: effectiveName,
      sizeInBytes: download.bytes.length,
      createdAt: DateTime.now(),
    );
  }

  @override
  Future<void> open(ExportedExcelFile file) async {
    final result = await OpenFilex.open(file.path);
    if (result.type != ResultType.done) {
      throw Exception('تعذر فتح الملف: ${result.message}');
    }
  }

  @override
  Future<void> share(ExportedExcelFile file) async {
    await Share.shareXFiles(
      [XFile(file.path, mimeType: excelMime)],
      text: 'تصدير بيانات مشروع المياه — ${file.fileName}',
    );
  }
}

class ExcelExportService {
  const ExcelExportService(this._repository, this._files);

  factory ExcelExportService.platform(ExportRepository repository) =>
      ExcelExportService(repository, PlatformExcelFileGateway());

  final ExportRepository _repository;
  final ExcelFileGateway _files;

  Future<ExportCatalog> getCatalog() => _repository.getCatalog();

  Future<ExportedExcelFile> export(ExportKind kind, ExportFilters filters) async {
    final download = await _repository.download(kind, filters);
    return _files.save(download);
  }

  Future<void> open(ExportedExcelFile file) => _files.open(file);
  Future<void> share(ExportedExcelFile file) => _files.share(file);
}
