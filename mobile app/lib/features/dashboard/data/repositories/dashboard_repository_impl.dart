import '../../../admin_shared/data/admin_api_client.dart';
import '../../domain/entities/dashboard_entities.dart';
import '../../domain/repositories/dashboard_repository.dart';
import '../datasources/dashboard_local_data_source.dart';
import '../datasources/dashboard_remote_data_source.dart';

class DashboardRepositoryImpl implements DashboardRepository {
  const DashboardRepositoryImpl(this._remote, this._local);

  final DashboardRemoteDataSource _remote;
  final DashboardLocalDataSource _local;

  @override
  Future<DashboardSnapshot> getDashboard({bool forceRefresh = false}) async {
    // Network remains the source of truth; cache is an offline fallback only.
    try {
      final data = await _remote.getDashboard();
      await _local.cacheDashboard(data);
      return DashboardSnapshot(
        data: data,
        updatedAt: DateTime.now(),
        isFromCache: false,
      );
    } catch (error) {
      if (!adminAllowsCacheFallback(error)) rethrow;
      final cached = await _local.readDashboard();
      if (cached == null) rethrow;
      return DashboardSnapshot(
        data: cached.data,
        updatedAt: cached.savedAt,
        isFromCache: true,
      );
    }
  }

  @override
  Future<ReportSnapshot> getReport(ReportFilter filter) async {
    if (!filter.isValid) {
      throw ArgumentError('تاريخ بداية التقرير يجب ألا يكون بعد تاريخ النهاية.');
    }
    try {
      final report = await _remote.getReport(filter);
      await _local.cacheReport(filter, report);
      return ReportSnapshot(
        report: report,
        updatedAt: DateTime.now(),
        isFromCache: false,
      );
    } catch (error) {
      if (!adminAllowsCacheFallback(error)) rethrow;
      final cached = await _local.readReport(filter);
      if (cached == null) rethrow;
      return ReportSnapshot(
        report: cached.report,
        updatedAt: cached.savedAt,
        isFromCache: true,
      );
    }
  }
}
