import 'package:equatable/equatable.dart';
import '../../../../core/utils/json_utils.dart';

class AllocationSnapshot extends Equatable {
  const AllocationSnapshot({
    required this.available, required this.subscriberId,
    this.integrityValid,
    this.revision, this.recordedAt, this.allocatedMinor,
    this.unallocatedMinor, this.rows = const [],
  });

  final bool available;
  final bool? integrityValid;
  final int subscriberId;
  final int? revision;
  final String? recordedAt;
  final int? allocatedMinor;
  final int? unallocatedMinor;
  final List<AllocationRow> rows;

  factory AllocationSnapshot.fromJson(Map<String, dynamic> json) => AllocationSnapshot(
    available: json['available'] == true,
    integrityValid: json['integrity_valid'] is bool ? json['integrity_valid'] as bool : null,
    subscriberId: JsonUtils.asInt(json['subscriber_id']),
    revision: JsonUtils.asIntOrNull(json['revision']),
    recordedAt: JsonUtils.asStringOrNull(json['recorded_at']),
    allocatedMinor: JsonUtils.asIntOrNull(json['allocated_minor']),
    unallocatedMinor: JsonUtils.asIntOrNull(json['unallocated_minor']),
    rows: (json['rows'] is List ? json['rows'] as List<dynamic> : const <dynamic>[])
        .whereType<Map>().map((r) => AllocationRow.fromJson(Map<String, dynamic>.from(r))).toList(),
  );

  Map<String, dynamic> toJson() => {
    'available': available, 'integrity_valid': integrityValid, 'subscriber_id': subscriberId, 'revision': revision,
    'recorded_at': recordedAt, 'allocated_minor': allocatedMinor,
    'unallocated_minor': unallocatedMinor, 'rows': rows.map((r) => r.toJson()).toList(),
  };

  @override
  List<Object?> get props => [available, integrityValid, subscriberId, revision, recordedAt, allocatedMinor, unallocatedMinor, rows];
}

class AllocationRow extends Equatable {
  const AllocationRow({this.paymentId, this.accountTransferId, this.invoiceId,
    this.billNumber, this.receiptNumber, required this.amountMinor});
  final int? paymentId;
  final int? accountTransferId;
  final int? invoiceId;
  final String? billNumber;
  final String? receiptNumber;
  final int amountMinor;

  factory AllocationRow.fromJson(Map<String, dynamic> json) => AllocationRow(
    paymentId: JsonUtils.asIntOrNull(json['payment_id']),
    accountTransferId: JsonUtils.asIntOrNull(json['account_transfer_id']),
    invoiceId: JsonUtils.asIntOrNull(json['invoice_id']),
    billNumber: JsonUtils.asStringOrNull(json['bill_number']),
    receiptNumber: JsonUtils.asStringOrNull(json['receipt_number']),
    amountMinor: JsonUtils.asInt(json['amount_minor']),
  );

  Map<String, dynamic> toJson() => {'payment_id': paymentId, 'account_transfer_id': accountTransferId,
    'invoice_id': invoiceId, 'bill_number': billNumber, 'receipt_number': receiptNumber, 'amount_minor': amountMinor};

  @override
  List<Object?> get props => [paymentId, accountTransferId, invoiceId, billNumber, receiptNumber, amountMinor];
}
