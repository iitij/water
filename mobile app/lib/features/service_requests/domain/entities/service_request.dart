import 'package:equatable/equatable.dart';

enum TankerStatus { pending, completed, invoiced, paid }

extension TankerStatusX on TankerStatus {
  String get apiValue => name;
  String get label => switch (this) {
        TankerStatus.pending => 'معلق',
        TankerStatus.completed => 'مكتمل',
        TankerStatus.invoiced => 'مفوتر',
        TankerStatus.paid => 'مسدد',
      };
  static TankerStatus fromApi(String? v) => switch (v) {
        'completed' => TankerStatus.completed,
        'invoiced' => TankerStatus.invoiced,
        'paid' => TankerStatus.paid,
        _ => TankerStatus.pending,
      };
}

class ServiceRequest extends Equatable {
  const ServiceRequest({
    required this.id,
    required this.subscriberId,
    required this.amount,
    required this.serviceDate,
    this.subscriberName,
    this.status = TankerStatus.pending,
    this.invoiceId,
    this.invoiceNumber,
    this.notes,
  });
  final int id;
  final int subscriberId;
  final String? subscriberName;
  final double amount;
  final String serviceDate;
  final TankerStatus status;
  final int? invoiceId;
  final String? invoiceNumber;
  final String? notes;

  bool get hasNote => notes != null && notes!.trim().isNotEmpty;
  bool get canComplete => status == TankerStatus.pending;
  bool get canInvoice => status == TankerStatus.completed;
  @override
  List<Object?> get props => [id, amount, status, invoiceId];
}
