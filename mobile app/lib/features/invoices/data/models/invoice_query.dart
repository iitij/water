import 'package:equatable/equatable.dart';

import '../../../../core/finance/finance_search.dart';
import '../../../../core/utils/json_utils.dart';

/// فلاتر قائمة الفواتير الأربعة + البحث الذكي (الخطة §4.4، §2.3-33).
class InvoiceQuery extends Equatable {
  const InvoiceQuery({
    this.search = '',
    this.squareId,
    this.status,
    this.subscriberId,
    this.invoiceType,
    this.billingPeriod,
    this.trashed = 'without',
    this.limit,
    this.page = 1,
    this.sortBy = 'bill_date',
    this.sortDirection = 'desc',
  });

  final String search;

  /// `without` | `with` | `only` — لمطابقة `TrashedFilter` في Filament.
  final String trashed;
  final int? squareId;
  final String? status;
  final int? subscriberId;
  final String? invoiceType;

  /// بصيغة `YYYY-MM-DD|YYYY-MM-DD` كما يريدها الخادم.
  final String? billingPeriod;
  final int? limit;
  final int page;
  final String sortBy;
  final String sortDirection;

  FinanceSearchQuery get parsedSearch => FinanceSearchQuery.parse(search);

  bool get hasSearch => parsedSearch.rawQuery.isNotEmpty;

  Map<String, dynamic> toQueryMap() {
    final parsed = parsedSearch;
    return <String, dynamic>{
      if (parsed.rawQuery.isNotEmpty) 'query': parsed.rawQuery,
      // البحث المركّب `4/1` يُرسل أيضاً كمفاتيح منفصلة لمنصات API الأحدث.
      if (parsed.isCompoundPathSearch && parsed.squareId != null)
        'square_id': parsed.squareId,
      if (parsed.isCompoundPathSearch && parsed.pathNumber != null)
        'path_number': parsed.pathNumber,
      if (squareId != null) 'square_id': squareId,
      if (status != null) 'status': status,
      if (subscriberId != null) 'subscriber_id': subscriberId,
      if (invoiceType != null) 'invoice_type': invoiceType,
      if (billingPeriod != null) 'billing_period': _wireBillingPeriod(billingPeriod!),
      if (trashed != 'without') 'trashed': trashed,
      if (limit != null) 'per_page': limit,
      'page': page,
      'sort_by': sortBy,
      'sort_direction': sortDirection,
    };
  }

  /// Dio's query encoder cannot encode DateTime values; normalize periods to
  /// the API's documented `YYYY-MM-DD|YYYY-MM-DD` wire representation.
  static String _wireBillingPeriod(String value) => value
      .split('|')
      .map((part) => DateTime.tryParse(part)?.toIso8601String().split('T').first ?? part)
      .join('|');

  /// بصمة للكيوبت: تمنع إعادة الجلب عند نفس الحالة.
  String get signature => <String>[
    search,
    '$squareId',
    '$status',
    '$subscriberId',
    '$invoiceType',
    '$billingPeriod',
    trashed,
    '$limit',
    '$page',
    sortBy,
    sortDirection,
  ].join('§');

  InvoiceQuery copyWith({
    String? search,
    int? squareId,
    bool clearSquare = false,
    String? status,
    bool clearStatus = false,
    int? subscriberId,
    bool clearSubscriber = false,
    String? invoiceType,
    bool clearInvoiceType = false,
    String? billingPeriod,
    bool clearBillingPeriod = false,
    String? trashed,
    int? limit,
    int? page,
    String? sortBy,
    String? sortDirection,
  }) => InvoiceQuery(
    search: search ?? this.search,
    squareId: clearSquare ? null : (squareId ?? this.squareId),
    status: clearStatus ? null : (status ?? this.status),
    subscriberId: clearSubscriber ? null : (subscriberId ?? this.subscriberId),
    invoiceType: clearInvoiceType ? null : (invoiceType ?? this.invoiceType),
    billingPeriod: clearBillingPeriod
        ? null
        : (billingPeriod ?? this.billingPeriod),
    trashed: trashed ?? this.trashed,
    limit: limit ?? this.limit,
    page: page ?? this.page,
    sortBy: sortBy ?? this.sortBy,
    sortDirection: sortDirection ?? this.sortDirection,
  );

  int get activeFilterCount => <Object?>[
    squareId,
    status,
    subscriberId,
    invoiceType,
    billingPeriod,
    if (trashed != 'without') trashed,
  ].where((e) => e != null).length;

  @override
  List<Object?> get props => <Object?>[
    search,
    squareId,
    status,
    subscriberId,
    invoiceType,
    billingPeriod,
    trashed,
    limit,
    page,
    sortBy,
    sortDirection,
  ];
}

/// الطلبات المشتركة لمحركات الإصدار الثلاثة (`SCR-16`).
class GenerateMeterInvoiceRequest extends Equatable {
  const GenerateMeterInvoiceRequest({
    required this.subscriberId,
    required this.meterId,
    this.includeReadings = true,
    this.hasSubscriptionFee = false,
    this.subscriptionInstallment = 0,
    this.hasTankerFee = false,
    this.tankerInstallment = 0,
    this.notes,
  });

  final int subscriberId;
  final int meterId;
  final bool includeReadings;
  final bool hasSubscriptionFee;
  final double subscriptionInstallment;
  final bool hasTankerFee;
  final double tankerInstallment;
  final String? notes;

  /// جسم الطلب لـ `POST /api/invoices/generate-meter` (§2.3-38).
  Map<String, dynamic> toJson() => <String, dynamic>{
    'subscriber_id': subscriberId,
    'meter_id': meterId,
    'include_readings': includeReadings,
    'has_subscription_fee': hasSubscriptionFee,
    'subscription_installment': hasSubscriptionFee
        ? subscriptionInstallment
        : 0,
    'has_tanker_fee': hasTankerFee,
    'tanker_installment': hasTankerFee ? tankerInstallment : 0,
    if (notes != null && notes!.isNotEmpty) 'notes': notes,
  };

  @override
  List<Object?> get props => <Object?>[
    subscriberId,
    meterId,
    includeReadings,
    hasSubscriptionFee,
    subscriptionInstallment,
    hasTankerFee,
    tankerInstallment,
    notes,
  ];
}

class GenerateSubscriptionInvoiceRequest extends Equatable {
  const GenerateSubscriptionInvoiceRequest({
    required this.subscriberId,
    required this.subscriptionInstallment,
    this.meterId,
    this.notes,
  });

  final int subscriberId;
  final double subscriptionInstallment;

  /// رسوم الاشتراك قد تكون مرتبطة بعداد (`SubscriptionFee.meter_id`) — الاختياري هنا
  /// يحدّد أي الرسوم تُضمَّن، ويبقى `meter_id` للفاتورة `null` حفاظاً على العزل.
  final int? meterId;
  final String? notes;

  Map<String, dynamic> toJson() => <String, dynamic>{
    'subscriber_id': subscriberId,
    'subscription_installment': subscriptionInstallment,
    if (meterId != null) 'fee_meter_id': meterId,
    if (notes != null && notes!.isNotEmpty) 'notes': notes,
  };

  @override
  List<Object?> get props => <Object?>[
    subscriberId,
    subscriptionInstallment,
    meterId,
    notes,
  ];
}

class GenerateTankerInvoiceRequest extends Equatable {
  const GenerateTankerInvoiceRequest({
    required this.subscriberId,
    required this.tankerInstallment,
    this.serviceRequestIds = const <int>[],
    this.notes,
  });

  final int subscriberId;
  final double tankerInstallment;
  final List<int> serviceRequestIds;
  final String? notes;

  Map<String, dynamic> toJson() => <String, dynamic>{
    'subscriber_id': subscriberId,
    'tanker_installment': tankerInstallment,
    if (serviceRequestIds.isNotEmpty) 'service_request_ids': serviceRequestIds,
    if (notes != null && notes!.isNotEmpty) 'notes': notes,
  };

  @override
  List<Object?> get props => <Object?>[
    subscriberId,
    tankerInstallment,
    serviceRequestIds,
    notes,
  ];
}

/// نتيجة عملية (إعادة احتساب/حذف جماعي) مطابقة لشكل `bulk-*` المقترح.
class BulkActionResult extends Equatable {
  const BulkActionResult({
    required this.processed,
    required this.failed,
    this.message,
  });

  factory BulkActionResult.fromJson(Map<String, dynamic> json) =>
      BulkActionResult(
        processed: JsonUtils.asInt(
          json['processed'] ??
              json['deleted_count'] ??
              json['restored_count'] ??
              json['recalculated_count'] ??
              json['success_count'] ??
              json['count'],
        ),
        failed: JsonUtils.asInt(json['failed'] ?? json['skipped_count']),
        message: JsonUtils.asStringOrNull(json['message']),
      );

  final int processed;
  final int failed;
  final String? message;

  @override
  List<Object?> get props => <Object?>[processed, failed, message];
}
