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
       _cashSessions = cashSessionService,
       super(CollectPaymentState(paymentDate: DateTime.now()));

  final PaymentsRepository _payments;
  final ReferencesRemoteDataSource _references;

  /// حاجز «لا تحصيل بلا صندوق» (خطة 2.0 — الفصل 5.2). اختياري حتى لا تتعطل
  /// الشاشات القديمة، لكنه مربوط في كل مسارات الإنتاج عبر DI.
  final CashSessionService? _cashSessions;

  // A route meter hint is not authorization to target an invoice. The actual
  // selected invoice determines its own meter reference.
  int _accountLoadSequence = 0;
  bool _contextUsable = false;

  /// Explicit invoice entry: never replace the caller's selection with the latest invoice.
  Future<void> openForInvoice(InvoiceModel invoice) async {
    if (isClosed || state.submitting) return;
    final sequence = ++_accountLoadSequence;
    _contextUsable = false;
    emit(CollectPaymentState(
      subscriberId: invoice.subscriberId,
      subscriberName: invoice.beneficiaryName,
      invoiceId: invoice.id,
      dueAmount: invoice.payableAmount,
      amount: invoice.payableAmount,
      openInvoices: [invoice],
      paymentDate: DateTime.now(),
      loadingInvoices: true,
    ));
    await _loadAccountInvoices(invoice.subscriberId, sequence, explicitInvoice: invoice);
  }

  Future<void> openForSubscriber(int subscriberId, {String? name}) async {
    if (isClosed || state.submitting) return;
    final sequence = ++_accountLoadSequence;
    _contextUsable = false;
    // A different account must not inherit an invoice, reference, amount,
    // note, name or successful outcome from the previous account.
    emit(CollectPaymentState(
      subscriberId: subscriberId,
      subscriberName: name,
      paymentDate: DateTime.now(),
      loadingInvoices: true,
    ));
    await _loadAccountInvoices(subscriberId, sequence);
  }

  bool _currentAccount(int subscriberId, int sequence) =>
      !isClosed && sequence == _accountLoadSequence && state.subscriberId == subscriberId;

  Future<void> _loadAccountInvoices(
    int subscriberId,
    int sequence, {
    InvoiceModel? explicitInvoice,
  }) async {
    try {
      final summary = await _references.dueSummary(subscriberId);
      if (!_currentAccount(subscriberId, sequence)) return;
      final latest = await _payments.latestUnpaidInvoice(subscriberId);
      if (!_currentAccount(subscriberId, sequence)) return;

      if ((summary != null && (summary.subscriberId != subscriberId ||
              summary.invoices.any((item) => item.subscriberId != subscriberId))) ||
          (latest != null && latest.subscriberId != subscriberId)) {
        throw const ValidationFailure('تعذر التحقق من حساب المشترك.', <String, List<String>>{});
      }

      final invoices = summary?.invoices.isNotEmpty == true
          ? summary!.invoices
          : <InvoiceModel>[if (latest != null) latest];
      final open = InvoiceAccounting.openInvoicesForAccount(
        invoices: invoices, subscriberId: subscriberId,
      );

      if (explicitInvoice != null) {
        final candidates = <InvoiceModel>[
          ...invoices.where((item) => item.id == explicitInvoice.id),
          if (latest?.id == explicitInvoice.id) latest!,
        ];
        if (candidates.any((item) => item.meterId != explicitInvoice.meterId)) {
          throw const ValidationFailure('تعذر التحقق من هوية الفاتورة.', <String, List<String>>{});
        }
        final selected = candidates.firstOrNull ?? explicitInvoice;
        if (selected.isDeleted || selected.status == InvoiceStatus.draft ||
            selected.status == InvoiceStatus.cancelled) {
          throw const ValidationFailure('الفاتورة غير صالحة للتحصيل.', <String, List<String>>{});
        }
        final due = selected.payableAmount;
        _contextUsable = true;
        emit(state.copyWith(
          loadingInvoices: false,
          invoiceId: selected.id,
          openInvoices: [...open.where((item) => item.id != selected.id), selected],
          dueAmount: due,
          amount: state.amount == state.dueAmount ? due : state.amount,
        ));
        return;
      }

      final selected = InvoiceAccounting.latestUnpaidOf(subscriberId, open);
      final due = summary?.invoices.isNotEmpty == true
          ? summary!.dueAmount
          : (selected?.payableAmount ?? summary?.dueAmount ?? 0);
      _contextUsable = true;
      emit(state.copyWith(
        loadingInvoices: false,
        openInvoices: open,
        clearInvoice: selected == null,
        invoiceId: selected?.id,
        dueAmount: due,
        amount: state.amount == state.dueAmount ? due : state.amount,
      ));
    } catch (error) {
      if (!_currentAccount(subscriberId, sequence)) return;
      _contextUsable = false;
      emit(state.copyWith(
        loadingInvoices: false,
        validationMessage: error is AppFailure ? error.message : '$error',
      ));
    }
  }

  void selectInvoice(int? invoiceId) {
    if (isClosed || state.submitting || !_contextUsable) return;
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
    if (isClosed || state.submitting || !_contextUsable) return;
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
    if (!_contextUsable || state.loadingInvoices) {
      throw const ValidationFailure('لم يتم التحقق من حساب المشترك بعد.', <String, List<String>>{});
    }
    final selected = state.openInvoices.where((i) =>
        i.id == state.invoiceId && i.subscriberId == subscriberId).firstOrNull;
    if (state.invoiceId != null && selected == null) {
      throw const ValidationFailure('الفاتورة لا تنتمي إلى حساب المشترك.', <String, List<String>>{});
    }
    return CollectPaymentRequest(
      subscriberId: subscriberId,
      invoiceId: state.invoiceId,
      meterId: selected?.meterId,
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
    if (isClosed || state.submitting || state.isSubmitted || state.loadingInvoices) return false;
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
