import '../../../../core/fouad/api_client.dart';
import '../../../../core/fouad/json_read.dart';
import '../models/subscription_fee_model.dart';

class SubscriptionFeesRemoteDatasource {
  SubscriptionFeesRemoteDatasource(this._api);
  final ApiClient _api;

  Future<Map<String, dynamic>> getFees({String? status, int? subscriberId}) async {
    final data = await _api.get('/subscription-fees', query: {
      if (status != null) 'status': status,
      if (subscriberId != null) 'subscriber_id': subscriberId,
    });
    return data is List ? {'data': data} : jMap(data);
  }

  SubscriptionFeeModel _one(Object? raw) {
    final j = jMap(raw);
    return SubscriptionFeeModel.fromJson(jMapOrNull(j['subscription_fee']) ?? jMapOrNull(j['data']) ?? j);
  }

  Future<SubscriptionFeeModel> create(Map<String, dynamic> p) async =>
      _one(await _api.post('/subscription-fees', data: p));

  Future<SubscriptionFeeModel> update(int id, Map<String, dynamic> p) async =>
      _one(await _api.put('/subscription-fees/$id', data: p));

  Future<void> delete(int id) => _api.delete('/subscription-fees/$id');

  Future<int> bulkDelete(List<int> ids) async {
    final j = jMap(await _api.post('/subscription-fees/bulk-delete', data: {'ids': ids}));
    return jInt(j['deleted_count']);
  }

  Future<Map<String, dynamic>> invoice(int id) async =>
      jMap(await _api.post('/subscription-fees/$id/invoice'));

  static SubscriptionFeeModel parse(Map<String, dynamic> j) => SubscriptionFeeModel.fromJson(j);
  static double d(Object? v) => jDouble(v);
}
