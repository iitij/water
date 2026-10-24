import 'package:uuid/uuid.dart';

import '../../../../core/error/failures.dart';
import '../../../../core/finance/finance_api_client.dart';
import '../../../../core/finance/finance_sync_queue.dart';
import '../../../../core/network/finance_endpoints.dart';
import '../../../../core/network/network_info.dart';
import '../../../invoices/data/datasources/invoices_remote_datasource.dart';
import '../../../invoices/data/models/invoice_model.dart';
import '../../../invoices/data/models/invoice_query.dart';
import '../../../invoices/domain/accounting/finance_enums.dart';
import '../../../invoices/domain/accounting/invoice_accounting.dart';
import '../../domain/repositories/payments_repository.dart';
import '../datasources/payments_remote_datasource.dart';
import '../models/collect_payment_models.dart';
import '../models/payment_model.dart';

/// مستودع السندات: شبكة + طابور مزامنة أوفلاين للتحصيل الميداني.
///
/// منطق «الدفعة أوفلاين»: سند القبض يُسجَّل في `pending_sync_actions` بمفتاح
/// idempotency محلي، لأن رقم السند الرسمي (`REC-YYYYMM-XXXX`) يولّده الخادم فقط
/// (`Payment::generateReceiptNumber`). عند المزامنة يستبدل Dev 2 الرقم المحلي.
class PaymentsRepositoryImpl implements PaymentsRepository {
  const PaymentsRepositoryImpl({
    required PaymentsRemoteDataSource remote,
    required InvoicesRemoteDataSource invoices,
    required NetworkInfo networkInfo,
    FinanceSyncQueueRepository? syncQueue,
    Uuid uuid = const Uuid(),
    this.offlineEnabled = true,
  })  : _remote = remote,
        _invoices = invoices,
        _network = networkInfo,
        _syncQueue = syncQueue,
        _uuid = uuid;

  final PaymentsRemoteDataSource _remote;
  final InvoicesRemoteDataSource _invoices;
  final NetworkInfo _network;
  final FinanceSyncQueueRepository? _syncQueue;
  final Uuid _uuid;

  /// تعطيل الطابور المحلي عند الحاجة في الاختبارات.
  final bool offlineEnabled;

  @override
  Future<List<PaymentModel>> list(PaymentQuery query) => _remote.list(query);

  @override
  Future<PaymentModel> byId(int id) => _remote.byId(id);

  @override
  Future<CollectPaymentOutcome> collect(CollectPaymentRequest request) async {
    final validationError = request.validate();
    if (validationError != null) {
      throw ValidationFailure(validationError, <String, List<String>>{});
    }

    // يُنشأ UUID v4 واحد قبل المحاولة الشبكية ويُعاد استخدامه عند السقوط أوفلاين
    // لمنع ازدواجية السند إذا وصلت الحزمة للخادم وانقطع الرد.
    final syncUuid = _uuid.v4();

    final online = await _network.isConnected;
    if (!online && offlineEnabled) {
      return _enqueueOffline(request, syncUuid: syncUuid);
    }

    try {
      if (request.invoiceId != null) {
        return await _remote.payInvoice(
          request.invoiceId!,
          request.toPayPayload(syncUuid: syncUuid),
        );
      }
      return await _remote.create(request.toCreatePayload(syncUuid: syncUuid));
    } on AppFailure catch (failure) {
      // انقطاع أثناء الطلب → ندرجه في الطابور بنفس sync_uuid بدل إخبار المحصل بالفشل.
      if (_isNetworkLike(failure) && offlineEnabled) {
        return _enqueueOffline(request, syncUuid: syncUuid);
      }
      rethrow;
    }
  }

  Future<CollectPaymentOutcome> _enqueueOffline(
    CollectPaymentRequest request, {
    required String syncUuid,
  }) async {
    final localReceipt = 'REC-LOCAL-${DateTime.now().millisecondsSinceEpoch}';
    final queue = _syncQueue;
    if (queue == null) {
      throw const NetworkFailure(
        'لا يوجد اتصال والطابور المحلي غير مهيأ — جرّب المزامنة لاحقاً.',
      );
    }

    await queue.enqueue(
      SyncAction(
        type: request.invoiceId != null ? 'invoice.pay' : 'payment.create',
        payload: <String, dynamic>{
          if (request.invoiceId != null) 'invoice_id': request.invoiceId,
          ...request.toCreatePayload(syncUuid: syncUuid),
        },
        createdAt: DateTime.now(),
        idempotencyKey: syncUuid,
        sourceModule: 'payments',
      ),
    );

    return CollectPaymentOutcome(
      payment: PaymentModel(
        id: 0,
        subscriberId: request.subscriberId,
        meterId: request.meterId,
        invoiceId: request.invoiceId,
        receiptNumber: localReceipt,
        amount: request.amount,
        method: request.method,
        referenceNumber: request.referenceNumber,
        reason: request.reason,
        notes: request.notes,
        paymentDate: request.paymentDate ?? DateTime.now(),
      ),
      message: 'تم حفظ السند محلياً ⏳ بانتظار المزامنة',
      invoiceStatus: InvoiceStatus.partiallyPaid.wire,
      queuedOffline: true,
    );
  }

  static bool _isNetworkLike(AppFailure failure) =>
      failure is NetworkFailure || failure is TimeoutFailure || failure.statusCode == 0;

  @override
  Future<PaymentModel> update(int id, Map<String, dynamic> payload) =>
      _remote.update(id, payload);

  /// الإلغاء يمرّ بالشبكة دائماً: لا يجوز إلغاء سند محلياً ثم اكتشاف أن
  /// الخادم لم يُلغِه. عند الانقطاع تُرمى `NetworkFailure` ويُطلب من المحصّل
  /// إعادة المحاولة (الإلغاء ليس عملية ميدانية حرجة كالتحصيل).
  @override
  Future<PaymentModel> voidPayment(int id, {required String reason}) {
    final trimmed = reason.trim();
    if (trimmed.length < 10) {
      throw const ValidationFailure(
        'اكتب سبب الإلغاء (10 أحرف على الأقل) ليُسجَّل في سجل التدقيق.',
        <String, List<String>>{},
      );
    }
    return _remote.voidPayment(id, reason: trimmed);
  }

  @override
  Future<void> delete(int id) => _remote.delete(id);

  @override
  Future<int> bulkDelete(List<int> ids) => _remote.bulkDelete(ids);

  @override
  Future<InvoiceModel?> latestUnpaidInvoice(int subscriberId) async {
    try {
      final list = await _invoices.list(
        InvoiceQuery(subscriberId: subscriberId, limit: 200),
      );
      return InvoiceAccounting.latestUnpaidOf(subscriberId, list);
    } on AppFailure {
      return null;
    }
  }

  @override
  String pdfPath(int paymentId) => FinanceEndpoints.paymentPdf(paymentId);

  @override
  String whatsappLinkPath(int paymentId) =>
      FinanceEndpoints.paymentWhatsappLink(paymentId);

  @override
  String sendWhatsappPath(int paymentId) =>
      FinanceEndpoints.paymentSendWhatsapp(paymentId);

  @override
  String printFallbackPath(int paymentId) =>
      financeAdminPrintUrl('/payments/$paymentId/print');
}
