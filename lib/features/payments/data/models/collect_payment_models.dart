import 'package:equatable/equatable.dart';

import 'payment_model.dart';

/// ناتج عملية تحصيل (سواء نُفّذ فوراً أو أُدرج في طابور المزامنة الأوفلاين).
class CollectPaymentOutcome extends Equatable {
  const CollectPaymentOutcome({
    this.payment,
    this.receiptData,
    this.message,
    this.invoiceStatus,
    this.invoiceTotalAmount,
    this.invoiceAmountPaid,
    this.queuedOffline = false,
  });

  final PaymentModel? payment;
  final ReceiptDataModel? receiptData;
  final String? message;
  final String? invoiceStatus;
  final double? invoiceTotalAmount;
  final double? invoiceAmountPaid;

  /// `true` = حُفظ محلياً في `pending_sync_actions` بانتظار عودة الشبكة.
  final bool queuedOffline;

  bool get isSuccess => payment != null || queuedOffline;

  String get referenceNumber => payment?.receiptNumber ?? '(معلّق حتى المزامنة)';

  @override
  List<Object?> get props => <Object?>[
        payment,
        receiptData,
        message,
        invoiceStatus,
        queuedOffline,
      ];
}

/// طلب التحصيل الموحّد بين `SCR-13` (لمسة واحدة) و`SCR-15` (تفاصيل الفاتورة)
/// و`SCR-18` (نموذج السند).
class CollectPaymentRequest extends Equatable {
  const CollectPaymentRequest({
    required this.subscriberId,
    required this.amount,
    required this.method,
    this.invoiceId,
    this.referenceNumber,
    this.reason,
    this.notes,
    this.paymentDate,
  });

  final int subscriberId;
  final int? invoiceId;
  final double amount;
  final PaymentMethod method;
  final String? referenceNumber;
  final String? reason;
  final String? notes;
  final DateTime? paymentDate;

  /// جسم `POST /api/invoices/{id}/pay` (`InvoiceController::pay` سطر 96-104).
  Map<String, dynamic> toPayPayload() => <String, dynamic>{
        'amount': amount,
        'payment_method': method.wire,
        if (method.requiresReference) 'reference_number': referenceNumber,
        if (notes != null && notes!.trim().isNotEmpty) 'notes': notes!.trim(),
        if (reason != null && reason!.trim().isNotEmpty) 'reason': reason!.trim(),
      };

  /// جسم `POST /api/payments` (§2.3-50).
  Map<String, dynamic> toCreatePayload() => PaymentModel.createPayload(
        subscriberId: subscriberId,
        invoiceId: invoiceId,
        amount: amount,
        method: method,
        paymentDate: paymentDate,
        referenceNumber: referenceNumber,
        reason: reason,
        notes: notes,
      );

  /// التحقق المحلي قبل الإرسال — يطابق `PaymentResource::form` والقاعدة الشرطية
  /// في `InvoiceResource#L402-405` (إلزامية `reference_number`).
  String? validate() {
    if (amount <= 0) return 'أدخل مبلغاً أكبر من صفر.';
    if (method.requiresReference && (referenceNumber?.trim().isEmpty ?? true)) {
      return method == PaymentMethod.check
          ? 'رقم الشيك مطلوب عند اختيار طريقة الدفع «شيك».'
          : 'رقم الحوالة مطلوب عند اختيار طريقة الدفع «تحويل بنكي».';
    }
    return null;
  }

  CollectPaymentRequest copyWith({
    double? amount,
    PaymentMethod? method,
    String? referenceNumber,
    String? reason,
    String? notes,
    DateTime? paymentDate,
    int? invoiceId,
  }) =>
      CollectPaymentRequest(
        subscriberId: subscriberId,
        invoiceId: invoiceId ?? this.invoiceId,
        amount: amount ?? this.amount,
        method: method ?? this.method,
        referenceNumber: referenceNumber ?? this.referenceNumber,
        reason: reason ?? this.reason,
        notes: notes ?? this.notes,
        paymentDate: paymentDate ?? this.paymentDate,
      );

  @override
  List<Object?> get props => <Object?>[
        subscriberId,
        invoiceId,
        amount,
        method,
        referenceNumber,
        reason,
        notes,
        paymentDate,
      ];
}
