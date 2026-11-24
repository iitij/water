import 'dart:convert';

import '../../../../core/finance/finance_api_client.dart';
import '../../../../core/models/square_label.dart';
import '../../../../core/network/finance_endpoints.dart';
import '../../../../core/utils/json_utils.dart';
import '../models/invoice_model.dart';

/// مراجع خفيفة تحتاجها نوافذ الإصدار الثلاثية وشاشات التحصيل:
/// المربعات، المشتركون (مع العداد الحالي والرسوم المعلّقة)، وملخص الاستحقاق.
///
/// ملاحظة ملكية: هذه المسارات يملكها Dev 1/Dev 2/Dev 4، لكنها للقراءة فقط داخل
/// وحدة Dev 3، وتُستخدم بأشكال مرنة (tolerant parsing) حتى لا ننكسر مع اختلاف
/// صيغة الاستجابة بين `SubscriberController@index` الحالي والنسخة الموسّعة.
class SquareOption {
  const SquareOption({required this.id, required this.name, this.code, this.subscribersCount});

  final int id;
  final String name;
  final String? code;
  String get displayName => squareDisplayName(name, code);
  final int? subscribersCount;

  factory SquareOption.fromJson(Map<String, dynamic> json) => SquareOption(
        id: JsonUtils.asInt(json['id']),
        name: JsonUtils.asString(json['name'], fallback: '-'),
        code: JsonUtils.asStringOrNull(json['code']),
        subscribersCount: JsonUtils.asIntOrNull(
          json['subscribers_count'] ?? json['count'],
        ),
      );

  @override
  String toString() => displayName;
}

class MeterOption {
  const MeterOption({
    required this.id,
    required this.uniqueCode,
    this.pathNumber,
    this.balance = 0,
    this.status,
    this.uninvoicedUnits = 0,
  });

  final int id;
  final String uniqueCode;
  final int? pathNumber;
  final double balance;
  final String? status;

  /// وحدات غير مفوترة (من `GET /api/meters/{id}/pending` عند توفره — Dev 2).
  final double uninvoicedUnits;

  // Legacy tenant fields may still arrive from older API/cache snapshots.
  // They never affect current-meter selection or subscriber identity.

  /// رصيد دائن متاح على دفتر العداد (يُخصم من الفاتورة القادمة كما في
  /// `InvoiceService::generateInvoice` سطر 96-104).
  double get credit => balance > 0 ? balance : 0;
  double get debit => balance < 0 ? -balance : 0;

  String get label {
    final buffer = StringBuffer(uniqueCode.isEmpty ? '#$id' : uniqueCode);
    if (pathNumber != null) buffer.write(' / مسار $pathNumber');
    return buffer.toString();
  }

  factory MeterOption.fromJson(Map<String, dynamic> json) => MeterOption(
        id: JsonUtils.asInt(json['id']),
        uniqueCode: JsonUtils.asString(json['unique_code']),
        pathNumber: JsonUtils.asIntOrNull(json['path_number']),
        balance: JsonUtils.asDouble(json['balance']),
        status: JsonUtils.asStringOrNull(json['status']),
        uninvoicedUnits: JsonUtils.asDouble(json['uninvoiced_units']),
      );

  @override
  bool operator ==(Object other) => other is MeterOption && other.id == id;

  @override
  int get hashCode => id.hashCode;
}

class SubscriberOption {
  const SubscriberOption({
    required this.id,
    required this.name,
    this.phone,
    this.squareId,
    this.squareName,
    this.status,
    this.balance = 0,
    this.meters = const <MeterOption>[],
  });

  final int id;
  final String name;
  final String? phone;
  final int? squareId;
  final String? squareName;
  final String? status;

  /// الرصيد العام للمشترك (موجب = دائن).
  final double balance;
  final List<MeterOption> meters;

  bool get isActive => status == null || status == 'active';

  /// رصيد دائن متاح للخصم عند الإصدار (أدنى بين العام والعداد حسب نوع الفاتورة).
  double get availableGeneralCredit => balance > 0 ? balance : 0;

  String get label {
    final buffer = StringBuffer(name);
    if (squareName != null) buffer.write(' — ${squareName!}');
    if (meters.isNotEmpty) {
      buffer.write(' (${meters.map((m) => m.uniqueCode).join('، ')})');
    }
    return buffer.toString();
  }

  factory SubscriberOption.fromJson(Map<String, dynamic> json) {
    final square = JsonUtils.asMap(json['square']);
    return SubscriberOption(
      id: JsonUtils.asInt(json['id']),
      name: JsonUtils.asString(json['name'], fallback: '-'),
      phone: JsonUtils.asStringOrNull(json['phone']),
      squareId: JsonUtils.asIntOrNull(json['square_id']),
      squareName: square == null
          ? JsonUtils.asStringOrNull(json['square_name'])
          : JsonUtils.asStringOrNull(square['name']),
      status: JsonUtils.asStringOrNull(json['status']),
      balance: JsonUtils.asDouble(json['balance']),
      meters: JsonUtils.asMapList(json['meters'])
          .where((row) => row['removed_at'] == null && row['deleted_at'] == null)
          .map(MeterOption.fromJson)
          .take(1)
          .toList(),
    );
  }

  SubscriberOption copyWith({List<MeterOption>? meters}) => SubscriberOption(
        id: id,
        name: name,
        phone: phone,
        squareId: squareId,
        squareName: squareName,
        status: status,
        balance: balance,
        meters: meters ?? this.meters,
      );

  @override
  bool operator ==(Object other) => other is SubscriberOption && other.id == id;

  @override
  int get hashCode => id.hashCode;

  @override
  String toString() => name;
}

/// ملخص استحقاق المشترك لنافذة التحصيل (`GET /api/subscribers/{id}/unpaid-invoices-summary`).
/// عند غياب المسار يُشتق محلياً من قائمة فواتير المشترك.
class SubscriberDueSummary {
  const SubscriberDueSummary({
    required this.subscriberId,
    this.latestUnpaidInvoiceId,
    this.latestUnpaidBillNumber,
    this.dueAmount = 0,
    this.outstandingBalance = 0,
    this.creditBalance = 0,
    this.openInvoicesCount = 0,
    this.invoices = const <InvoiceModel>[],
  });

  final int subscriberId;
  final int? latestUnpaidInvoiceId;
  final String? latestUnpaidBillNumber;

  /// المستحق الفعلي من الخادم، دون تكرار المتأخرات التاريخية.
  final double dueAmount;

  /// صافي مديونية الحساب المالي الموحد للمشترك.
  final double outstandingBalance;
  final double creditBalance;
  final int openInvoicesCount;
  final List<InvoiceModel> invoices;

  bool get hasInvoiceToCollect => latestUnpaidInvoiceId != null;

  factory SubscriberDueSummary.fromJson(Map<String, dynamic> json) => SubscriberDueSummary(
        subscriberId: JsonUtils.asInt(json['subscriber_id']),
        latestUnpaidInvoiceId: JsonUtils.asIntOrNull(json['invoice_id']),
        latestUnpaidBillNumber: JsonUtils.asStringOrNull(json['bill_number']),
        dueAmount: JsonUtils.asDouble(json['due_amount'] ?? json['amount_due']),
        outstandingBalance: JsonUtils.asDouble(json['outstanding_balance']),
        creditBalance: JsonUtils.asDouble(json['credit_balance']),
        openInvoicesCount: JsonUtils.asInt(json['open_invoices_count']),
        invoices: JsonUtils.asMapList(json['invoices']).map(InvoiceModel.fromJson).toList(growable: false),
      );

  @override
  String toString() => jsonEncode(<String, dynamic>{
        'subscriber_id': subscriberId,
        'invoice_id': latestUnpaidInvoiceId,
        'due_amount': dueAmount,
      });
}

class ReferencesRemoteDataSource {
  const ReferencesRemoteDataSource(this._client);

  final FinanceApiClient _client;

  Future<List<SquareOption>> squares() async {
    final response = await _client.getRaw(FinanceEndpoints.squares);
    return JsonUtils.unwrapList(response).map(SquareOption.fromJson).toList();
  }

  /// `/api/subscribers?q=&square_id=` — بحث نصي مع فلتر المربع.
  Future<List<SubscriberOption>> subscribers({
    String? search,
    int? squareId,
    String? status,
    int? limit,
  }) async {
    final response = await _client.getRaw(
      FinanceEndpoints.subscribers,
      query: <String, dynamic>{
        if (search != null && search.trim().isNotEmpty) 'q': search.trim(),
        if (squareId != null) 'square_id': squareId,
        if (status != null) 'status': status,
        if (limit != null) 'per_page': limit,
      },
    );
    return JsonUtils.unwrapList(response).map(SubscriberOption.fromJson).toList();
  }

  /// عدّادات مشترك محدد — `GET /api/subscribers/{id}` يرجع `meters` مضمّنة.
  Future<List<MeterOption>> metersOf(int subscriberId) async {
    final response = await _client.getRaw(
      FinanceEndpoints.subscribers,
      query: <String, dynamic>{'subscriber_id': subscriberId, 'with': 'meters'},
    );
    final list = JsonUtils.unwrapList(response);
    for (final item in list) {
      if (JsonUtils.asInt(item['id']) == subscriberId) {
        return SubscriberOption.fromJson(item).meters;
      }
    }
    return const <MeterOption>[];
  }

  Future<SubscriberDueSummary?> dueSummary(int subscriberId) async {
    try {
      final response = await _client.getRaw(
        FinanceEndpoints.subscriberDueSummary(subscriberId),
      );
      final map = JsonUtils.asMap(response);
      if (map == null) return null;
      final data = JsonUtils.asMap(map['data']) ?? map;
      return SubscriberDueSummary.fromJson(data);
    } catch (_) {
      return null;
    }
  }


}
