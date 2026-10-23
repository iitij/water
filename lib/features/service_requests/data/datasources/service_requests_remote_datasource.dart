import '../../../../core/fouad/api_client.dart';
import '../models/service_request_model.dart';

class ServiceRequestsRemoteDatasource {
  ServiceRequestsRemoteDatasource(this._api);
  final ApiClient _api;

  Future<Map<String, dynamic>> getRequests({String? status, int? subscriberId, String? from, String? until}) async =>
      await _api.get('/service-requests', query: {
        if (status != null) 'status': status,
        if (subscriberId != null) 'subscriber_id': subscriberId,
        if (from != null) 'created_from': from,
        if (until != null) 'created_until': until,
      }) as Map<String, dynamic>;

  Future<ServiceRequestModel> create(Map<String, dynamic> p) async {
    final j = await _api.post('/service-requests', data: p) as Map<String, dynamic>;
    return ServiceRequestModel.fromJson(j['service_request'] as Map<String, dynamic>);
  }

  Future<ServiceRequestModel> update(int id, Map<String, dynamic> p) async {
    final j = await _api.put('/service-requests/$id', data: p) as Map<String, dynamic>;
    return ServiceRequestModel.fromJson(j['service_request'] as Map<String, dynamic>);
  }

  Future<void> delete(int id) => _api.delete('/service-requests/$id');

  Future<ServiceRequestModel> complete(int id) async {
    final j = await _api.patch('/service-requests/$id/complete') as Map<String, dynamic>;
    return ServiceRequestModel.fromJson(j['service_request'] as Map<String, dynamic>);
  }

  Future<Map<String, dynamic>> invoice(int id) async =>
      await _api.post('/service-requests/$id/invoice') as Map<String, dynamic>;

  static ServiceRequestModel parse(Map<String, dynamic> j) => ServiceRequestModel.fromJson(j);
}
