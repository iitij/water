import '../../../../core/fouad/api_client.dart';
import '../../../../core/fouad/json_read.dart';
import '../models/service_request_model.dart';

class ServiceRequestsRemoteDatasource {
  ServiceRequestsRemoteDatasource(this._api);
  final ApiClient _api;

  Future<Map<String, dynamic>> getRequests({String? status, int? subscriberId, String? from, String? until}) async {
    final data = await _api.get('/service-requests', query: {
      if (status != null) 'status': status,
      if (subscriberId != null) 'subscriber_id': subscriberId,
      if (from != null) 'created_from': from,
      if (until != null) 'created_until': until,
    });
    return data is List ? {'data': data} : jMap(data);
  }

  ServiceRequestModel _one(Object? raw) {
    final j = jMap(raw);
    return ServiceRequestModel.fromJson(jMapOrNull(j['service_request']) ?? jMapOrNull(j['data']) ?? j);
  }

  Future<ServiceRequestModel> create(Map<String, dynamic> p) async =>
      _one(await _api.post('/service-requests', data: p));

  Future<ServiceRequestModel> update(int id, Map<String, dynamic> p) async =>
      _one(await _api.put('/service-requests/$id', data: p));

  Future<void> delete(int id) => _api.delete('/service-requests/$id');

  Future<int> bulkDelete(List<int> ids) async {
    final j = jMap(await _api.post('/service-requests/bulk-delete', data: {'ids': ids}));
    return jInt(j['deleted_count']);
  }

  Future<ServiceRequestModel> complete(int id) async =>
      _one(await _api.patch('/service-requests/$id/complete'));

  Future<Map<String, dynamic>> invoice(int id) async =>
      jMap(await _api.post('/service-requests/$id/invoice'));

  static ServiceRequestModel parse(Map<String, dynamic> j) => ServiceRequestModel.fromJson(j);
}
