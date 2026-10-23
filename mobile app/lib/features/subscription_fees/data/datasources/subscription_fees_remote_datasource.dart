import '../../../../core/fouad/api_client.dart';
import '../models/subscription_fee_model.dart';

class SubscriptionFeesRemoteDatasource {
  SubscriptionFeesRemoteDatasource(this._api);
  final ApiClient _api;

  Future<Map<String, dynamic>> getFees({String? status, int? subscriberId}) async =>
      await _api.get('/subscription-fees', query: {
        if (status != null) 'status': status,
        if (subscriberId != null) 'subscriber_id': subscriberId,
      }) as Map<String, dynamic>;

  Future<SubscriptionFeeModel> create(Map<String, dynamic> p) async {
    final j = await _api.post('/subscription-fees', data: p) as Map<String, dynamic>;
    return SubscriptionFeeModel.fromJson(j['subscription_fee'] as Map<String, dynamic>);
  }

  Future<SubscriptionFeeModel> update(int id, Map<String, dynamic> p) async {
    final j = await _api.put('/subscription-fees/$id', data: p) as Map<String, dynamic>;
    return SubscriptionFeeModel.fromJson(j['subscription_fee'] as Map<String, dynamic>);
  }

  Future<void> delete(int id) => _api.delete('/subscription-fees/$id');

  Future<int> bulkDelete(List<int> ids) async {
    final j = await _api.post('/subscription-fees/bulk-delete', data: {'ids': ids}) as Map<String, dynamic>;
    return (j['deleted_count'] as num?)?.toInt() ?? 0;
  }

  Future<Map<String, dynamic>> invoice(int id) async =>
      await _api.post('/subscription-fees/$id/invoice') as Map<String, dynamic>;

  static SubscriptionFeeModel parse(Map<String, dynamic> j) => SubscriptionFeeModel.fromJson(j);
  static double d(Object? v) => (v as num?)?.toDouble() ?? 0;
}
