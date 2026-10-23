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
  });

  factory SubscriptionFeeModel.fromJson(Map<String, dynamic> j) {
    final sub = j['subscriber'] as Map<String, dynamic>?;
    final meter = j['meter'] as Map<String, dynamic>?;
    final inv = j['invoice'] as Map<String, dynamic>?;
    return SubscriptionFeeModel(
      id: j['id'] as int,
      subscriberId: (j['subscriber_id'] as num).toInt(),
      subscriberName: sub?['name'] as String?,
      meterId: (j['meter_id'] as num?)?.toInt(),
      meterCode: meter?['unique_code'] as String?,
      amount: (j['amount'] as num?)?.toDouble() ?? 0,
      status: FeeStatusX.fromApi(j['status'] as String?),
      invoiceId: (j['invoice_id'] as num?)?.toInt(),
      invoiceNumber: inv?['bill_number'] as String?,
      notes: j['notes'] as String?,
      createdAt: j['created_at'] as String?,
    );
  }
}
