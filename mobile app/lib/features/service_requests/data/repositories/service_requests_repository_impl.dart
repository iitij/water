import '../../../../core/fouad/json_read.dart';
import '../../domain/entities/service_request.dart';
import '../../domain/repositories/service_requests_repository.dart';
import '../datasources/service_requests_remote_datasource.dart';

class ServiceRequestsRepositoryImpl implements ServiceRequestsRepository {
  ServiceRequestsRepositoryImpl(this._remote);
  final ServiceRequestsRemoteDatasource _remote;

  @override
  Future<TankerPage> getRequests({TankerStatus? status, int? subscriberId, String? from, String? until}) async {
    final j = await _remote.getRequests(status: status?.apiValue, subscriberId: subscriberId, from: from, until: until);
    return TankerPage(
      requests: jMapList(j).map(ServiceRequestsRemoteDatasource.parse).toList(),
      totalSum: jDouble(j['total_sum']),
    );
  }

  @override
  Future<ServiceRequest> createRequest({required int subscriberId, required double amount, required String serviceDate, TankerStatus status = TankerStatus.completed, String? notes}) =>
      _remote.create({'subscriber_id': subscriberId, 'amount': amount, 'service_date': serviceDate, 'status': status.apiValue, 'notes': notes});

  @override
  Future<ServiceRequest> updateRequest(ServiceRequest r) => _remote.update(r.id, {
        'subscriber_id': r.subscriberId,
        'amount': r.amount,
        'service_date': r.serviceDate,
        'status': r.status.apiValue,
        'notes': r.notes,
      });

  @override
  Future<void> deleteRequest(int id) => _remote.delete(id);

  @override
  Future<int> bulkDelete(List<int> ids) => _remote.bulkDelete(ids);

  @override
  Future<ServiceRequest> markCompleted(int id) => _remote.complete(id);

  @override
  Future<Map<String, dynamic>> invoiceRequest(int id) => _remote.invoice(id);

  @override
  Future<double?> lastAmountFor(int subscriberId) async {
    final page = await getRequests(subscriberId: subscriberId);
    return page.requests.isEmpty ? null : page.requests.first.amount;
  }
}
