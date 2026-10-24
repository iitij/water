import '../../../../core/fouad/json_read.dart';
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
    final sub = jMapOrNull(j['subscriber']);
    final inv = jMapOrNull(j['invoice']);
    final date = jStr(j['service_date']) ?? '';
    return ServiceRequestModel(
      id: jInt(j['id']),
      subscriberId: jInt(j['subscriber_id']),
      subscriberName: jStr(sub?['name'] ?? j['subscriber_name']),
      amount: jDouble(j['amount']),
      serviceDate: date.length > 10 ? date.substring(0, 10) : date,
      status: TankerStatusX.fromApi(jStr(j['status'])),
      invoiceId: jIntOrNull(j['invoice_id']),
      invoiceNumber: jStr(inv?['bill_number']),
      notes: jStr(j['notes']),
    );
  }
}
