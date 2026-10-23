import '../../../../core/network/api_client.dart';
import '../../../subscribers/data/models/subscriber_model.dart';
import '../models/square_model.dart';

class SquaresRemoteDatasource {
  SquaresRemoteDatasource(this._api);
  final ApiClient _api;

  Future<List<SquareModel>> getSquares({String? search}) async {
    final data = await _api.get('/squares', query: {
      if (search != null && search.isNotEmpty) 'search': search,
    });
    return (data as List).map((e) => SquareModel.fromJson(e as Map<String, dynamic>)).toList();
  }

  Future<({SquareModel square, List<SubscriberModel> subscribers})> getSquare(int id) async {
    final j = await _api.get('/squares/$id') as Map<String, dynamic>;
    final subs = ((j['subscribers'] as List?) ?? [])
        .map((e) => SubscriberModel.fromJson(e as Map<String, dynamic>))
        .toList();
    return (square: SquareModel.fromJson(j), subscribers: subs);
  }

  Future<SquareModel> create(Map<String, dynamic> payload) async {
    final j = await _api.post('/squares', data: payload) as Map<String, dynamic>;
    return SquareModel.fromJson(j['square'] as Map<String, dynamic>);
  }

  Future<SquareModel> update(int id, Map<String, dynamic> payload) async {
    final j = await _api.put('/squares/$id', data: payload) as Map<String, dynamic>;
    return SquareModel.fromJson(j['square'] as Map<String, dynamic>);
  }

  Future<void> delete(int id) => _api.delete('/squares/$id');

  Future<int> bulkDelete(List<int> ids) async {
    final j = await _api.post('/squares/bulk-delete', data: {'ids': ids}) as Map<String, dynamic>;
    return (j['deleted_count'] as num).toInt();
  }
}
