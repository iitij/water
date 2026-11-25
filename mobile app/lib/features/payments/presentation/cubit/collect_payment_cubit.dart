import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/error/failures.dart';
import '../../../cash_sessions/services/cash_session_service.dart';
import '../../../invoices/data/datasources/references_remote_datasource.dart';
import '../../../invoices/data/models/invoice_model.dart';
import '../../../invoices/domain/accounting/finance_enums.dart';
import '../../../invoices/domain/accounting/invoice_accounting.dart';
import '../../data/models/collect_payment_models.dart';
import '../../domain/repositories/payments_repository.dart';
import 'collect_payment_state.dart';

/// منطق نافذة التحصيل المشتركة (`CollectPaymentSheet`) — يستعملها أكرم في
/// `SCR-13/15/18` ويسلمها لفؤاد (`SCR-05/07/19/20`) ولأسامة (`SCR-03`).
///
/// القواعد المنفذة هنا:
///  1. عند اختيار المشترك: جلب أحدث فاتورة غير مسددة وتعبئة `amount` بالمستحق
///     (`PaymentResource::form` سطر 36-52).
///  2. عند تغيير الفاتورة: المستحق الحي من payableAmount، دون تكرار المتأخرات التاريخية.
///  3. `reference_number` إلزامي مع `bank_transfer`/`check`.
///  4. معاينة توزيع FIFO محلياً + الفائض كرصيد دائن.
class CollectPaymentCubit extends Cubit<CollectPaymentState> {
  CollectPaymentCubit({
    required PaymentsRepository paymentsRepository,
    required ReferencesRemoteDataSource references,
    required int? meterId,
    CashSessionService? cashSessionService,
  }) : _payments = paymentsRepository,
       _references = references,
       _meterId = meterId,
       _cashSessions = cashSessionService,
       super(CollectPaymentState(paymentDate: DateTime.now()));

  final PaymentsRepository _payments;
  final ReferencesRemoteDataSource _references;

  /// حاجز «لا تحصيل بلا صندوق» (خطة 2.0 — الفصل 5.2). اختياري حتى لا تتعطل
  /// الشاشات القديمة، لكنه مربوط في كل مسارات الإنتاج عبر DI.
  final CashSessionService? _cashSessions;

  /// معرّف العداد مرجع للعملية فقط، وليس حساباً مالياً مستقلاً.
  final int? _meterId;

  /// فتح من فاتورة محددة (`SCR-13`/`SCR-15`/ودجة الفواتير المتأخرة).
  Future<void> openForInvoice(InvoiceModel invoice) async {
    emit(
      state.copyWith(
        subscriberId: invoice.subscriberId,
        subscriberName: invoice.beneficiaryName,
        invoiceId: invoice.id,
        dueAmount: invoice.payableAmount,
        amount: invoice.payableAmount,
        openInvoices: <InvoiceModel>[invoice],
        clearValidation: true,
      ),
    );
    await _loadAccountInvoices(invoice.subscriberId, invoice.meterId);
  }

  /// فتح عام (`SCR-18`): المستخدم يختار المشترك، فنجلب استحقاقه تلقائياً.
  Future<void> openForSubscriber(int subscriberId, {String? name}) async {
    emit(
      state.copyWith(
        subscriberId: subscriberId,
        subscriberName: name,
        clearInvoice: true,
        openInvoices: const [],
        dueAmount: 0,
        amount: 0,
        loadingInvoices: true,
        clearValidation: true,
      ),
    );
    await _loadAccountInvoices(subscriberId, _meterId, autoSelectLatest: true);
  }

  Future<void> _loadAccountInvoices(
    int subscriberId,
    int? meterId, {
    bool autoSelectLatest = false,
  }) async {
    try {
      final summary = await _references.dueSummary(subscriberId);
      final invoice = await _payments.latestUnpaidInvoice(subscriberId);
      if (isClosed || state.subscriberId != subscriberId) return;
      // Use all outstanding invoices supplied by the account summary.
      final open = InvoiceAccounting.openInvoicesForAccount(
        invoices: summary?.invoices.isNotEmpty == true
            ? summary!.invoices
            : <InvoiceModel>[if (invoice != null && invoice.subscriberId == subscriberId) invoice],
        subscriberId: subscriberId,
      );
      // Refreshing account context must not replace the invoice the user opened.
      if (!autoSelectLatest) {
        final selected = state.openInvoices
            .where((item) => item.id == state.invoiceId)
            .firstOrNull;
        if (selected != null) {
          final refreshed = open.where((item) => item.id == selected.id).firstOrNull ??
              (invoice?.id == selected.id && invoice?.subscriberId == subscriberId && invoice?.meterId == selected.meterId
                  ? invoice! : selected);
          final accountInvoices = <InvoiceModel>[
            ...open.where((item) => item.id != refreshed.id),
            refreshed,
          ];
          final due = refreshed.payableAmount;
          emit(
            state.copyWith(
              loadingInvoices: false,
              openInvoices: accountInvoices,
              dueAmount: due,
              amount: state.amount == state.dueAmount ? due : state.amount,
            ),
          );
          return;
        }
      }
      final selected = invoice != null && invoice.subscriberId == subscriberId
          ? invoice
          : InvoiceAccounting.latestUnpaidOf(subscriberId, open);
      final due = summary?.invoices.isNotEmpty == true
          ? summary!.dueAmount
          : (selected?.payableAmount ?? summary?.dueAmount ?? 0);

      emit(
        state.copyWith(
          loadingInvoices: false,
          openInvoices: open,
          clearInvoice: selected == null,
          invoiceId: selected?.id,
          dueAmount: due,
          amount: due,
        ),
      );
    } catch (error) {
      if (isClosed || state.subscriberId != subscriberId) return;
      emit(state.copyWith(loadingInvoices: false, validationMessage: '$error'));
    }
  }

  void selectInvoice(int? invoiceId) {
    if (invoiceId == null) {
      emit(
        state.copyWith(
          clearInvoice: true,
          dueAmount: 0,
          amount: 0,
          openInvoices: state.openInvoices,
        ),
      );
      return;
    }
    final matches = state.openInvoices.where((i) => i.id == invoiceId).toList();
    if (matches.isEmpty) {
      emit(state.copyWith(clearInvoice: true, dueAmount: 0, amount: 0));
      return;
    }
    final invoice = matches.first;
    emit(
      state.copyWith(
        invoiceId: invoiceId,
        dueAmount: invoice.payableAmount,
        amount: invoice.payableAmount,
      ),
    );
  }

  /// خيار «تطبيق على الأقدم تلقائياً (FIFO)».
  void useFifoAutomatically() {
    final due = _totalDueOfOpen();
    emit(state.copyWith(clearInvoice: true, dueAmount: due, amount: due));
  }

  void setAmount(double value) =>
      emit(state.copyWith(amount: value, clearValidation: true));

  void fillFullDue() => emit(state.copyWith(amount: state.dueAmount));

  void fillHalfDue() => emit(
    state.copyWith(
      amount: double.parse((state.dueAmount / 2).toStringAsFixed(2)),
    ),
  );

  void resetAmount() => emit(state.copyWith(amount: 0));

  void setMethod(PaymentMethod method) {
    emit(
      state.copyWith(
        method: method,
        // عند التحول إلى نقدي يُلغى الالتزام ويُبقى الرقم للمرجع الاختياري.
        clearValidation: true,
      ),
    );
  }

  void setReferenceNumber(String value) =>
      emit(state.copyWith(referenceNumber: value));

  void setReason(String value) => emit(state.copyWith(reason: value));

  void setNotes(String value) => emit(state.copyWith(notes: value));

  void setDate(DateTime date) => emit(state.copyWith(paymentDate: date));

  double _totalDueOfOpen() => state.openInvoices.fold<double>(
    0,
    (sum, invoice) => sum + invoice.ownRemaining,
  );

  CollectPaymentRequest buildRequest() {
    final subscriberId = state.subscriberId;
    if (subscriberId == null) {
      throw const ValidationFailure(
        'اختر المشترك أولاً.',
        <String, List<String>>{},
      );
    }
    return CollectPaymentRequest(
      subscriberId: subscriberId,
      invoiceId: state.invoiceId,
      meterId: state.openInvoices.where((i) => i.id == state.invoiceId).firstOrNull?.meterId ??
          (state.invoiceId == null ? null : _meterId),
      amount: state.amount,
      method: state.method,
      referenceNumber: state.referenceNumber.trim().isEmpty
          ? null
          : state.referenceNumber.trim(),
      reason: state.reason.trim().isEmpty ? null : state.reason.trim(),
      notes: state.notes.trim().isEmpty ? null : state.notes.trim(),
      paymentDate: state.paymentDate ?? DateTime.now(),
    );
  }

  /// يعيد `true` عند نجاح الحفظ (أو الإدراج في طابور المزامنة).
  Future<bool> submit() async {
    if (isClosed || state.submitting) return false;
    final validation = state.validationMessage;
    if (validation != null) return false;

    late final CollectPaymentRequest request;
    try {
      request = buildRequest();
    } on ValidationFailure catch (failure) {
      emit(state.copyWith(validationMessage: failure.message));
      return false;
    }

    final error = request.validate();
    if (error != null) {
      emit(state.copyWith(validationMessage: error));
      return false;
    }

    try {
      _cashSessions?.ensureCanCollect();
    } on CashSessionRequiredException catch (error) {
      emit(state.copyWith(validationMessage: error.message));
      return false;
    }

    emit(state.copyWith(submitting: true, clearValidation: true));
    try {
      final outcome = await _payments.collect(request);
      if (isClosed) return outcome.isSuccess;
      emit(state.copyWith(submitting: false, outcome: outcome));
      return outcome.isSuccess;
    } on AppFailure catch (failure) {
      if (isClosed) return false;
      emit(
        state.copyWith(submitting: false, validationMessage: failure.message),
      );
      return false;
    } catch (error) {
      if (isClosed) return false;
      emit(
        state.copyWith(
          submitting: false,
          validationMessage: 'فشل الحفظ: $error',
        ),
      );
      return false;
    }
  }
}
