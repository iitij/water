import 'package:intl/intl.dart';

import '../../../admin_shared/data/admin_api_client.dart';
import '../../../admin_shared/data/admin_api_endpoints.dart';
import '../models/dashboard_models.dart';
import '../models/report_models.dart';
import '../../domain/entities/dashboard_entities.dart';

class DashboardRemoteDataSource {
  const DashboardRemoteDataSource(this._api);

  final AdminApiClient _api;

  Future<DashboardDataModel> getDashboard() async {
    final response = await _api.get(AdminApiEndpoints.dashboard);
    return DashboardDataModel.fromJson(response);
  }

  Future<FinancialReportModel> getReport(ReportFilter filter) async {
    final format = DateFormat('yyyy-MM-dd', 'en_US');
    final response = await _api.get(
      AdminApiEndpoints.reports,
      query: {
        'from_date': filter.from == null ? null : format.format(filter.from!),
        'to_date': filter.to == null ? null : format.format(filter.to!),
      },
    );
    return FinancialReportModel.fromJson(response);
  }
}
