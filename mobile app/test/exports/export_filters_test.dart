import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/features/admin_shared/data/admin_api_client.dart';
import 'package:water_project/features/exports/data/datasources/export_remote_data_source.dart';
import 'package:water_project/features/exports/data/repositories/export_repository_impl.dart';
import 'package:water_project/features/exports/domain/entities/export_entities.dart';

import '../support/fake_admin_api_client.dart';

void main() {
  test('filters match DataExportService query parameters', () {
    final filters = ExportFilters(
      squareId: 4,
      status: 'overdue',
      fromDate: DateTime(2026, 10, 1),
      toDate: DateTime(2026, 10, 23),
      isInvoiced: false,
    );

    expect(filters.toQuery(ExportKind.subscribers), {
      'square_id': 4,
      'status': 'overdue',
    });
    expect(filters.toQuery(ExportKind.invoices), {
      'square_id': 4,
      'status': 'overdue',
      'from_date': '2026-10-01',
      'to_date': '2026-10-23',
    });
    expect(filters.toQuery(ExportKind.readings), {
      'square_id': 4,
      'from_date': '2026-10-01',
      'to_date': '2026-10-23',
      'is_invoiced': '0',
    });
  });

  test('repository accepts XLSX signature and rejects HTML responses', () async {
    final api = FakeAdminApiClient();
    final repository = ExportRepositoryImpl(
      ExportRemoteDataSource(api),
      now: () => DateTime(2026, 10, 23),
    );
    api.downloads['/exports/subscribers'] = AdminDownload(
      bytes: Uint8List.fromList(const [0x50, 0x4B, 0x03, 0x04, 1, 2]),
    );
    api.downloads['/exports/invoices'] = AdminDownload(
      bytes: Uint8List.fromList('<html>login</html>'.codeUnits),
    );

    final excel = await repository.download(
      ExportKind.subscribers,
      const ExportFilters(),
    );
    expect(excel.fileName, 'المشتركون_2026-10-23.xlsx');

    await expectLater(
      repository.download(ExportKind.invoices, const ExportFilters()),
      throwsA(isA<AdminApiException>()),
    );
  });

  test('invalid date range is rejected before any network request', () async {
    final api = FakeAdminApiClient();
    final repository = ExportRepositoryImpl(ExportRemoteDataSource(api));
    final filters = ExportFilters(
      fromDate: DateTime(2026, 10, 23),
      toDate: DateTime(2026, 10, 1),
    );

    await expectLater(
      repository.download(ExportKind.readings, filters),
      throwsA(isA<AdminApiException>()),
    );
    expect(api.requests, isEmpty);
  });
}
