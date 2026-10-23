import 'package:equatable/equatable.dart';

import '../../data/models/invoice_model.dart';
import 'invoices_state.dart';

/// حالة شاشة تفاصيل الفاتورة (`SCR-15`).
class InvoiceDetailState extends Equatable {
  const InvoiceDetailState({
    required this.invoiceId,
    this.invoice,
    this.status = FinanceLoadStatus.idle,
    this.errorMessage,
    this.actionRunning = false,
    this.lastActionLabel,
    this.printingBusy = false,
    this.whatsappBusy = false,
  });

  final int invoiceId;
  final InvoiceModel? invoice;
  final FinanceLoadStatus status;
  final String? errorMessage;

  /// إعادة احتساب/حفظ/حذف قيد التنفيذ.
  final bool actionRunning;
  final String? lastActionLabel;
  final bool printingBusy;
  final bool whatsappBusy;

  bool get isLoading => status == FinanceLoadStatus.loading;
  bool get hasInvoice => invoice != null;

  /// زر «تحصيل» يظهر فقط إذا كانت الفاتورة مفتوحة **و** الأحدث في حسابها
  /// (قاعدة `collect_payment` في `InvoiceResource#L372` + `isLatestInvoice()`).
  bool get canCollect =>
      invoice != null &&
      invoice!.status.isOpenForCollection &&
      (invoice!.isLatestInvoice ?? true);

  InvoiceDetailState copyWith({
    InvoiceModel? invoice,
    FinanceLoadStatus? status,
    String? errorMessage,
    bool clearError = false,
    bool? actionRunning,
    String? lastActionLabel,
    bool? printingBusy,
    bool? whatsappBusy,
  }) =>
      InvoiceDetailState(
        invoiceId: invoiceId,
        invoice: invoice ?? this.invoice,
        status: status ?? this.status,
        errorMessage: clearError ? null : (errorMessage ?? this.errorMessage),
        actionRunning: actionRunning ?? this.actionRunning,
        lastActionLabel: lastActionLabel ?? this.lastActionLabel,
        printingBusy: printingBusy ?? this.printingBusy,
        whatsappBusy: whatsappBusy ?? this.whatsappBusy,
      );

  @override
  List<Object?> get props => <Object?>[
        invoiceId,
        invoice,
        status,
        errorMessage,
        actionRunning,
        lastActionLabel,
        printingBusy,
        whatsappBusy,
      ];
}
