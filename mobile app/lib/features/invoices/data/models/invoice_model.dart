import 'dart:convert';

import 'package:equatable/equatable.dart';

import '../../../../core/models/record_location.dart';
import '../../../../core/utils/json_utils.dart';
import '../../domain/accounting/finance_enums.dart';
import '../../../payments/data/models/allocation_snapshot.dart';

/// نموذج الفاتورة — يعكس `App\Models\Invoice` + الحقول المحسوبة التي يرسلها
/// `InvoiceController` (`true_remaining_amount`, `is_path_match`) و`is_latest_invoice`
/// المطلوبة في §2.3-36.
///
/// تُستخدم النماذج كنماذج مجال (Model-as-Entity) بدل `freezed` لتفادي توليد الكود
/// في فروع الفريق المتوازية — انظر `docs/handoff/DEV3_FINANCE_MODULE.md`.
class InvoiceModel extends Equatable {
  const InvoiceModel({
    required this.id,
    this.location = const RecordLocation(),
    required this.subscriberId,
    required this.invoiceType,
    required this.billNumber,
    this.meterId,
    this.retiredMeterId,
    this.retiredMeterCode,
    this.correctedUnits,
    this.historicalUnitPrice,
    this.originalInvoiceId,
    this.correctedByInvoiceId,
    this.billDate,
    this.periodStart,
    this.periodEnd,
    this.consumptionFee = 0,
    this.previousBalance = 0,
    this.creditApplied = 0,
    this.totalAmount = 0,
    this.amountPaid = 0,
    this.status = InvoiceStatus.issued,
    this.notes,
    this.createdAt,
    this.deletedAt,
    this.subscriber,
    this.meter,
    this.items = const <InvoiceItemModel>[],
    this.readings = const <InvoiceReadingModel>[],
    this.payments = const <InvoicePaymentRecord>[],
    this.serverTrueRemaining,
    this.allocationSnapshot,
    this.isLatestInvoice,
    this.isPathMatch = false,
  });

  final RecordLocation location;
  final int id;
  final int subscriberId;
  final int? meterId;
  final int? retiredMeterId;
  final String? retiredMeterCode;
  final double? correctedUnits;
  final double? historicalUnitPrice;
  final int? originalInvoiceId;
  final int? correctedByInvoiceId;
  final InvoiceType invoiceType;
  final String billNumber;
  final DateTime? billDate;
  final DateTime? periodStart;
  final DateTime? periodEnd;

  final double consumptionFee;
  final double previousBalance;
  final double creditApplied;
  final double totalAmount;
  final double amountPaid;

  final InvoiceStatus status;
  final String? notes;
  final DateTime? createdAt;
  final DateTime? deletedAt;

  final InvoiceSubscriberRef? subscriber;
  final InvoiceMeterRef? meter;

  final List<InvoiceItemModel> items;
  final List<InvoiceReadingModel> readings;
  final List<InvoicePaymentRecord> payments;

  /// `true_remaining_amount` كما حسبه الخادم (الأدق). `null` = غير مُرسل.
  final double? serverTrueRemaining;
  final AllocationSnapshot? allocationSnapshot;

  /// `is_latest_invoice` للحساب المعزول. `null` = غير مُرسل (يُحسب محلياً).
  final bool? isLatestInvoice;

  /// رتبة البحث المركّب من `InvoiceController::search`.
  final bool isPathMatch;

  bool get isDeleted => deletedAt != null;

  /// The subscriber owns the current meter and receives billing notices.
  /// Historical invoice meter references never change the recipient.
  String get beneficiaryName => subscriber?.name ?? '-';

  String get messageRecipientName => subscriber?.name ?? '';

  String? get messageRecipientPhone => subscriber?.phone;

  /// `total_amount + previous_balance` (`total_due` في Filament).
  double get totalDue => totalAmount + previousBalance;

  /// الصافي المطلوب لهذه الفاتورة: `(total_amount + previous_balance) - credit_applied`
  double get netPayable {
    final value = totalDue - creditApplied;
    return value < 0 ? 0 : value;
  }

  /// متبقي الفاتورة نفسها فقط — `Invoice::getRemainingAmount()`.
  double get ownRemaining {
    if (isDeleted || status == InvoiceStatus.draft || status == InvoiceStatus.cancelled) return 0;
    final value = totalAmount - amountPaid;
    return value < 0 ? 0 : value;
  }

  /// Live collectible balance; issued totals already include applied credit.
  /// Older invoices must never collect the cumulative debt a second time.
  double get payableAmount {
    if (isDeleted ||
        status == InvoiceStatus.draft ||
        status == InvoiceStatus.cancelled) {
      return 0;
    }
    final value = isLatestInvoice != false && serverTrueRemaining != null
        ? serverTrueRemaining!
        : ownRemaining;
    return value < 0 ? 0 : value;
  }

  bool get canCollect => payableAmount > 0;

  /// `true` عندما يقرر الخادم أن الفاتورة متأخرة (يُحدَّث عبر
  /// `Invoice::updateStatus()` مع `grace_period`) — العرض فقط، لا إعادة احتساب.
  bool get isOverdue => status == InvoiceStatus.overdue;

  double get consumptionUnits =>
      correctedUnits ?? readings.fold<double>(0, (sum, r) => sum + r.consumptionUnits);

  /// سعر الوحدة الضمني (مطابق لمنطق `WhatsAppService#L120`).
  double? get impliedUnitPrice {
    if (historicalUnitPrice != null) return historicalUnitPrice;
    final units = consumptionUnits;
    if (units > 0 && consumptionFee > 0) {
      return double.parse((consumptionFee / units).toStringAsFixed(2));
    }
    return null;
  }

  factory InvoiceModel.fromJson(Map<String, dynamic> json) {
    final items = JsonUtils.asMapList(
      json['items'],
    ).map(InvoiceItemModel.fromJson).toList(growable: false);
    final readings = JsonUtils.asMapList(
      json['readings'],
    ).map(InvoiceReadingModel.fromJson).toList(growable: false);
    final payments = JsonUtils.asMapList(
      json['payments'],
    ).map(InvoicePaymentRecord.fromJson).toList(growable: false);

    final correction = JsonUtils.asMap(json['historical_correction']);
    return InvoiceModel(
      id: JsonUtils.asInt(json['id']),
      location: RecordLocation.fromJson(json),
      subscriberId: JsonUtils.asInt(json['subscriber_id']),
      meterId: JsonUtils.asIntOrNull(json['meter_id']),
      retiredMeterId: JsonUtils.asIntOrNull(json['retired_meter_id']),
      retiredMeterCode: JsonUtils.asStringOrNull(json['retired_meter_code']),
      correctedUnits: JsonUtils.asDoubleOrNull(correction?['corrected_units']),
      historicalUnitPrice: JsonUtils.asDoubleOrNull(correction?['historical_unit_price']),
      originalInvoiceId: JsonUtils.asIntOrNull(correction?['original_invoice_id']),
      correctedByInvoiceId: JsonUtils.asIntOrNull(json['corrected_by_invoice_id']),
      invoiceType: InvoiceType.parse(json['invoice_type'] as String?),
      billNumber: JsonUtils.asString(json['bill_number']),
      billDate: JsonUtils.asDate(json['bill_date']),
      periodStart: JsonUtils.asDate(json['billing_period_start']),
      periodEnd: JsonUtils.asDate(json['billing_period_end']),
      consumptionFee: JsonUtils.asDouble(json['consumption_fee']),
      previousBalance: JsonUtils.asDouble(json['previous_balance']),
      creditApplied: JsonUtils.asDouble(json['credit_applied']),
      totalAmount: JsonUtils.asDouble(json['total_amount']),
      amountPaid: JsonUtils.asDouble(json['amount_paid']),
      status: InvoiceStatus.parse(json['status'] as String?),
      notes: JsonUtils.asStringOrNull(json['notes']),
      createdAt: JsonUtils.asDate(json['created_at']),
      deletedAt: JsonUtils.asDate(json['deleted_at']),
      subscriber: json['subscriber'] is Map
          ? InvoiceSubscriberRef.fromJson(
              (json['subscriber'] as Map).cast<String, dynamic>(),
            )
          : null,
      meter: json['meter'] is Map
          ? InvoiceMeterRef.fromJson(
              (json['meter'] as Map).cast<String, dynamic>(),
            )
          : null,
      items: items,
      readings: readings,
      payments: payments,
      allocationSnapshot: JsonUtils.asMap(json['allocation_snapshot']) == null ? null : AllocationSnapshot.fromJson(JsonUtils.asMap(json['allocation_snapshot'])!),
      serverTrueRemaining: JsonUtils.asDoubleOrNull(
        json['true_remaining_amount'],
      ),
      isLatestInvoice: json['is_latest_invoice'] == null
          ? null
          : JsonUtils.asBool(json['is_latest_invoice']),
      isPathMatch: JsonUtils.asBool(json['is_path_match']),
    );
  }

  Map<String, dynamic> toJson() => <String, dynamic>{
    'id': id,
    'subscriber_id': subscriberId,
    'meter_id': meterId,
    'retired_meter_id': retiredMeterId,
    'retired_meter_code': retiredMeterCode,
    'corrected_by_invoice_id': correctedByInvoiceId,
    if (originalInvoiceId != null) 'historical_correction': {
      'original_invoice_id': originalInvoiceId,
      'corrected_units': correctedUnits,
      'historical_unit_price': historicalUnitPrice,
    },
    'invoice_type': invoiceType.wire,
    'bill_number': billNumber,
    'bill_date': DateFormat.day(billDate),
    'billing_period_start': DateFormat.day(periodStart),
    'billing_period_end': DateFormat.day(periodEnd),
    'consumption_fee': consumptionFee,
    'previous_balance': previousBalance,
    'credit_applied': creditApplied,
    'total_amount': totalAmount,
    'amount_paid': amountPaid,
    'status': status.wire,
    'notes': notes,
    'true_remaining_amount': serverTrueRemaining,
    if (allocationSnapshot != null) 'allocation_snapshot': allocationSnapshot!.toJson(),
    'is_latest_invoice': isLatestInvoice,
  };

  /// جسم الطلب لـ `PUT /api/invoices/{id}` (الإنشاء اليدوي يعادل `CreateInvoice`).
  Map<String, dynamic> toUpdatePayload() => <String, dynamic>{
    'subscriber_id': subscriberId,
    'bill_date': DateFormat.day(billDate),
    'status': status.wire,
    'consumption_fee': consumptionFee,
    'previous_balance': previousBalance,
    'credit_applied': creditApplied,
    'total_amount': totalAmount,
    'notes': notes,
  };

  InvoiceModel copyWith({
    InvoiceStatus? status,
    double? amountPaid,
    double? totalAmount,
    double? previousBalance,
    double? creditApplied,
    double? consumptionFee,
    String? notes,
    DateTime? billDate,
    bool? isLatestInvoice,
    double? serverTrueRemaining,
    List<InvoiceItemModel>? items,
    List<InvoiceReadingModel>? readings,
    List<InvoicePaymentRecord>? payments,
    InvoiceSubscriberRef? subscriber,
    InvoiceMeterRef? meter,
  }) => InvoiceModel(
    id: id,
    subscriberId: subscriberId,
    meterId: meterId,
    retiredMeterId: retiredMeterId,
    retiredMeterCode: retiredMeterCode,
    correctedUnits: correctedUnits,
    historicalUnitPrice: historicalUnitPrice,
    originalInvoiceId: originalInvoiceId,
    correctedByInvoiceId: correctedByInvoiceId,
    invoiceType: invoiceType,
    billNumber: billNumber,
    billDate: billDate ?? this.billDate,
    periodStart: periodStart,
    periodEnd: periodEnd,
    consumptionFee: consumptionFee ?? this.consumptionFee,
    previousBalance: previousBalance ?? this.previousBalance,
    creditApplied: creditApplied ?? this.creditApplied,
    totalAmount: totalAmount ?? this.totalAmount,
    amountPaid: amountPaid ?? this.amountPaid,
    status: status ?? this.status,
    notes: notes ?? this.notes,
    location: location,
    createdAt: createdAt,
    deletedAt: deletedAt,
    subscriber: subscriber ?? this.subscriber,
    meter: meter ?? this.meter,
    items: items ?? this.items,
    readings: readings ?? this.readings,
    payments: payments ?? this.payments,
    serverTrueRemaining: serverTrueRemaining ?? this.serverTrueRemaining,
    allocationSnapshot: amountPaid == null && status == null ? allocationSnapshot : null,
    isLatestInvoice: isLatestInvoice ?? this.isLatestInvoice,
    isPathMatch: isPathMatch,
  );

  @override
  List<Object?> get props => <Object?>[
    location,
    id,
    billNumber,
    status,
    totalAmount,
    amountPaid,
    previousBalance,
    creditApplied,
    serverTrueRemaining,
    allocationSnapshot,
    isLatestInvoice,
    retiredMeterId,
    retiredMeterCode,
    correctedUnits,
    historicalUnitPrice,
    originalInvoiceId,
    correctedByInvoiceId,
  ];
}

/// بنود الفاتورة (`invoice_items`) — تُنشأ في `InvoiceService::generateInvoice` سطر 165-205.
class InvoiceItemModel extends Equatable {
  const InvoiceItemModel({
    required this.id,
    required this.type,
    required this.description,
    required this.amount,
  });

  final int id;
  final InvoiceItemType type;
  final String description;
  final double amount;

  factory InvoiceItemModel.fromJson(Map<String, dynamic> json) =>
      InvoiceItemModel(
        id: JsonUtils.asInt(json['id']),
        type: InvoiceItemType.parse(json['type'] as String?),
        description: JsonUtils.asString(json['description']),
        amount: JsonUtils.asDouble(json['amount']),
      );

  Map<String, dynamic> toJson() => <String, dynamic>{
    'id': id,
    'type': type.wire,
    'description': description,
    'amount': amount,
  };

  @override
  List<Object?> get props => <Object?>[id, type, description, amount];
}

/// القراءة المرتبطة بالفاتورة (عرض فقط — المالك الفعلي Dev 2).
class InvoiceReadingModel extends Equatable {
  const InvoiceReadingModel({
    required this.id,
    required this.previousReading,
    required this.currentReading,
    required this.consumptionUnits,
    this.readingDate,
    this.periodStart,
    this.periodEnd,
  });

  final int id;
  final double previousReading;
  final double currentReading;
  final double consumptionUnits;
  final DateTime? readingDate;
  final DateTime? periodStart;
  final DateTime? periodEnd;

  factory InvoiceReadingModel.fromJson(Map<String, dynamic> json) =>
      InvoiceReadingModel(
        id: JsonUtils.asInt(json['id']),
        previousReading: JsonUtils.asDouble(json['previous_reading']),
        currentReading: JsonUtils.asDouble(json['current_reading']),
        consumptionUnits: JsonUtils.asDouble(json['consumption_units']),
        readingDate: JsonUtils.asDate(json['reading_date']),
        periodStart: JsonUtils.asDate(json['period_start']),
        periodEnd: JsonUtils.asDate(json['period_end']),
      );

  @override
  List<Object?> get props => <Object?>[
    id,
    previousReading,
    currentReading,
    consumptionUnits,
  ];
}

/// سجل دفعة مرتبطة بالفاتورة (يظهر في `SCR-15`).
class InvoicePaymentRecord extends Equatable {
  const InvoicePaymentRecord({
    required this.id,
    required this.receiptNumber,
    required this.amount,
    required this.method,
    this.paymentDate,
    this.referenceNumber,
    this.collectorName,
  });

  final int id;
  final String receiptNumber;
  final double amount;
  final PaymentMethod method;
  final DateTime? paymentDate;
  final String? referenceNumber;
  final String? collectorName;

  factory InvoicePaymentRecord.fromJson(Map<String, dynamic> json) {
    final user = json['user'];
    return InvoicePaymentRecord(
      id: JsonUtils.asInt(json['id']),
      receiptNumber: JsonUtils.asString(json['receipt_number']),
      amount: JsonUtils.asDouble(json['amount']),
      method: PaymentMethod.parse(json['payment_method'] as String?),
      paymentDate: JsonUtils.asDate(json['payment_date']),
      referenceNumber: JsonUtils.asStringOrNull(json['reference_number']),
      collectorName: user is Map
          ? JsonUtils.asStringOrNull(user['name'])
          : null,
    );
  }

  @override
  List<Object?> get props => <Object?>[id, receiptNumber, amount, method];
}

/// إسقاط مختصر للمشترك (الحقول التي تحتاجها شاشات الفواتير فقط).
class InvoiceSubscriberRef extends Equatable {
  const InvoiceSubscriberRef({
    required this.id,
    required this.name,
    this.phone,
    this.squareId,
    this.squareName,
    this.balance = 0,
    this.status,
  });

  final int id;
  final String name;
  final String? phone;
  final int? squareId;
  final String? squareName;

  /// الرصيد العام للمشترك (`subscribers.balance`): موجب = دائن، سالب = مدين.
  final double balance;
  final String? status;

  double get credit => balance > 0 ? balance : 0;
  double get debit => balance < 0 ? -balance : 0;

  factory InvoiceSubscriberRef.fromJson(Map<String, dynamic> json) {
    final square = json['square'];
    return InvoiceSubscriberRef(
      id: JsonUtils.asInt(json['id']),
      name: JsonUtils.asString(json['name'], fallback: '-'),
      phone: JsonUtils.asStringOrNull(json['phone']),
      squareId: JsonUtils.asIntOrNull(json['square_id']),
      squareName: square is Map
          ? JsonUtils.asStringOrNull(square['name'])
          : null,
      balance: JsonUtils.asDouble(json['balance']),
      status: JsonUtils.asStringOrNull(json['status']),
    );
  }

  @override
  List<Object?> get props => <Object?>[id, name, phone, squareName, balance];
}

/// بيانات العداد المرتبط بالفاتورة، بما فيها السجلات التاريخية.
class InvoiceMeterRef extends Equatable {
  const InvoiceMeterRef({
    required this.id,
    this.uniqueCode,
    this.pathNumber,
    this.balance = 0,
    this.status,
  });

  final int id;
  final String? uniqueCode;
  final int? pathNumber;

  /// `meters.balance` — دفتر العداد المعزول.
  final double balance;
  final String? status;

  double get credit => balance > 0 ? balance : 0;
  double get debit => balance < 0 ? -balance : 0;

  String get label {
    final code = uniqueCode ?? '#$id';
    return pathNumber == null ? code : '$code / مسار $pathNumber';
  }

  factory InvoiceMeterRef.fromJson(Map<String, dynamic> json) =>
      InvoiceMeterRef(
        id: JsonUtils.asInt(json['id']),
        uniqueCode: JsonUtils.asStringOrNull(json['unique_code']),
        pathNumber: JsonUtils.asIntOrNull(json['path_number']),
        balance: JsonUtils.asDouble(json['balance']),
        status: JsonUtils.asStringOrNull(json['status']),
      );

  @override
  List<Object?> get props => <Object?>[
    id,
    uniqueCode,
    pathNumber,
    balance,
  ];
}

/// فترة فوترة مميزة (لملء فلتر `billing_period`) — `GET /api/invoices/billing-periods`.
class BillingPeriodOption extends Equatable {
  const BillingPeriodOption({
    required this.start,
    required this.end,
    this.invoiceCount = 0,
  });

  final DateTime start;
  final DateTime end;
  final int invoiceCount;

  /// الصيغة المطلوبة في الـ API: `YYYY-MM-DD|YYYY-MM-DD` (§2.3-33).
  String get wireValue => '${DateFormat.day(start)}|${DateFormat.day(end)}';

  String get label =>
      'من: ${DateFormat.day(start)} إلى: ${DateFormat.day(end)}'
      '${invoiceCount > 0 ? ' ($invoiceCount)' : ''}';

  factory BillingPeriodOption.fromWire(String value, {int invoiceCount = 0}) {
    final parts = value.split('|');
    if (parts.length == 2) {
      final start = JsonUtils.asDate(parts[0]) ?? DateTime.now();
      final end = JsonUtils.asDate(parts[1]) ?? start;
      return BillingPeriodOption(
        start: start,
        end: end,
        invoiceCount: invoiceCount,
      );
    }
    final now = DateTime.now();
    return BillingPeriodOption(
      start: now,
      end: now,
      invoiceCount: invoiceCount,
    );
  }

  factory BillingPeriodOption.fromJson(Map<String, dynamic> json) {
    final start = JsonUtils.asDate(
      json['billing_period_start'] ?? json['start'],
    );
    final end = JsonUtils.asDate(json['billing_period_end'] ?? json['end']);
    return BillingPeriodOption(
      start: start ?? DateTime.now(),
      end: end ?? start ?? DateTime.now(),
      invoiceCount: JsonUtils.asInt(json['invoices_count']),
    );
  }

  @override
  List<Object?> get props => <Object?>[start, end];

  @override
  String toString() => jsonEncode(<String, dynamic>{
    'start': start.toIso8601String(),
    'end': end.toIso8601String(),
  });
}
