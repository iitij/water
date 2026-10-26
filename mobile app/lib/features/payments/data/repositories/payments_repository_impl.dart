import 'dart:async';

import 'package:uuid/uuid.dart';

import '../../../../core/error/failures.dart';
import '../../../../core/finance/finance_api_client.dart';
import '../../../../core/finance/finance_sync_queue.dart';
import '../../../../core/network/finance_endpoints.dart';
import '../../../../core/network/network_info.dart';
import '../../../audit/services/audit_log_service.dart';
import '../../../invoices/data/datasources/invoices_remote_datasource.dart';
import '../../../invoices/data/models/invoice_model.dart';
import '../../../invoices/data/models/invoice_query.dart';
import '../../../invoices/domain/accounting/finance_enums.dart';
import '../../../invoices/domain/accounting/invoice_accounting.dart';
import '../../domain/repositories/paginated_payments_repository.dart';
import '../../domain/repositories/payments_repository.dart';
import '../../domain/repositories/sorted_void_requests_repository.dart';
import '../datasources/payments_remote_datasource.dart';
import '../models/collect_payment_models.dart';
import '../models/payment_model.dart';
import '../models/payments_page.dart';

/// مستودع السندات: شبكة + طابور مزامنة أوفلاين للتحصيل الميداني.
///
/// منطق «الدفعة أوفلاين»: سند القبض يُسجَّل في `pending_sync_actions` بمفتاح
/// idempotency محلي، لأن رقم السند الرسمي (`REC-YYYYMM-XXXX`) يولّده الخادم فقط
/// (`Payment::generateReceiptNumber`). عند المزامنة يستبدل Dev 2 الرقم المحلي.
class PaymentsRepositoryImpl
    implements
        PaymentsRepository,
        PaginatedPaymentsRepository,
        SortedVoidRequestsRepository {
  const PaymentsRepositoryImpl({
    required PaymentsRemoteDataSource remote,
    required InvoicesRemoteDataSource invoices,
    required NetworkInfo networkInfo,
    FinanceSyncQueueRepository? syncQueue,
    AuditLogService? audit,
    Uuid uuid = const Uuid(),
    this.offlineEnabled = true,
  }) : _remote = remote,
       _invoices = invoices,
       _network = networkInfo,
       _syncQueue = syncQueue,
       _audit = audit,
       _uuid = uuid;

  final PaymentsRemoteDataSource _remote;
  final InvoicesRemoteDataSource _invoices;
  final NetworkInfo _network;
  final FinanceSyncQueueRepository? _syncQueue;

  /// سجل التدقيق المحلي (S4-05): يسجّل التحصيل والإلغاء حتى بلا شبكة.
  final AuditLogService? _audit;
  final Uuid _uuid;

  /// تعطيل الطابور المحلي عند الحاجة في الاختبارات.
  final bool offlineEnabled;

  @override
  Future<List<PaymentModel>> list(PaymentQuery query) => _remote.list(query);

  @override
  Future<PaymentsPage> listPage(PaymentQuery query) => _remote.listPage(query);

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
      final outcome = request.invoiceId != null
          ? await _remote.payInvoice(
              request.invoiceId!,
              request.toPayPayload(syncUuid: syncUuid),
            )
          : await _remote.create(request.toCreatePayload(syncUuid: syncUuid));

      _record(
        'payment.collected',
        entityId: request.subscriberId,
        after: <String, dynamic>{
          'amount': request.amount,
          'method': request.method,
          'invoice_id': request.invoiceId,
          'meter_id': request.meterId,
          'sync_uuid': syncUuid,
        },
      );
      return outcome;
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

    _record(
      'payment.queued_offline',
      entityId: request.subscriberId,
      after: <String, dynamic>{
        'amount': request.amount,
        'method': request.method,
        'invoice_id': request.invoiceId,
        'sync_uuid': syncUuid,
      },
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

  /// تسجيل أفضل-جهد: فشل السجل لا يجوز أن يُفشل عملية مالية.
  void _record(
    String event, {
    String? entityType,
    Object? entityId,
    Map<String, dynamic>? after,
  }) {
    final audit = _audit;
    if (audit == null) return;
    unawaited(() async {
      await audit.record(
        event,
        entityType: entityType,
        entityId: entityId,
        after: after,
      );
      await audit.flush();
    }());
  }

  static bool _isNetworkLike(AppFailure failure) =>
      failure is NetworkFailure ||
      failure is TimeoutFailure ||
      failure.statusCode == 0;

  @override
  Future<PaymentModel> update(int id, Map<String, dynamic> payload) =>
      _remote.update(id, payload);

  /// الإلغاء لا يُنفَّذ محلياً أبداً: الحكم النهائي للخادم (قد يطلبه اعتماد
  /// مشرف). عند انقطاع الشبكة يُحفظ **طلب** الإلغاء في طابور المزامنة بسبب
  /// مكتوب، ويُرسَل تلقائياً عند العودة؛ السند يبقى صالحاً حتى يقبل الخادم.
  @override
  Future<VoidPaymentOutcome> voidPayment(
    int id, {
    required String reason,
  }) async {
    final trimmed = reason.trim();
    if (trimmed.length < 10) {
      throw const ValidationFailure(
        'اكتب سبب الإلغاء (10 أحرف على الأقل) ليُسجَّل في سجل التدقيق.',
        <String, List<String>>{},
      );
    }

    try {
      final outcome = await _remote.voidPayment(id, reason: trimmed);
      _record(
        outcome.pendingApproval
            ? 'payment.void_pending_approval'
            : 'payment.voided',
        entityType: 'Payment',
        entityId: id,
        after: <String, dynamic>{'reason': trimmed},
      );
      return outcome;
    } on AppFailure catch (failure) {
      final queue = _syncQueue;
      if (!offlineEnabled || queue == null || !_isNetworkLike(failure)) {
        rethrow;
      }

      await queue.enqueue(
        SyncAction(
          type: 'payment.void',
          payload: <String, dynamic>{'payment_id': id, 'reason': trimmed},
          createdAt: DateTime.now(),
          idempotencyKey: _uuid.v4(),
          sourceModule: 'payments',
        ),
      );

      _record(
        'payment.void_queued_offline',
        entityType: 'Payment',
        entityId: id,
        after: <String, dynamic>{'reason': trimmed},
      );

      return const VoidPaymentOutcome(queuedOffline: true);
    }
  }

  @override
  Future<List<PaymentVoidRequest>> voidRequests({
    int page = 1,
    int perPage = 25,
  }) => _remote.voidRequests(page: page, perPage: perPage);

  @override
  Future<List<PaymentVoidRequest>> sortedVoidRequests({
    required int page,
    required int perPage,
    required String sortBy,
    required String sortDirection,
  }) => _remote.voidRequests(
    page: page,
    perPage: perPage,
    sortBy: sortBy,
    sortDirection: sortDirection,
  );

  @override
  Future<bool> decideVoidRequest(
    int id, {
    required bool approve,
    String? notes,
  }) async {
    final ok = await _remote.decideVoidRequest(
      id,
      approve: approve,
      notes: notes,
    );
    _record(
      approve
          ? 'payment.void_request_approved'
          : 'payment.void_request_rejected',
      entityType: 'Payment',
      entityId: id,
      after: <String, dynamic>{
        'approve': approve,
        if (notes != null && notes.trim().isNotEmpty) 'notes': notes.trim(),
      },
    );
    return ok;
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

  /// تسجيل نتيجة واتساب: الخادم أولاً، ثم سجل التدقيق المحلي كشبكة أمان
  /// (يعمل أوفلاين ويُرفع لاحقاً). لا يرمي أبداً — الإرسال وقع فعلاً.
  @override
  Future<bool> recordWhatsappSend(int paymentId, {required String via}) async {
    var serverOk = false;
    try {
      await _remote.recordWhatsappResult(paymentId, via: via);
      serverOk = true;
    } on AppFailure {
      serverOk = false;
    } catch (_) {
      serverOk = false;
    }
    _record(
      'payment.whatsapp_shared',
      entityType: 'Payment',
      entityId: paymentId,
      after: <String, dynamic>{'via': via, 'recorded_on_server': serverOk},
    );
    return serverOk;
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
