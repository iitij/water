import '../../../../core/fouad/api_client.dart';
import '../../../../core/fouad/json_read.dart';
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
    return jMapList(data).map(SubscriberModel.fromJson).toList();
  }

  Future<Map<String, dynamic>> get360(int id) async {
    final data = jMap(await _api.get('/subscribers/$id'));
    // يقبل الشكل المسطّح أو المغلّف {subscriber: {...}} / {data: {...}}.
    if (data['id'] == null) {
      final inner = jMapOrNull(data['subscriber']) ?? jMapOrNull(data['data']);
      if (inner != null) return {...data, ...inner};
    }
    return data;
  }

  Future<SubscriberModel> create(Map<String, dynamic> payload) async {
    final j = jMap(await _api.post('/subscribers', data: payload));
    return SubscriberModel.fromJson(jMapOrNull(j['subscriber']) ?? j);
  }

  Future<SubscriberModel> update(int id, Map<String, dynamic> payload) async {
    final j = jMap(await _api.put('/subscribers/$id', data: payload));
    return SubscriberModel.fromJson(jMapOrNull(j['subscriber']) ?? j);
  }

  Future<void> delete(int id) => _api.delete('/subscribers/$id');

  Future<int> bulkDelete(List<int> ids) async {
    final j = jMap(await _api.post('/subscribers/bulk-delete', data: {'ids': ids}));
    return jInt(j['deleted_count']);
  }

  Future<List<LedgerEntryModel>> getTransactions(int id, {String? meterId}) async {
    final data = await _api.get('/subscribers/$id/transactions', query: {
      if (meterId != null) 'meter_id': meterId,
    });
    return jMapList(data, const ['transactions']).map(LedgerEntryModel.fromJson).toList();
  }

  Future<List<SquareModel>> getSquares() async {
    final data = await _api.get('/squares');
    return jMapList(data, const ['squares']).map(SquareModel.fromJson).toList();
  }

  Future<Map<String, dynamic>> collectPayment(int id, Map<String, dynamic> payload) async =>
      jMap(await _api.post('/subscribers/$id/collect-payment', data: payload));
}
