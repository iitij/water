import '../../../../core/error/failures.dart';
import '../../../../core/finance/finance_api_client.dart';
import '../../../../core/network/finance_endpoints.dart';
import '../../../../core/utils/json_utils.dart';
import '../models/invoice_model.dart';
import '../models/invoice_query.dart';

/// مصدر بيانات الفواتير عبر الـ API (Dev 3).
///
/// كل مسار هنا موثّق برقمه في `FLUTTER_MOBILE_MASTER_PLAN.md` §2.3 (المجموعة 7).
/// المسارات المميّزة بـ ⚠️ غير موجودة بعد في `routes/api.php` وهي مطلوبة من Dev 1.
class InvoicesRemoteDataSource {
  const InvoicesRemoteDataSource(this._client);

  final FinanceApiClient _client;

  /// #33 `GET /api/invoices` — قائمة/Fلاتر/بحث ذكي.
  Future<List<InvoiceModel>> list(InvoiceQuery query) async {
    final response = await _client.getRaw(
      FinanceEndpoints.invoices,
      query: query.toQueryMap(),
    );
    return _parseInvoices(response);
  }

  /// #35 `GET /api/invoices/unpaid` — فواتير المحصل (`issued|overdue|partially_paid`).
  Future<List<InvoiceModel>> unpaid() async {
    final response = await _client.getRaw(FinanceEndpoints.invoicesUnpaid);
    return _parseInvoices(response);
  }

  /// #35 `GET /api/invoices/search` — البحث الميداني (اسم/رقم فاتورة/مسار مركّب).
  Future<List<InvoiceModel>> search({String? query, int? squareId}) async {
    final response = await _client.getRaw(
      FinanceEndpoints.invoicesSearch,
      query: <String, dynamic>{
        if (query != null && query.trim().isNotEmpty) 'query': query.trim(),
        if (squareId != null) 'square_id': squareId,
      },
    );
    final invoices = _parseInvoices(response);
    // `InvoiceController::search` يرتّب المطابقات المسار أولاً؛ نعيد الترتيب محلياً
    // تحسباً لاختلاف إصدار الخادم.
    invoices.sort((a, b) => (b.isPathMatch ? 1 : 0).compareTo(a.isPathMatch ? 1 : 0));
    return invoices;
  }

  /// #36 `GET /api/invoices/{id}` — التفاصيل الكاملة (بنود/قراءات/مدفوعات).
  Future<InvoiceModel> byId(int id) async {
    final response = await _client.getRaw(FinanceEndpoints.invoice(id));
    final map = JsonUtils.asMap(response);
    if (map == null) throw const NotFoundFailure('استجابة غير صالحة لتفاصيل الفاتورة.');
    // الخادم يغلّف التفاصيل بـ {"invoice": {...}} (InvoiceController::show) —
    // وكان التطبيق يقرأ "data" فقط فتظهر التفاصيل فارغة.
    final data = JsonUtils.asMap(map['invoice']) ??
        JsonUtils.asMap(map['data']);
    return InvoiceModel.fromJson(data ?? map);
  }

  /// #34 `GET /api/invoices/billing-periods` — فترات الفوترة للفلتر.
  Future<List<BillingPeriodOption>> billingPeriods() async {
    try {
      final response = await _client.getRaw(FinanceEndpoints.invoicesBillingPeriods);
      final items = JsonUtils.unwrapList(response);
      if (items.isEmpty && response is List) {
        return response
            .whereType<String>()
            .map((e) => BillingPeriodOption.fromWire(e))
            .toList();
      }
      return items.map(BillingPeriodOption.fromJson).toList();
    } on AppFailure {
      return const <BillingPeriodOption>[];
    }
  }

  /// #37 `POST /api/invoices` — إنشاء يدوي (يطابق `CreateInvoice` في Filament).
  Future<InvoiceModel> create(Map<String, dynamic> payload) =>
      _mutate(() => _client.post(FinanceEndpoints.invoices, body: payload));

  /// #38 `POST /api/invoices/generate-meter`
  Future<InvoiceModel> generateMeter(GenerateMeterInvoiceRequest request) => _mutate(
        () => _client.post(
          FinanceEndpoints.invoicesGenerateMeter,
          body: request.toJson(),
        ),
      );

  /// #39 `POST /api/invoices/generate-subscription`
  Future<InvoiceModel> generateSubscription(GenerateSubscriptionInvoiceRequest request) =>
      _mutate(
        () => _client.post(
          FinanceEndpoints.invoicesGenerateSubscription,
          body: request.toJson(),
        ),
      );

  /// #40 `POST /api/invoices/generate-tanker`
  Future<InvoiceModel> generateTanker(GenerateTankerInvoiceRequest request) => _mutate(
        () => _client.post(
          FinanceEndpoints.invoicesGenerateTanker,
          body: request.toJson(),
        ),
      );

  /// #41 `PUT /api/invoices/{id}` — تشغيل `Invoice::updated` (تحديث القيد + إعادة التوزيع).
  Future<InvoiceModel> update(int id, Map<String, dynamic> payload) => _mutate(
        () => _client.put(FinanceEndpoints.invoice(id), body: payload),
      );

  /// #42 `DELETE /api/invoices/{id}`
  Future<void> delete(int id) async {
    await _client.delete(FinanceEndpoints.invoice(id));
  }

  /// `DELETE /api/invoices/{id}/force` — حذف نهائي للفاتورة.
  Future<void> forceDelete(int id) async {
    await _client.delete(FinanceEndpoints.invoiceForce(id));
  }

  /// #42 `POST /api/invoices/bulk-delete`
  Future<BulkActionResult> bulkDelete(List<int> ids) async {
    final response = await _client.post(
      FinanceEndpoints.invoicesBulkDelete,
      body: <String, dynamic>{'ids': ids},
    );
    return _bulkResult(response, fallbackProcessed: ids.length);
  }

  /// #43 `POST /api/invoices/{id}/restore`
  Future<void> restore(int id) async {
    await _client.post(FinanceEndpoints.invoiceRestore(id));
  }

  /// `POST /api/invoices/bulk-restore` — استعادة جماعية للفواتير المحذوفة ناعماً.
  Future<BulkActionResult> bulkRestore(List<int> ids) async {
    final response = await _client.post(
      FinanceEndpoints.invoicesBulkRestore,
      body: <String, dynamic>{'ids': ids},
    );
    return _bulkResult(response, fallbackProcessed: ids.length);
  }

  /// `POST /api/invoices/bulk-force-delete` — حذف نهائي جماعي للفواتير.
  Future<BulkActionResult> bulkForceDelete(List<int> ids) async {
    final response = await _client.post(
      FinanceEndpoints.invoicesBulkForceDelete,
      body: <String, dynamic>{'ids': ids},
    );
    return _bulkResult(response, fallbackProcessed: ids.length);
  }

  /// ⚠️ `POST /api/invoices/{id}/recalculate` — مطلوب من Dev 1
  /// (يستدعي `PaymentService::recalculateInvoices($subscriber, $meterId)`).
  Future<InvoiceModel> recalculate(int id) async {
    final response = await _client.post(FinanceEndpoints.invoiceRecalculate(id));
    final map = JsonUtils.asMap(response);
    if (map != null) {
      final invoice = JsonUtils.asMap(map['invoice']);
      if (invoice != null) return InvoiceModel.fromJson(invoice);
    }
    return byId(id);
  }

  /// ⚠️ `POST /api/invoices/bulk-recalculate` — إعادة احتساب جماعي (Dev 1).
  Future<BulkActionResult> bulkRecalculate(List<int> ids) async {
    final response = await _client.post(
      FinanceEndpoints.invoicesBulkRecalculate,
      body: <String, dynamic>{'ids': ids},
    );
    return _bulkResult(response, fallbackProcessed: ids.length);
  }

  BulkActionResult _bulkResult(Object? response, {required int fallbackProcessed}) {
    final map = JsonUtils.asMap(response);
    if (map == null) {
      return BulkActionResult(processed: fallbackProcessed, failed: 0);
    }
    return BulkActionResult.fromJson(map);
  }

  /// يقبل `POST` الذي يرجع الفاتورة مباشرة أو داخل `{invoice: ...}` أو `{data: ...}`.
  Future<InvoiceModel> _mutate(Future<dynamic> Function() action) async {
    final response = await action();
    final map = JsonUtils.asMap(response);
    if (map != null) {
      for (final key in <String>['invoice', 'data']) {
        final nested = JsonUtils.asMap(map[key]);
        if (nested != null && nested.containsKey('bill_number')) {
          return InvoiceModel.fromJson(nested);
        }
      }
      if (map.containsKey('bill_number')) return InvoiceModel.fromJson(map);
    }
    throw const UnexpectedFailure('لم يُرجع الخادم بيانات الفاتورة بعد الحفظ.');
  }

  List<InvoiceModel> _parseInvoices(Object? response) {
    if (response is Map) {
      final map = response.cast<String, dynamic>();
      final nested = JsonUtils.asMap(map['invoices']);
      if (nested != null) return <InvoiceModel>[InvoiceModel.fromJson(nested)];
    }
    return JsonUtils.unwrapList(response).map(InvoiceModel.fromJson).toList();
  }
}
