import '../../../../core/fouad/api_client.dart';
import '../../../../core/network/api_endpoints.dart';
import 'models/global_search_models.dart';

/// مصدر نتائج البحث الشامل من الخادم.
class GlobalSearchRemoteDatasource {
  GlobalSearchRemoteDatasource(this._api);

  final ApiClient _api;

  Future<GlobalSearchResponse> search(
    String query, {
    int limit = 5,
    int? pathNumber,
    DateTime? addedFrom,
    DateTime? addedTo,
    String? sortBy,
    bool descending = true,
  }) async {
    final data = await _api.get(
      ApiEndpoints.globalSearch,
      query: <String, dynamic>{
        'q': query,
        'limit': limit,
        if (pathNumber != null) 'path_number': pathNumber,
        if (addedFrom != null)
          'added_from': addedFrom.toIso8601String().split('T').first,
        if (addedTo != null)
          'added_to': addedTo.toIso8601String().split('T').first,
        if (sortBy != null) 'sort_by': sortBy,
        if (sortBy != null) 'sort_direction': descending ? 'desc' : 'asc',
      },
    );

    if (data is Map<String, dynamic>) {
      return GlobalSearchResponse.fromJson(data);
    }

    return GlobalSearchResponse(query: query);
  }
}
