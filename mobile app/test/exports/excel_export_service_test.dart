import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/features/admin_shared/data/admin_api_client.dart';
import 'package:water_project/features/exports/data/datasources/export_remote_data_source.dart';
import 'package:water_project/features/exports/data/repositories/export_repository_impl.dart';
import 'package:water_project/features/exports/data/services/excel_export_service.dart';
import 'package:water_project/features/exports/domain/entities/export_entities.dart';
import 'package:water_project/features/exports/domain/repositories/export_repository.dart';

import '../support/fake_admin_api_client.dart';

void main() {
  test('service saves then delegates open and share to the platform gateway', () async {
    final api = FakeAdminApiClient();
    api.downloads['/exports/readings'] = AdminDownload(
      bytes: Uint8List.fromList(const [0x50, 0x4B, 0x03, 0x04]),
      fileName: 'قراءات أكتوبر.xlsx',
    );
    final repository = ExportRepositoryImpl(ExportRemoteDataSource(api));
    final gateway = _MemoryExcelGateway();
    final service = ExcelExportService(repository, gateway);

    final file = await service.export(ExportKind.readings, const ExportFilters());
    await service.open(file);
    await service.share(file);

    expect(file.fileName, 'قراءات أكتوبر.xlsx');
    expect(file.path, '/documents/exports/قراءات أكتوبر.xlsx');
    expect(gateway.opened, [file]);
    expect(gateway.shared, [file]);
  });
}

class _MemoryExcelGateway implements ExcelFileGateway {
  final opened = <ExportedExcelFile>[];
  final shared = <ExportedExcelFile>[];

  @override
  Future<ExportedExcelFile> save(ExcelDownload download) async {
    return ExportedExcelFile(
      kind: download.kind,
      path: '/documents/exports/${download.fileName}',
      fileName: download.fileName,
      sizeInBytes: download.bytes.length,
      createdAt: DateTime(2026, 10, 23),
    );
  }

  @override
  Future<void> open(ExportedExcelFile file) async => opened.add(file);

  @override
  Future<void> share(ExportedExcelFile file) async => shared.add(file);
}
