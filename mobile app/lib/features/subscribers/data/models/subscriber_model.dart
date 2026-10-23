import '../../domain/entities/subscriber.dart';

double _d(Object? v) => (v as num?)?.toDouble() ?? 0;

class SubscriberModel extends Subscriber {
  const SubscriberModel({
    required super.id,
    required super.name,
    super.phone,
    super.address,
    super.squareId,
    super.squareName,
    super.status,
    super.subscriptionStartDate,
    super.metersCount,
    super.creditBalance,
    super.debitBalance,
    super.generalBalance,
    super.meterNumber,
  });

  factory SubscriberModel.fromJson(Map<String, dynamic> j) {
    final square = j['square'] as Map<String, dynamic>?;
    return SubscriberModel(
      id: j['id'] as int,
      name: j['name'] as String? ?? '',
      phone: j['phone'] as String?,
      address: j['address'] as String?,
      squareId: (j['square_id'] as num?)?.toInt(),
      squareName: square?['name'] as String?,
      status: SubscriberStatusX.fromApi(j['status'] as String?),
      subscriptionStartDate: j['subscription_start_date'] as String?,
      metersCount: (j['meters_count'] as num?)?.toInt() ??
          ((j['meters'] as List?)?.length ?? 0),
      creditBalance: _d(j['credit_balance']),
      debitBalance: _d(j['debit_balance'] ?? j['balance']),
      generalBalance: _d(j['account_balance'] ?? j['balance']),
      meterNumber: j['meter_number'] as String?,
    );
  }

  Map<String, dynamic> toPayload() => {
        'name': name,
        'phone': phone,
        'square_id': squareId,
        'status': status.apiValue,
        'subscription_start_date': subscriptionStartDate,
        'address': address,
      };
}

class MeterBalanceModel extends MeterBalance {
  const MeterBalanceModel({
    required super.meterId,
    required super.uniqueCode,
    super.tenantName,
    super.credit,
    super.debit,
    super.readingsCount,
    super.status,
    super.pathNumber,
  });

  factory MeterBalanceModel.fromJson(Map<String, dynamic> j) => MeterBalanceModel(
        meterId: j['id'] as int,
        uniqueCode: j['unique_code'] as String? ?? '',
        tenantName: j['tenant_name'] as String?,
        credit: _d(j['meter_credit']),
        debit: _d(j['meter_debit']),
        readingsCount: (j['readings_count'] as num?)?.toInt() ?? 0,
        status: j['status'] as String? ?? 'active',
        pathNumber: (j['path_number'] as num?)?.toInt(),
      );
}

class LedgerEntryModel extends LedgerEntry {
  const LedgerEntryModel({
    required super.id,
    required super.type,
    required super.amount,
    super.meterId,
    super.party,
    super.description,
    super.createdAt,
    super.runningBalance,
  });

  factory LedgerEntryModel.fromJson(Map<String, dynamic> j) => LedgerEntryModel(
        id: j['id'] as int,
        type: j['type'] as String? ?? '',
        amount: _d(j['amount']),
        meterId: (j['meter_id'] as num?)?.toInt(),
        party: j['party'] as String? ?? 'الحساب العام للمشترك',
        description: j['description'] as String?,
        createdAt: j['created_at'] as String?,
        runningBalance: _d(j['running_balance']),
      );
}
