import '../entities/service_request.dart';

class TankerPage {
  const TankerPage({required this.requests, this.totalSum = 0});
  final List<ServiceRequest> requests;
  final double totalSum;
}

abstract class ServiceRequestsRepository {
  Future<TankerPage> getRequests({TankerStatus? status, int? subscriberId, String? from, String? until});
  Future<ServiceRequest> createRequest({required int subscriberId, required double amount, required String serviceDate, TankerStatus status = TankerStatus.completed, String? notes});
  Future<ServiceRequest> updateRequest(ServiceRequest request);
  Future<void> deleteRequest(int id);
  Future<ServiceRequest> markCompleted(int id);
  Future<Map<String, dynamic>> invoiceRequest(int id);
  Future<double?> lastAmountFor(int subscriberId);
}
