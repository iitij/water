import 'package:equatable/equatable.dart';

import '../../../../core/models/record_location.dart';

/// قيد دفتر أستاذ عام (SCR-22) — للقراءة فقط.
class LedgerTransaction extends Equatable {
  const LedgerTransaction({
    required this.id,
    this.location = const RecordLocation(),
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
  final RecordLocation location;
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
    'payment_reversal' => 'عكس سند قبض',
    'invoice_reversal' => 'عكس فاتورة',
    'payment_transfer_in' => 'تصحيح تخصيص وارد',
    'payment_refund' => 'استرداد رصيد دائن',
    _ => type,
  };
  @override
  List<Object?> get props => [location, id, type, amount];
}
