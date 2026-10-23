import 'package:equatable/equatable.dart';

/// قيد دفتر أستاذ عام (SCR-22) — للقراءة فقط.
class LedgerTransaction extends Equatable {
  const LedgerTransaction({
    required this.id,
    required this.type,
    required this.amount,
    this.subscriberId,
    this.subscriberName,
    this.meterId,
    this.description,
    this.createdAt,
    this.referenceType,
    this.referenceId,
  });
  final int id;

  /// payment / invoice / adjustment (مع قبول credit/debit من الخطة).
  final String type;
  final double amount;
  final int? subscriberId;
  final String? subscriberName;
  final int? meterId;
  final String? description;
  final String? createdAt;
  final String? referenceType;
  final int? referenceId;

  bool get isCredit => amount > 0;
  String get typeLabel => switch (type) {
        'payment' || 'credit' => 'سند قبض (دائن)',
        'invoice' || 'debit' => 'فاتورة (مدين)',
        'adjustment' => 'تسوية',
        _ => type,
      };
  @override
  List<Object?> get props => [id, type, amount];
}
