import '../../../../core/fouad/json_read.dart';
import '../../domain/entities/ledger_transaction.dart';

class LedgerTransactionModel extends LedgerTransaction {
  const LedgerTransactionModel({
    required super.id,
    required super.type,
    required super.amount,
    super.subscriberId,
    super.subscriberName,
    super.meterId,
    super.description,
    super.createdAt,
    super.referenceType,
    super.referenceId,
  });

  factory LedgerTransactionModel.fromJson(Map<String, dynamic> j) {
    final sub = jMapOrNull(j['subscriber']);
    return LedgerTransactionModel(
      id: jInt(j['id']),
      type: jStr(j['type']) ?? '',
      amount: jDouble(j['amount']),
      subscriberId: jIntOrNull(j['subscriber_id']),
      subscriberName: jStr(sub?['name'] ?? j['subscriber_name']),
      meterId: jIntOrNull(j['meter_id']),
      description: jStr(j['description']),
      createdAt: jStr(j['created_at']),
      referenceType: jStr(j['reference_type']),
      referenceId: jIntOrNull(j['reference_id']),
    );
  }
}
