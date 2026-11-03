import 'package:equatable/equatable.dart';

import '../../../../core/models/record_location.dart';

enum FeeStatus { uninvoiced, invoiced, partiallyPaid, paid }

extension FeeStatusX on FeeStatus {
  String get apiValue => switch (this) {
    FeeStatus.partiallyPaid => 'partially_paid',
    _ => name,
  };
  String get label => switch (this) {
    FeeStatus.uninvoiced => 'غير مفوترة',
    FeeStatus.invoiced => 'مفوترة',
    FeeStatus.partiallyPaid => 'مسدد جزئياً',
    FeeStatus.paid => 'مسدد',
  };
  static FeeStatus fromApi(String? v) => switch (v) {
    'invoiced' => FeeStatus.invoiced,
    'partially_paid' => FeeStatus.partiallyPaid,
    'paid' => FeeStatus.paid,
    _ => FeeStatus.uninvoiced,
  };
}

class SubscriptionFee extends Equatable {
  const SubscriptionFee({
    required this.id,
    this.location = const RecordLocation(),
    required this.subscriberId,
    required this.amount,
    this.subscriberName,
    this.meterId,
    this.meterCode,
    this.status = FeeStatus.uninvoiced,
    this.invoiceId,
    this.invoiceNumber,
    this.notes,
    this.createdAt,
  });
  final RecordLocation location;
  final int id;
  final int subscriberId;
  final String? subscriberName;
  final int? meterId;
  final String? meterCode;
  final double amount;
  final FeeStatus status;
  final int? invoiceId;
  final String? invoiceNumber;
  final String? notes;
  final String? createdAt;
  bool get canInvoice => status == FeeStatus.uninvoiced;
  @override
  List<Object?> get props => [location, id, amount, status, invoiceId];
}
