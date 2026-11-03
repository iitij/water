import 'global_search_remote_datasource.dart';
import 'models/global_search_models.dart';

/// واجهة قراءة نتائج البحث الشامل — بلا تخزين محلي.
class GlobalSearchRepository {
  GlobalSearchRepository(this._remote);

  final GlobalSearchRemoteDatasource _remote;

  Future<GlobalSearchResponse> search(
    String query, {
    int limit = 5,
    int? pathNumber,
    DateTime? addedFrom,
    DateTime? addedTo,
    String? sortBy,
    bool descending = true,
  }) => _remote.search(
    query,
    limit: limit,
    pathNumber: pathNumber,
    addedFrom: addedFrom,
    addedTo: addedTo,
    sortBy: sortBy,
    descending: descending,
  );
}
