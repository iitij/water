import '../../domain/entities/service_request.dart';

class ServiceRequestModel extends ServiceRequest {
  const ServiceRequestModel({
    required super.id,
    required super.subscriberId,
    required super.amount,
    required super.serviceDate,
    super.subscriberName,
    super.status,
    super.invoiceId,
    super.invoiceNumber,
    super.notes,
  });

  factory ServiceRequestModel.fromJson(Map<String, dynamic> j) {
    final sub = j['subscriber'] as Map<String, dynamic>?;
    final inv = j['invoice'] as Map<String, dynamic>?;
    final date = j['service_date'] as String? ?? '';
    return ServiceRequestModel(
      id: j['id'] as int,
      subscriberId: (j['subscriber_id'] as num).toInt(),
      subscriberName: sub?['name'] as String?,
      amount: (j['amount'] as num?)?.toDouble() ?? 0,
      serviceDate: date.length > 10 ? date.substring(0, 10) : date,
      status: TankerStatusX.fromApi(j['status'] as String?),
      invoiceId: (j['invoice_id'] as num?)?.toInt(),
      invoiceNumber: inv?['bill_number'] as String?,
      notes: j['notes'] as String?,
    );
  }
}
