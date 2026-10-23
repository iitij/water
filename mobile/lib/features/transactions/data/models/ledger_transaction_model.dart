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
    final sub = j['subscriber'] as Map<String, dynamic>?;
    return LedgerTransactionModel(
      id: j['id'] as int,
      type: j['type'] as String? ?? '',
      amount: (j['amount'] as num?)?.toDouble() ?? 0,
      subscriberId: (j['subscriber_id'] as num?)?.toInt(),
      subscriberName: sub?['name'] as String?,
      meterId: (j['meter_id'] as num?)?.toInt(),
      description: j['description'] as String?,
      createdAt: j['created_at'] as String?,
      referenceType: j['reference_type'] as String?,
      referenceId: (j['reference_id'] as num?)?.toInt(),
    );
  }
}
