import '../../../../core/error/failures.dart';
import '../../../../core/finance/finance_api_client.dart';
import '../../../../core/network/finance_endpoints.dart';
import '../../../../core/utils/json_utils.dart';
import '../models/collect_payment_models.dart';
import '../models/payment_model.dart';

/// مصدر بيانات سندات القبض (§2.3 المجموعة 8) + مسار التحصيل السريع من الفاتورة.
class PaymentsRemoteDataSource {
  const PaymentsRemoteDataSource(this._client);

  final FinanceApiClient _client;

  /// #48 `GET /api/payments`
  Future<List<PaymentModel>> list(PaymentQuery query) async {
    final response = await _client.getRaw(
      FinanceEndpoints.payments,
      query: query.toQueryMap(),
    );
    return JsonUtils.unwrapList(response).map(PaymentModel.fromJson).toList();
  }

  /// #49 `GET /api/payments/{id}`
  Future<PaymentModel> byId(int id) async {
    final response = await _client.getRaw(FinanceEndpoints.payment(id));
    final map = JsonUtils.asMap(response);
    if (map == null) throw const NotFoundFailure('سند القبض غير موجود.');
    final data = JsonUtils.asMap(map['payment']) ?? JsonUtils.asMap(map['data']) ?? map;
    return PaymentModel.fromJson(data);
  }

  /// #50 `POST /api/payments` — سند قبض عام (قد يكون بلا فاتورة → توزيع FIFO).
  Future<CollectPaymentOutcome> create(Map<String, dynamic> payload) async {
    final response = await _client.post(FinanceEndpoints.payments, body: payload);
    return _parseOutcome(response);
  }

  /// #44 `POST /api/invoices/{id}/pay` — التحصيل من شاشة الفاتورة/المحصل السريع.
  Future<CollectPaymentOutcome> payInvoice(
    int invoiceId,
    Map<String, dynamic> payload,
  ) async {
    final response = await _client.post(
      FinanceEndpoints.invoicePay(invoiceId),
      body: payload,
    );
    return _parseOutcome(response);
  }

  /// #51 `PUT /api/payments/{id}` — يعيد توزيع الفواتير في الخادم.
  Future<PaymentModel> update(int id, Map<String, dynamic> payload) async {
    final response = await _client.put(FinanceEndpoints.payment(id), body: payload);
    final map = JsonUtils.asMap(response);
    if (map == null) return byId(id);
    final data = JsonUtils.asMap(map['payment']) ?? JsonUtils.asMap(map['data']) ?? map;
    return PaymentModel.fromJson(data);
  }

  /// #52 `DELETE /api/payments/{id}`
  Future<void> delete(int id) => _client.delete(FinanceEndpoints.payment(id));

  /// #new `POST /api/payments/{id}/whatsapp-result` — يسجّل أن السند أُرسل.
  Future<void> recordWhatsappResult(int id, {required String via}) =>
      _client.post(
        FinanceEndpoints.paymentWhatsappResult(id),
        body: <String, dynamic>{'via': via},
      );

  /// `POST /api/payments/{id}/void` — الإلغاء بدل الحذف.
  ///
  /// الخادم يحذف حركات دفتر الأستاذ ويعيد احتساب الفواتير والأرصدة، ويحفظ
  /// السبب والمُنفّذ في سجل التدقيق.
  Future<VoidPaymentOutcome> voidPayment(int id, {required String reason}) async {
    final trimmed = reason.trim();
    if (trimmed.length < 10) {
      throw const ValidationFailure(
        'سبب الإلغاء مطلوب ولا يقل عن 10 أحرف.',
        <String, List<String>>{},
      );
    }
    final response = await _client.post(
      FinanceEndpoints.paymentVoid(id),
      body: <String, dynamic>{'reason': trimmed},
    );
    final map = JsonUtils.asMap(response);
    final pending = map?['pending_approval'] == true;
    final data = JsonUtils.asMap(map?['payment']) ?? JsonUtils.asMap(map?['data']);
    final payment = data == null ? await byId(id) : PaymentModel.fromJson(data);
    return VoidPaymentOutcome(payment: payment, pendingApproval: pending);
  }

  /// `GET /api/payments/void-requests` — طلبات الإلغاء بانتظار اعتماد المشرف.
  Future<List<PaymentVoidRequest>> voidRequests({int page = 1, int perPage = 25}) async {
    final response = await _client.getRaw(
      FinanceEndpoints.paymentsVoidRequests,
      query: <String, dynamic>{'page': page, 'per_page': perPage},
    );
    return JsonUtils.unwrapList(response).map(PaymentVoidRequest.fromJson).toList();
  }

  /// `POST /api/payments/{id}/void-decision` — قرار المشرف في طلب الإلغاء.
  Future<bool> decideVoidRequest(
    int id, {
    required bool approve,
    String? notes,
  }) async {
    final response = await _client.post(
      FinanceEndpoints.paymentVoidDecision(id),
      body: <String, dynamic>{
        'approve': approve,
        if (notes != null && notes.trim().isNotEmpty) 'notes': notes.trim(),
      },
    );
    final map = JsonUtils.asMap(response);
    // الخادم يعيد {message, payment, receipt_data} بعد تنفيذ القرار.
    return map != null &&
        (map['payment'] != null || map['message'] != null);
  }

  /// #52 `POST /api/payments/bulk-delete`
  Future<int> bulkDelete(List<int> ids) async {
    final response = await _client.post(
      FinanceEndpoints.paymentsBulkDelete,
      body: <String, dynamic>{'ids': ids},
    );
    final map = JsonUtils.asMap(response);
    if (map == null) return ids.length;
    final count = JsonUtils.asIntOrNull(
      map['deleted_count'] ?? map['deleted'] ?? map['processed'] ?? map['count'],
    );
    return count ?? ids.length;
  }

  /// يفسّر استجابة `pay` التي تحتوي `payment` + `receipt_data` + `invoice_status`.
  CollectPaymentOutcome _parseOutcome(Object? response) {
    final map = JsonUtils.asMap(response);
    if (map == null) {
      throw const UnexpectedFailure('استجابة غير متوقعة من خادم الدفع.');
    }

    final paymentJson = JsonUtils.asMap(map['payment']);
    final invoiceJson = JsonUtils.asMap(map['invoice']);
    final status = JsonUtils.asStringOrNull(map['invoice_status']);

    return CollectPaymentOutcome(
      payment: paymentJson == null ? null : PaymentModel.fromJson(paymentJson),
      receiptData: ReceiptDataModel.fromPaymentJson(map),
      message: JsonUtils.asStringOrNull(map['message']),
      invoiceStatus: status,
      invoiceTotalAmount: JsonUtils.asDoubleOrNull(invoiceJson?['total_amount']),
      invoiceAmountPaid: JsonUtils.asDoubleOrNull(invoiceJson?['amount_paid']),
    );
  }
}
