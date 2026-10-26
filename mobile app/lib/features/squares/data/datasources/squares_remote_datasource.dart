import '../../../../core/fouad/api_client.dart';
import '../../../../core/fouad/json_read.dart';
import '../../../../core/network/paginated_collection.dart';
import '../../../subscribers/data/models/subscriber_model.dart';
import '../models/square_model.dart';

class SquaresRemoteDatasource {
  SquaresRemoteDatasource(this._api);
  final ApiClient _api;

  Future<List<SquareModel>> getSquares({String? search}) async {
    final data = await collectPaginatedCollection((page) => _api.get('/squares', query: {
      if (search != null && search.isNotEmpty) 'search': search,
      'page': page,
    }), collectionKeys: const ['squares']);
    return jMapList(data, const ['squares']).map(SquareModel.fromJson).toList();
  }

  Future<({SquareModel square, List<SubscriberModel> subscribers})> getSquare(int id) async {
    var j = jMap(await _api.get('/squares/$id'));
    if (j['id'] == null && j['square'] is Map) j = {...j, ...jMap(j['square'])};
    final subs = jMapList(j['subscribers']).map(SubscriberModel.fromJson).toList();
    return (square: SquareModel.fromJson(j), subscribers: subs);
  }

  Future<SquareModel> create(Map<String, dynamic> payload) async {
    final j = jMap(await _api.post('/squares', data: payload));
    return SquareModel.fromJson(jMapOrNull(j['square']) ?? j);
  }

  Future<SquareModel> update(int id, Map<String, dynamic> payload) async {
    final j = jMap(await _api.put('/squares/$id', data: payload));
    return SquareModel.fromJson(jMapOrNull(j['square']) ?? j);
  }

  Future<void> delete(int id) => _api.delete('/squares/$id');

  Future<int> bulkDelete(List<int> ids) async {
    final j = jMap(await _api.post('/squares/bulk-delete', data: {'ids': ids}));
    return jInt(j['deleted_count']);
  }
}
