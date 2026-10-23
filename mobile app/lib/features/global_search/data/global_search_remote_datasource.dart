import '../../../../core/fouad/api_client.dart';
import '../../../../core/network/api_endpoints.dart';
import 'models/global_search_models.dart';

/// مصدر نتائج البحث الشامل من الخادم.
class GlobalSearchRemoteDatasource {
  GlobalSearchRemoteDatasource(this._api);

  final ApiClient _api;

  Future<GlobalSearchResponse> search(String query, {int limit = 5}) async {
    final data = await _api.get(
      ApiEndpoints.globalSearch,
      query: <String, dynamic>{'q': query, 'limit': limit},
    );

    if (data is Map<String, dynamic>) {
      return GlobalSearchResponse.fromJson(data);
    }

    return GlobalSearchResponse(query: query);
  }
}
