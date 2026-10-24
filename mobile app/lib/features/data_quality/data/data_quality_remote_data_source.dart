import '../../admin_shared/data/admin_api_client.dart';
import '../../admin_shared/data/admin_api_endpoints.dart';
import '../../admin_shared/data/admin_json.dart';
import '../domain/data_quality_issue.dart';

/// قراءة تقرير جودة البيانات من الخادم.
class DataQualityRemoteDataSource {
  const DataQualityRemoteDataSource(this._api);

  final AdminApiClient _api;

  Future<DataQualityReport> getReport({int? squareId, int? staleDays}) async {
    final response = await _api.get(
      AdminApiEndpoints.dataQualityIssues,
      query: <String, dynamic>{
        if (squareId != null) 'square_id': squareId,
        if (staleDays != null) 'stale_days': staleDays,
      },
    );
    return DataQualityReport.fromJson(adminMap(adminUnwrap(response)));
  }
}
