import 'package:equatable/equatable.dart';

import '../../../invoices/data/models/invoice_model.dart';
import '../../../invoices/domain/accounting/finance_enums.dart';
import '../../data/models/collect_payment_models.dart';
import '../../domain/accounting/payment_allocator.dart';

/// حالة نافذة/شاشة تسجيل الدفعة (`SCR-18` + `CollectPaymentSheet` المشتركة).
class CollectPaymentState extends Equatable {
  const CollectPaymentState({
    this.subscriberId,
    this.subscriberName,
    this.invoiceId,
    this.openInvoices = const <InvoiceModel>[],
    this.dueAmount = 0,
    this.amount = 0,
    this.method = PaymentMethod.cash,
    this.referenceNumber = '',
    this.reason = '',
    this.notes = '',
    this.paymentDate,
    this.loadingInvoices = false,
    this.submitting = false,
    this.outcome,
    this.validationMessage,
  });

  final int? subscriberId;
  final String? subscriberName;
  final int? invoiceId;

  /// فواتير الحساب المعزول المفتوحة (FIFO معاينة).
  final List<InvoiceModel> openInvoices;

  /// المستحق الحي للفاتورة المحددة، دون تكرار المتأخرات التاريخية.
  final double dueAmount;
  final double amount;
  final PaymentMethod method;
  final String referenceNumber;
  final String reason;
  final String notes;
  final DateTime? paymentDate;

  final bool loadingInvoices;
  final bool submitting;
  final CollectPaymentOutcome? outcome;
  final String? validationMessage;

  bool get isSubmitted => outcome?.isSuccess ?? false;
  bool get hasInvoice => invoiceId != null;

  /// هل يُطلب رقم الحوالة/الشيك؟ (قاعدة `InvoiceResource#L402-405`).
  bool get requiresReference => method.requiresReference;

  double get remainingAfterPayment {
    final rest = dueAmount - amount;
    return rest > 0 ? double.parse(rest.toStringAsFixed(2)) : 0;
  }

  double get surplus => amount - dueAmount > 0
      ? double.parse((amount - dueAmount).toStringAsFixed(2))
      : 0;

  /// توزيع الدفعة على الفواتير (معاينة محلية لنفس خوارزمية الخادم).
  AllocationResult get previewAllocation => PaymentAllocator.allocate(
        invoices: openInvoices,
        amount: amount,
        targetInvoiceId: invoiceId,
      );

  CollectPaymentRequest toRequest({required int subscriberId}) => CollectPaymentRequest(
        subscriberId: subscriberId,
        invoiceId: invoiceId,
        amount: amount,
        method: method,
        referenceNumber: referenceNumber.trim().isEmpty ? null : referenceNumber.trim(),
        reason: reason.trim().isEmpty ? null : reason.trim(),
        notes: notes.trim().isEmpty ? null : notes.trim(),
        paymentDate: paymentDate ?? DateTime.now(),
      );

  CollectPaymentState copyWith({
    int? subscriberId,
    String? subscriberName,
    int? invoiceId,
    bool clearInvoice = false,
    List<InvoiceModel>? openInvoices,
    double? dueAmount,
    double? amount,
    PaymentMethod? method,
    String? referenceNumber,
    String? reason,
    String? notes,
    DateTime? paymentDate,
    bool? loadingInvoices,
    bool? submitting,
    CollectPaymentOutcome? outcome,
    String? validationMessage,
    bool clearValidation = false,
  }) =>
      CollectPaymentState(
        subscriberId: subscriberId ?? this.subscriberId,
        subscriberName: subscriberName ?? this.subscriberName,
        invoiceId: clearInvoice ? null : (invoiceId ?? this.invoiceId),
        openInvoices: openInvoices ?? this.openInvoices,
        dueAmount: dueAmount ?? this.dueAmount,
        amount: amount ?? this.amount,
        method: method ?? this.method,
        referenceNumber: referenceNumber ?? this.referenceNumber,
        reason: reason ?? this.reason,
        notes: notes ?? this.notes,
        paymentDate: paymentDate ?? this.paymentDate,
        loadingInvoices: loadingInvoices ?? this.loadingInvoices,
        submitting: submitting ?? this.submitting,
        outcome: outcome ?? this.outcome,
        validationMessage:
            clearValidation ? null : (validationMessage ?? this.validationMessage),
      );

  @override
  List<Object?> get props => <Object?>[
        subscriberId,
        invoiceId,
        dueAmount,
        amount,
        method,
        referenceNumber,
        reason,
        notes,
        paymentDate,
        loadingInvoices,
        submitting,
        outcome,
        validationMessage,
      ];
}
