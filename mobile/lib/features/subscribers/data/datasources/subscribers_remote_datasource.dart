import '../../../../core/network/api_client.dart';
import '../../../squares/data/models/square_model.dart';
import '../models/subscriber_model.dart';

class SubscribersRemoteDatasource {
  SubscribersRemoteDatasource(this._api);
  final ApiClient _api;

  Future<List<SubscriberModel>> getSubscribers({
    String? query, int? squareId, String? status, int? perPage,
  }) async {
    final data = await _api.get('/subscribers', query: {
      if (query != null && query.isNotEmpty) 'q': query,
      if (squareId != null) 'square_id': squareId,
      if (status != null) 'status': status,
      if (perPage != null) 'per_page': perPage,
    });
    final list = data is Map<String, dynamic> ? (data['data'] as List) : data as List;
    return list.map((e) => SubscriberModel.fromJson(e as Map<String, dynamic>)).toList();
  }

  Future<Map<String, dynamic>> get360(int id) async =>
      await _api.get('/subscribers/$id') as Map<String, dynamic>;

  Future<SubscriberModel> create(Map<String, dynamic> payload) async {
    final j = await _api.post('/subscribers', data: payload) as Map<String, dynamic>;
    return SubscriberModel.fromJson(j['subscriber'] as Map<String, dynamic>);
  }

  Future<SubscriberModel> update(int id, Map<String, dynamic> payload) async {
    final j = await _api.put('/subscribers/$id', data: payload) as Map<String, dynamic>;
    return SubscriberModel.fromJson(j['subscriber'] as Map<String, dynamic>);
  }

  Future<void> delete(int id) => _api.delete('/subscribers/$id');

  Future<List<LedgerEntryModel>> getTransactions(int id, {String? meterId}) async {
    final data = await _api.get('/subscribers/$id/transactions', query: {
      if (meterId != null) 'meter_id': meterId,
    });
    return (data as List).map((e) => LedgerEntryModel.fromJson(e as Map<String, dynamic>)).toList();
  }

  Future<List<SquareModel>> getSquares() async {
    final data = await _api.get('/squares');
    return (data as List).map((e) => SquareModel.fromJson(e as Map<String, dynamic>)).toList();
  }

  Future<Map<String, dynamic>> collectPayment(int id, Map<String, dynamic> payload) async =>
      await _api.post('/subscribers/$id/collect-payment', data: payload) as Map<String, dynamic>;
}
