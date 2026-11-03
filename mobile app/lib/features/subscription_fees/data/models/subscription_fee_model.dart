import '../../../../core/fouad/json_read.dart';
import '../../../../core/models/record_location.dart';
import '../../domain/entities/subscription_fee.dart';

class SubscriptionFeeModel extends SubscriptionFee {
  const SubscriptionFeeModel({
    required super.id,
    required super.subscriberId,
    required super.amount,
    super.subscriberName,
    super.meterId,
    super.meterCode,
    super.status,
    super.invoiceId,
    super.invoiceNumber,
    super.notes,
    super.createdAt,
    super.location,
  });

  factory SubscriptionFeeModel.fromJson(Map<String, dynamic> j) {
    final sub = jMapOrNull(j['subscriber']);
    final meter = jMapOrNull(j['meter']);
    final inv = jMapOrNull(j['invoice']);
    return SubscriptionFeeModel(
      id: jInt(j['id']),
      location: RecordLocation.fromJson(j),
      subscriberId: jInt(j['subscriber_id']),
      subscriberName: jStr(sub?['name'] ?? j['subscriber_name']),
      meterId: jIntOrNull(j['meter_id']),
      meterCode: jStr(meter?['unique_code'] ?? j['meter_code']),
      amount: jDouble(j['amount']),
      status: FeeStatusX.fromApi(jStr(j['status'])),
      invoiceId: jIntOrNull(j['invoice_id']),
      invoiceNumber: jStr(inv?['bill_number']),
      notes: jStr(j['notes']),
      createdAt: jStr(j['created_at']),
    );
  }
}
