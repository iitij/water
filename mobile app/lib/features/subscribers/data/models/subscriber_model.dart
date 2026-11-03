import '../../../../core/fouad/json_read.dart';
import '../../../../core/models/square_label.dart';
import '../../domain/entities/subscriber.dart';

double _d(Object? v) => jDouble(v);

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
    super.pathNumbers,
    super.createdAt,
  });

  factory SubscriberModel.fromJson(Map<String, dynamic> j) {
    final square = jMapOrNull(j['square']);
    return SubscriberModel(
      id: jInt(j['id']),
      name: jStr(j['name']) ?? '',
      phone: jStr(j['phone']),
      address: jStr(j['address']),
      squareId: jIntOrNull(j['square_id']),
      squareName: squareLabelOrNull(square?['name'] ?? j['square_name'], square?['code'] ?? j['square_code']),
      status: SubscriberStatusX.fromApi(jStr(j['status'])),
      subscriptionStartDate: jStr(j['subscription_start_date']),
      metersCount:
          jIntOrNull(j['meters_count']) ??
          ((j['meters'] is List) ? (j['meters'] as List).length : 0),
      creditBalance: _d(j['credit_balance']),
      debitBalance: _d(j['debit_balance'] ?? j['balance']),
      generalBalance: _d(j['account_balance'] ?? j['balance']),
      meterNumber: jStr(j['meter_number']),
      pathNumbers: _pathNumbers(j),
      createdAt: DateTime.tryParse(jStr(j['created_at']) ?? ''),
    );
  }

  static List<int> _pathNumbers(Map<String, dynamic> json) {
    final values = json['path_numbers'];
    final numbers = <int>{
      if (values is List)
        for (final value in values)
          if (jIntOrNull(value) case final int number) number,
      for (final meter in jMapList(json['meters']))
        if (jIntOrNull(meter['path_number']) case final int number) number,
    }.toList()..sort();
    return List.unmodifiable(numbers);
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

  factory MeterBalanceModel.fromJson(Map<String, dynamic> j) =>
      MeterBalanceModel(
        meterId: jInt(j['id']),
        uniqueCode: jStr(j['unique_code']) ?? '',
        tenantName: jStr(j['tenant_name']),
        credit: _d(j['meter_credit']),
        debit: _d(j['meter_debit']),
        readingsCount: jInt(j['readings_count']),
        status: jStr(j['status']) ?? 'active',
        pathNumber: jIntOrNull(j['path_number']),
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
    id: jInt(j['id']),
    type: jStr(j['type']) ?? '',
    amount: _d(j['amount']),
    meterId: jIntOrNull(j['meter_id']),
    party: jStr(j['party']) ?? 'الحساب العام للمشترك',
    description: jStr(j['description']),
    createdAt: jStr(j['created_at']),
    runningBalance: _d(j['running_balance']),
  );
}
