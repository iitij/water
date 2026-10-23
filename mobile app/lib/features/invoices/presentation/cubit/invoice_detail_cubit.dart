import 'dart:typed_data';

import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/error/failures.dart';
import '../../../../core/services/pdf_print_service.dart';
import '../../../../core/services/thermal_printer_service.dart';
import '../../../../core/services/whatsapp_launcher_service.dart';
import '../../../../core/utils/json_utils.dart';
import '../../data/models/invoice_model.dart';
import '../../domain/accounting/finance_enums.dart';
import '../../domain/repositories/invoices_repository.dart';
import '../thermal/invoice_thermal_ticket.dart';
import '../whatsapp/invoice_whatsapp_builder.dart';
import 'invoice_detail_state.dart';
import 'invoices_state.dart';

/// `InvoiceDetailCubit` — تحميل الفاتورة + إجراءات SCR-15:
/// إعادة الاحتساب، التعديل اليدوي، الحذف، طباعة PDF، طباعة حرارية، وواتساب.
class InvoiceDetailCubit extends Cubit<InvoiceDetailState> {
  InvoiceDetailCubit({
    required int invoiceId,
    required InvoicesRepository repository,
    required PdfPrintService pdfService,
    required ThermalPrinterService thermalService,
    required WhatsAppLauncherService whatsappService,
    required InvoiceWhatsAppBuilder whatsappBuilder,
  })  : _repository = repository,
        _pdf = pdfService,
        _thermal = thermalService,
        _whatsapp = whatsappService,
        _whatsappBuilder = whatsappBuilder,
        super(InvoiceDetailState(invoiceId: invoiceId));

  final InvoicesRepository _repository;
  final PdfPrintService _pdf;
  final ThermalPrinterService _thermal;
  final WhatsAppLauncherService _whatsapp;
  final InvoiceWhatsAppBuilder _whatsappBuilder;

  Future<void> load() async {
    emit(state.copyWith(status: FinanceLoadStatus.loading, clearError: true));
    try {
      final invoice = await _repository.byId(state.invoiceId);
      emit(state.copyWith(invoice: invoice, status: FinanceLoadStatus.ready));
    } on AppFailure catch (failure) {
      emit(state.copyWith(status: FinanceLoadStatus.failure, errorMessage: failure.message));
    }
  }

  Future<bool> saveManualEdit(InvoiceModel invoice) async {
    emit(state.copyWith(actionRunning: true, clearError: true));
    try {
      final updated = await _repository.update(invoice.id, invoice.toUpdatePayload());
      emit(state.copyWith(
        invoice: updated,
        actionRunning: false,
        lastActionLabel: 'تم حفظ التعديلات',
      ));
      return true;
    } on AppFailure catch (failure) {
      emit(state.copyWith(
        actionRunning: false,
        errorMessage: failure.message,
      ));
      return false;
    }
  }

  /// `Recalculate` — يعيد توزيع الدفعات على هذا الحساب المعزول.
  Future<bool> recalculate() async {
    final invoice = state.invoice;
    if (invoice == null) return false;
    emit(state.copyWith(actionRunning: true, clearError: true));
    try {
      final updated = await _repository.recalculate(invoice.id);
      emit(state.copyWith(
        invoice: updated,
        actionRunning: false,
        lastActionLabel: 'تمت إعادة الاحتساب',
      ));
      return true;
    } on AppFailure catch (failure) {
      emit(state.copyWith(actionRunning: false, errorMessage: failure.message));
      return false;
    }
  }

  Future<bool> delete() async {
    final invoice = state.invoice;
    if (invoice == null) return false;
    emit(state.copyWith(actionRunning: true, clearError: true));
    try {
      await _repository.delete(invoice.id);
      emit(state.copyWith(actionRunning: false, lastActionLabel: 'تم حذف الفاتورة'));
      return true;
    } on AppFailure catch (failure) {
      emit(state.copyWith(actionRunning: false, errorMessage: failure.message));
      return false;
    }
  }

  // ─── الطباعة المزدوجة ────────────────────────────────────────────────────
  Future<Uint8List?> loadPdfBytes() async {
    final invoice = state.invoice;
    if (invoice == null) return null;
    emit(state.copyWith(printingBusy: true, clearError: true));
    try {
      final bytes = await _pdf.fetchPdf(
        _repository.pdfPath(invoice.id),
        fallbackUrl: _repository.printFallbackPath(invoice.id),
      );
      emit(state.copyWith(printingBusy: false));
      return bytes;
    } on AppFailure catch (failure) {
      emit(state.copyWith(printingBusy: false, errorMessage: failure.message));
      return null;
    }
  }

  /// يبني بايتات ESC/POS للطباعة الحرارية (يُنفَّذ الإرسال داخل [printThermal]).
  Future<ThermalPrintResult> printThermal() async {
    final invoice = state.invoice;
    if (invoice == null) {
      return const ThermalPrintResult(
        ThermalPrintStatus.failed,
        message: 'الفاتورة غير محمّلة بعد.',
      );
    }
    emit(state.copyWith(printingBusy: true));
    final result = await _thermal.printTicket(buildInvoiceThermalTicket(invoice));
    emit(state.copyWith(
      printingBusy: false,
      lastActionLabel: result.isSuccess ? 'تمت الطباعة الحرارية' : result.message,
      errorMessage: result.isSuccess ? null : result.message,
    ));
    return result;
  }

  // ─── الواتساب ──────────────────────────────────────────────────────────
  Future<WhatsAppSendResult> sendWhatsapp() async {
    final invoice = state.invoice;
    if (invoice == null) {
      return const WhatsAppSendResult(
        WhatsAppSendOutcome.failed,
        error: 'الفاتورة غير محمّلة بعد.',
      );
    }
    emit(state.copyWith(whatsappBusy: true, clearError: true));
    final payload = _whatsappBuilder.build(invoice);
    final result = await _whatsapp.send(payload);
    emit(state.copyWith(
      whatsappBusy: false,
      lastActionLabel: result.isSuccess ? 'تم إرسال الفاتورة' : null,
      errorMessage: result.isSuccess
          ? null
          : (result.error ?? 'تعذّر إرسال الواتساب — تأكد من تفعيله في الإعدادات.'),
    ));
    return result;
  }

  /// نص الرسالة (لمعاينته قبل الإرسال أو نسخه يدوياً).
  String previewWhatsappMessage(InvoiceModel invoice) =>
      _whatsappBuilder.build(invoice).message;

  /// تحديث محلي بعد الدفع من `CollectPaymentSheet` داخل نفس الشاشة.
  void applyPayment({required double paidAmount, required String? invoiceStatus}) {
    final invoice = state.invoice;
    if (invoice == null) return;
    emit(state.copyWith(
      invoice: invoice.copyWith(
        amountPaid: invoice.amountPaid + paidAmount,
        status: invoiceStatus == null
            ? invoice.status
            : InvoiceStatus.parse(invoiceStatus),
      ),
    ));
  }

  Map<String, dynamic> debugSnapshot() => state.invoice?.toJson() ?? const <String, dynamic>{};

  static String formatDay(DateTime? date) => JsonUtils.asString(DateFormat.day(date));
}
