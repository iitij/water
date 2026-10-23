import '../../../../core/config/app_config.dart';
import '../../../../core/error/failures.dart';
import '../../../../core/local_storage/sync_queue.dart';
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
    SyncQueueRepository? syncQueue,
    AppConfig config = const AppConfig(),
  })  : _remote = remote,
        _invoices = invoices,
        _network = networkInfo,
        _syncQueue = syncQueue,
        _config = config;

  final PaymentsRemoteDataSource _remote;
  final InvoicesRemoteDataSource _invoices;
  final NetworkInfo _network;
  final SyncQueueRepository? _syncQueue;
  final AppConfig _config;

  @override
  Future<List<PaymentModel>> list(PaymentQuery query) => _remote.list(query);

  @override
  Future<PaymentModel> byId(int id) => _remote.byId(id);

  @override
  Future<CollectPaymentOutcome> collect(CollectPaymentRequest request) async {
    final validationError = request.validate();
    if (validationError != null) throw ValidationFailure(validationError, <String, List<String>>{});

    final online = await _network.isConnected;
    if (!online && _config.isOfflineEnabled) {
      return _enqueueOffline(request);
    }

    try {
      if (request.invoiceId != null) {
        return await _remote.payInvoice(
          request.invoiceId!,
          request.toPayPayload(),
        );
      }
      return await _remote.create(request.toCreatePayload());
    } on AppFailure catch (failure) {
      // انقطاع أثناء الطلب → ندرجه في الطابور بدل إخبار المحصل بالفشل.
      if (_isNetworkLike(failure) && _config.isOfflineEnabled) {
        return _enqueueOffline(request);
      }
      rethrow;
    }
  }

  Future<CollectPaymentOutcome> _enqueueOffline(CollectPaymentRequest request) async {
    final localReceipt = 'REC-LOCAL-${DateTime.now().millisecondsSinceEpoch}';
    final queue = _syncQueue;
    if (queue == null) {
      throw const NetworkFailure(
        'لا يوجد اتصال والطابور المحلي غير مهيأ — جرّب المزامنة لاحقاً.',
      );
    }

    await queue.enqueue(
      SyncAction(
        type: request.invoiceId != null
            ? 'invoice.pay'
            : 'payment.create',
        payload: <String, dynamic>{
          if (request.invoiceId != null) 'invoice_id': request.invoiceId,
          ...request.toCreatePayload(),
        },
        createdAt: DateTime.now(),
        idempotencyKey: localReceipt,
        sourceModule: 'payments',
      ),
    );

    return CollectPaymentOutcome(
      payment: PaymentModel(
        id: 0,
        subscriberId: request.subscriberId,
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
      '${_config.pdfBaseUrl ?? ''}${AppConfig.fallbackReceiptPrintPath(paymentId)}';
}
