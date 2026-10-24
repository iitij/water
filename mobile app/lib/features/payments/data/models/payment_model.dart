import 'package:equatable/equatable.dart';

import '../../../../core/utils/json_utils.dart';
import '../../../invoices/domain/accounting/finance_enums.dart';

/// نموذج سند القبض — يعكس `App\Models\Payment` + `receipt_data` التي يرجعها
/// `InvoiceController::pay` (سطر 138-152) و`GET /api/payments/{id}` (§2.3-49).
class PaymentModel extends Equatable {
  const PaymentModel({
    required this.id,
    required this.subscriberId,
    required this.receiptNumber,
    required this.amount,
    required this.method,
    this.meterId,
    this.invoiceId,
    this.userId,
    this.paymentDate,
    this.referenceNumber,
    this.reason,
    this.notes,
    this.createdAt,
    this.deletedAt,
    this.subscriberName,
    this.beneficiaryName,
    this.subscriberPhone,
    this.meterLabel,
    this.invoiceBillNumber,
    this.collectorName,
    this.voidedAt,
    this.voidReason,
    this.voidedBy,
    this.voidedVia,
    this.cashSessionId,
    this.whatsappSentAt,
    this.whatsappSentVia,
  });

  final int id;
  final int subscriberId;
  final int? meterId;
  final int? invoiceId;
  final int? userId;

  final String receiptNumber;
  final double amount;
  final DateTime? paymentDate;
  final PaymentMethod method;
  final String? referenceNumber;

  /// «وذلك مقابل» — يظهر في السند الرسمي (`receipt.blade.php` سطر 411).
  final String? reason;
  final String? notes;
  final String? createdAt;
  final DateTime? deletedAt;

  // إسقاطات للعرض (حتى لا نستورد ميزات زملائي).
  final String? subscriberName;
  final String? beneficiaryName;
  final String? subscriberPhone;
  final String? meterLabel;
  final String? invoiceBillNumber;
  final String? collectorName;

  /// تاريخ الإلغاء (2.0): السند الملغى يبقى في السجل ولا يُحذف.
  final DateTime? voidedAt;
  final String? voidReason;
  final String? voidedBy;
  final String? voidedVia;

  /// الصندوق الذي سُجّل فيه السند — أساس مطابقة نقد المحصّل.
  final int? cashSessionId;

  /// آخر إرسال ناجح للسند عبر واتساب (خطة 2.0 — الفصل 6.2).
  final DateTime? whatsappSentAt;

  /// `click_to_chat` / `meta_api` / `local_api` / `manual`.
  final String? whatsappSentVia;

  bool get whatsappSent => whatsappSentAt != null;

  bool get isDeleted => deletedAt != null;

  /// هل أُلغي هذا السند؟ (يبقى ظاهراً في السجل مع السبب)
  bool get isVoided => voidedAt != null;

  /// السند صالح للاعتماد في حسابات الجرد؟
  bool get isActive => !isVoided && !isDeleted;

  /// الاسم المستلم للرسالة: المستأجر أولاً ثم المشترك.
  String get displayName => (beneficiaryName?.trim().isNotEmpty ?? false)
      ? beneficiaryName!.trim()
      : (subscriberName ?? '-');

  /// هل هذا سند قبض عداد (يؤثر على `meters.balance`) أم على الحساب العام؟
  bool get affectsMeterAccount => meterId != null;

  factory PaymentModel.fromJson(Map<String, dynamic> json) {
    final subscriber = JsonUtils.asMap(json['subscriber']);
    final meter = JsonUtils.asMap(json['meter']);
    final invoice = JsonUtils.asMap(json['invoice']);
    final user = JsonUtils.asMap(json['user']);

    final tenantName = meter == null ? null : JsonUtils.asStringOrNull(meter['tenant_name']);
    final beneficiary = (tenantName?.trim().isNotEmpty ?? false)
        ? '${tenantName!.trim()} (مستأجر)'
        : JsonUtils.asStringOrNull(subscriber?['name']);

    final meterCode = meter == null ? null : JsonUtils.asStringOrNull(meter['unique_code']);
    final meterPath = meter == null ? null : JsonUtils.asIntOrNull(meter['path_number']);

    return PaymentModel(
      id: JsonUtils.asInt(json['id']),
      subscriberId: JsonUtils.asInt(json['subscriber_id']),
      meterId: JsonUtils.asIntOrNull(json['meter_id']),
      invoiceId: JsonUtils.asIntOrNull(json['invoice_id']),
      userId: JsonUtils.asIntOrNull(json['user_id']),
      receiptNumber: JsonUtils.asString(json['receipt_number']),
      amount: JsonUtils.asDouble(json['amount']),
      paymentDate: JsonUtils.asDate(json['payment_date']),
      method: PaymentMethod.parse(json['payment_method'] as String?),
      referenceNumber: JsonUtils.asStringOrNull(json['reference_number']),
      reason: JsonUtils.asStringOrNull(json['reason']),
      notes: JsonUtils.asStringOrNull(json['notes']),
      createdAt: JsonUtils.asStringOrNull(json['created_at']),
      deletedAt: JsonUtils.asDate(json['deleted_at']),
      subscriberName: JsonUtils.asStringOrNull(subscriber?['name']),
      beneficiaryName: beneficiary,
      subscriberPhone: JsonUtils.asStringOrNull(subscriber?['phone']) ??
          JsonUtils.asStringOrNull(meter?['tenant_phone']),
      meterLabel: meterCode == null
          ? null
          : (meterPath == null ? meterCode : '$meterCode / مسار $meterPath'),
      invoiceBillNumber: JsonUtils.asStringOrNull(invoice?['bill_number']),
      collectorName: JsonUtils.asStringOrNull(user?['name']),
      voidedAt: JsonUtils.asDate(json['voided_at']),
      voidReason: JsonUtils.asStringOrNull(json['void_reason']),
      voidedBy: JsonUtils.asStringOrNull(json['voided_by']),
      voidedVia: JsonUtils.asStringOrNull(json['voided_via']),
      cashSessionId: JsonUtils.asIntOrNull(json['cash_session_id']),
      whatsappSentAt: JsonUtils.asDate(json['whatsapp_sent_at']),
      whatsappSentVia: JsonUtils.asStringOrNull(json['whatsapp_sent_via']),
    );
  }

  Map<String, dynamic> toJson() => <String, dynamic>{
        'id': id,
        'subscriber_id': subscriberId,
        'meter_id': meterId,
        'invoice_id': invoiceId,
        'receipt_number': receiptNumber,
        'amount': amount,
        'payment_date': DateFormat.dateTime(paymentDate),
        'payment_method': method.wire,
        'reference_number': referenceNumber,
        'reason': reason,
        'notes': notes,
        'voided_at': DateFormat.dateTime(voidedAt),
        'void_reason': voidReason,
        'cash_session_id': cashSessionId,
        'whatsapp_sent_at': DateFormat.dateTime(whatsappSentAt),
        'whatsapp_sent_via': whatsappSentVia,
      };

  /// جسم `PUT /api/payments/{id}` — نفس حقول `PaymentResource::form`.
  Map<String, dynamic> toUpdatePayload() => <String, dynamic>{
        'subscriber_id': subscriberId,
        'invoice_id': invoiceId,
        'amount': amount,
        'payment_date': DateFormat.dateTime(paymentDate),
        'payment_method': method.wire,
        'reference_number': referenceNumber,
        'reason': reason,
        'notes': notes,
      };

  /// جسم `POST /api/payments` (§2.3-50).
  static Map<String, dynamic> createPayload({
    required int subscriberId,
    int? invoiceId,
    int? meterId,
    required double amount,
    required PaymentMethod method,
    DateTime? paymentDate,
    String? referenceNumber,
    String? reason,
    String? notes,
    String? syncUuid,
  }) =>
      <String, dynamic>{
        'subscriber_id': subscriberId,
        if (invoiceId != null) 'invoice_id': invoiceId,
        if (meterId != null) 'meter_id': meterId,
        'amount': amount,
        'payment_date': DateFormat.dateTime(paymentDate ?? DateTime.now()),
        'payment_method': method.wire,
        if (method.requiresReference) 'reference_number': referenceNumber,
        if (reason != null && reason.trim().isNotEmpty) 'reason': reason.trim(),
        if (notes != null && notes.trim().isNotEmpty) 'notes': notes.trim(),
        if (syncUuid != null && syncUuid.trim().isNotEmpty) 'sync_uuid': syncUuid.trim(),
      };

  @override
  List<Object?> get props => <Object?>[
        id,
        receiptNumber,
        amount,
        method,
        referenceNumber,
        reason,
        invoiceId,
        voidedAt,
        cashSessionId,
        whatsappSentAt,
        whatsappSentVia,
      ];
}

/// `receipt_data` التي ترجعها نقطة التحصيل — تُعرض في شاشة النجاح الفورية.
class ReceiptDataModel extends Equatable {
  const ReceiptDataModel({
    required this.receiptNumber,
    required this.amount,
    required this.date,
    required this.paymentMethod,
    required this.subscriberName,
    required this.invoiceRemaining,
    required this.outstandingBalance,
    required this.creditBalance,
    required this.collector,
    required this.pdfUrl,
    this.subscriberPhone,
    this.messageTemplate,
  });

  final String receiptNumber;
  final double amount;
  final String date;
  final String paymentMethod;
  final String subscriberName;
  final String? subscriberPhone;

  /// المتبقي من الفاتورة بعد هذه الدفعة.
  final double invoiceRemaining;

  /// إجمالي المتبقي للحساب (المشترك + كل عداداته).
  final double outstandingBalance;

  /// الرصيد الدائن الفائض (يُخصم تلقائياً من الفاتورة القادمة).
  final double creditBalance;
  final String collector;
  final String? pdfUrl;
  final String? messageTemplate;

  bool get hasSurplus => creditBalance > 0;
  bool get isFullySettled => invoiceRemaining <= 0 && outstandingBalance <= 0;

  factory ReceiptDataModel.fromPaymentJson(Map<String, dynamic> json) {
    final data = JsonUtils.asMap(json['receipt_data']) ?? json;
    return ReceiptDataModel(
      receiptNumber: JsonUtils.asString(data['receipt_number']),
      amount: JsonUtils.asDouble(data['amount']),
      date: JsonUtils.asString(data['date']),
      paymentMethod: JsonUtils.asString(data['payment_method']),
      subscriberName: JsonUtils.asString(data['subscriber_name']),
      subscriberPhone: JsonUtils.asStringOrNull(data['subscriber_phone']),
      invoiceRemaining: JsonUtils.asDouble(data['invoice_remaining']),
      outstandingBalance: JsonUtils.asDouble(data['outstanding_balance']),
      creditBalance: JsonUtils.asDouble(data['credit_balance']),
      collector: JsonUtils.asString(data['collector']),
      pdfUrl: JsonUtils.asStringOrNull(data['pdf_url']),
      messageTemplate: JsonUtils.asStringOrNull(data['message_template']),
    );
  }

  /// بناءResult مماثل من نموذج الدفع عند الرجوع من `POST /api/payments`.
  factory ReceiptDataModel.fromPayment(PaymentModel payment, {String? pdfUrl}) =>
      ReceiptDataModel(
        receiptNumber: payment.receiptNumber,
        amount: payment.amount,
        date: payment.createdAt ?? DateFormat.dateTime(DateTime.now()),
        paymentMethod: payment.method.label,
        subscriberName: payment.displayName,
        subscriberPhone: payment.subscriberPhone,
        invoiceRemaining: 0,
        outstandingBalance: 0,
        creditBalance: 0,
        collector: payment.collectorName ?? 'إدارة المشروع',
        pdfUrl: pdfUrl,
      );

  @override
  List<Object?> get props => <Object?>[
        receiptNumber,
        amount,
        invoiceRemaining,
        outstandingBalance,
        creditBalance,
      ];
}

/// فلاتر قائمة سندات القبض (§2.3-48).
class PaymentQuery extends Equatable {
  const PaymentQuery({
    this.search = '',
    this.method,
    this.subscriberId,
    this.invoiceId,
    this.fromDate,
    this.toDate,
    this.limit,
  });

  final String search;
  final String? method;
  final int? subscriberId;
  final int? invoiceId;
  final DateTime? fromDate;
  final DateTime? toDate;
  final int? limit;

  Map<String, dynamic> toQueryMap() => <String, dynamic>{
        if (search.trim().isNotEmpty) 'query': search.trim(),
        if (search.trim().isNotEmpty) 'search': search.trim(),
        if (method != null) 'payment_method': method,
        if (subscriberId != null) 'subscriber_id': subscriberId,
        if (invoiceId != null) 'invoice_id': invoiceId,
        if (fromDate != null) 'from_date': DateFormat.day(fromDate),
        if (toDate != null) 'to_date': DateFormat.day(toDate),
        if (limit != null) 'per_page': limit,
      };

  String get signature => <String>[
        search,
        '$method',
        '$subscriberId',
        '$fromDate',
        '$toDate',
      ].join('§');

  PaymentQuery copyWith({
    String? search,
    String? method,
    int? subscriberId,
    DateTime? fromDate,
    DateTime? toDate,
    bool clearFilters = false,
  }) =>
      PaymentQuery(
        search: search ?? this.search,
        method: clearFilters ? null : (method ?? this.method),
        subscriberId: clearFilters ? null : (subscriberId ?? this.subscriberId),
        invoiceId: invoiceId,
        fromDate: clearFilters ? null : (fromDate ?? this.fromDate),
        toDate: clearFilters ? null : (toDate ?? this.toDate),
        limit: limit,
      );

  @override
  List<Object?> get props =>
      <Object?>[search, method, subscriberId, invoiceId, fromDate, toDate];
}

/// نتيجة طلب إلغاء سند: قد يُلغى فوراً، أو ينتظر اعتماد مشرف إذا تجاوز الحد
/// المالي المحدد في إعدادات الخادم (خطة 2.0 — الفصل 5.3).
class VoidPaymentOutcome extends Equatable {
  const VoidPaymentOutcome({
    this.payment,
    this.pendingApproval = false,
    this.queuedOffline = false,
  });

  /// السند بعد التنفيذ؛ `null` عندما حُفظ الطلب محلياً بلا شبكة.
  final PaymentModel? payment;

  /// `true` يعني: السند ما زال صالحاً وطلب الإلغاء بانتظار المشرف.
  final bool pendingApproval;

  /// `true` يعني: انقطعت الشبكة فحُفظ طلب الإلغاء في طابور المزامنة.
  final bool queuedOffline;

  @override
  List<Object?> get props => <Object?>[payment, pendingApproval, queuedOffline];
}

/// طلب إلغاء سند معلّق بانتظار قرار المشرف (`GET /payments/void-requests` —
/// خطة 2.0 — الفصل 5.3). كان هذا المسار غير قابل للوصول من التطبيق نهائياً.
class PaymentVoidRequest extends Equatable {
  const PaymentVoidRequest({
    required this.id,
    required this.receiptNumber,
    required this.amount,
    this.subscriber,
    this.reason,
    this.requestedBy,
    this.requestedAt,
    this.paymentDate,
  });

  final int id;
  final String receiptNumber;
  final double amount;
  final String? subscriber;
  final String? reason;
  final String? requestedBy;
  final DateTime? requestedAt;
  final DateTime? paymentDate;

  factory PaymentVoidRequest.fromJson(Map<String, dynamic> json) =>
      PaymentVoidRequest(
        id: JsonUtils.asInt(json['id']),
        receiptNumber: JsonUtils.asString(json['receipt_number']),
        amount: JsonUtils.asDouble(json['amount']),
        subscriber: JsonUtils.asStringOrNull(json['subscriber']),
        reason: JsonUtils.asStringOrNull(json['reason']),
        requestedBy: JsonUtils.asStringOrNull(json['requested_by']),
        requestedAt: JsonUtils.asDate(json['requested_at']),
        paymentDate: JsonUtils.asDate(json['payment_date']),
      );

  @override
  List<Object?> get props => <Object?>[
        id,
        receiptNumber,
        amount,
        subscriber,
        reason,
        requestedBy,
        requestedAt,
        paymentDate,
      ];
}
