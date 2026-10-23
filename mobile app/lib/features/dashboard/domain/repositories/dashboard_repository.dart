import '../entities/dashboard_entities.dart';

abstract class DashboardRepository {
  Future<DashboardSnapshot> getDashboard({bool forceRefresh = false});

  Future<ReportSnapshot> getReport(ReportFilter filter);
}
