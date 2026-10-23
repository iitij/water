import 'package:flutter/material.dart';

import '../../../../core/finance/finance_feedback.dart';
import '../../../../core/finance/finance_locator.dart';
import '../../../../core/services/esc_pos/thermal_ticket.dart';
import '../../../../core/services/pdf_print_service.dart';
import '../../../../core/services/thermal_printer_service.dart';
import '../../../../core/services/whatsapp_launcher_service.dart';
import '../../../../core/settings/finance_config.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/widgets/pdf_preview_sheet.dart';
import '../../../../core/widgets/thermal_printer_prompt.dart';
import '../../../invoices/data/models/invoice_model.dart';
import '../../data/models/payment_model.dart';
import '../../domain/repositories/payments_repository.dart';
import '../thermal/payment_thermal_ticket.dart';
import '../whatsapp/payment_whatsapp_builder.dart';

/// إجراءات سند القبض (PDF / حراري / واتساب) — نفس العقد الذي توفره `invoice_actions`
/// للفواتير، ليستطيع Dev 4 وDev 5 استدعاءهما بالأسلوب نفسه.

/// معاينة وطباعة سند القبض (`A5 Landscape`).
Future<void> openPaymentPdfPreview(BuildContext context, PaymentModel payment) {
  final repository = sl<PaymentsRepository>();
  return PdfPreviewSheet.open(
    context,
    title: 'سند قبض ${payment.receiptNumber}',
    service: sl<PdfPrintService>(),
    fileName: 'receipt-${payment.receiptNumber}.pdf',
    loader: () => sl<PdfPrintService>().fetchPdf(
      repository.pdfPath(payment.id),
      fallbackUrl: repository.printFallbackPath(payment.id),
    ),
  );
}

/// طباعة سند القبض على الطابعة الحرارية (58mm/80mm).
Future<void> printPaymentThermal(
  BuildContext context,
  PaymentModel payment, {
  ReceiptDataModel? receiptData,
  InvoiceModel? invoice,
}) async {
  final config = sl<FinanceConfigRepository>().cached;
  final service = sl<ThermalPrinterService>();

  ThermalTicket ticket() => buildPaymentThermalTicket(
        payment,
        receiptData: receiptData,
        invoice: invoice,
        projectName: config.projectName,
      );

  final result = await service.printTicket(ticket());
  if (!context.mounted) return;

  if (result.isSuccess) {
    AppFeedback.success(context, result.message ?? 'تمت طباعة السند.');
    return;
  }

  if (result.status == ThermalPrintStatus.notConnected) {
    final address = await promptThermalPrinterAddress(context, service);
    if (!context.mounted) return;
    if (address == null || address.isEmpty) {
      AppFeedback.error(context, 'لم تُاختر طابعة حرارية.');
      return;
    }
    final retry = await service.printTicket(ticket());
    if (!context.mounted) return;
    if (retry.isSuccess) {
      AppFeedback.success(context, 'تمت طباعة السند على ${retry.deviceAddress}');
    } else {
      AppFeedback.error(context, retry.message ?? 'تعذّرت الطباعة الحرارية.');
    }
    return;
  }

  AppFeedback.error(context, result.message ?? 'تعذّرت الطباعة الحرارية.');
}

/// إرسال رسالة السند حسب طريقة النظام.
Future<void> sendPaymentWhatsapp(
  BuildContext context,
  PaymentModel payment, {
  ReceiptDataModel? receiptData,
  InvoiceModel? invoice,
}) async {
  final configRepository = sl<FinanceConfigRepository>();
  final config = await configRepository.refresh();
  final result = await sl<WhatsAppLauncherService>().send(
    PaymentWhatsAppBuilder(configRepository: configRepository).build(
      payment,
      overrideConfig: config,
      receiptData: receiptData,
      invoice: invoice,
    ),
  );
  if (!context.mounted) return;

  if (result.outcome == WhatsAppSendOutcome.launched) {
    AppFeedback.success(context, 'تم فتح واتساب بنص السند.');
    return;
  }
  if (result.outcome == WhatsAppSendOutcome.sentByApi) {
    AppFeedback.success(context, 'أرسل الخادم السند PDF عبر واتساب.');
    return;
  }
  if (result.outcome == WhatsAppSendOutcome.noPhone) {
    AppFeedback.info(context, 'لا يوجد رقم هاتف — انسخ نص السند يدوياً.');
    return;
  }
  if (result.outcome == WhatsAppSendOutcome.disabled) {
    AppFeedback.info(context, 'واتساب معطّل من إعدادات النظام.');
    return;
  }
  AppFeedback.error(context, result.error ?? 'فشل الإرسال.');
}

/// قائمة إجراءات السند (ضغطة طويلة على بطاقته أو من شاشة التفاصيل).
Future<void> showPaymentActionSheet(
  BuildContext context, {
  required PaymentModel payment,
  ReceiptDataModel? receiptData,
  InvoiceModel? invoice,
  Future<void> Function()? onDeleted,
}) async {
  final config = await sl<FinanceConfigRepository>().refresh();

  final actions = <_PaymentSheetAction>[
    _PaymentSheetAction(
      icon: Icons.picture_as_pdf_outlined,
      label: 'عرض سند القبض PDF',
      run: () => openPaymentPdfPreview(context, payment),
    ),
    _PaymentSheetAction(
      icon: Icons.print_outlined,
      label: 'طباعة حرارية',
      run: () => printPaymentThermal(context, payment, receiptData: receiptData),
    ),
    if (config.whatsappEnabled)
      _PaymentSheetAction(
        icon: Icons.chat_outlined,
        label: 'إرسال واتساب',
        run: () => sendPaymentWhatsapp(context, payment, receiptData: receiptData),
      ),
    if (onDeleted != null)
      _PaymentSheetAction(
        icon: Icons.delete_outline,
        label: 'حذف السند',
        destructive: true,
        run: () async {
          final confirmed = await ConfirmDialog.show(
            context,
            title: 'حذف سند قبض',
            message: 'سيُحذف السند حذفًا ناعمًا ويُعاد احتساب الفواتير المرتبطة به.',
            confirmLabel: 'حذف',
            destructive: true,
          );
          if (!confirmed) return;
          await onDeleted();
        },
      ),
  ];

  if (!context.mounted) return;
  await showModalBottomSheet<void>(
    context: context,
    showDragHandle: true,
    builder: (sheetContext) => SafeArea(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          ListTile(
            leading: const Icon(Icons.receipt_long_outlined),
            title: Text('سند ${payment.receiptNumber}'),
            subtitle: Text(payment.displayName),
          ),
          const Divider(height: 1),
          ...actions.map(
            (action) => ListTile(
              leading: Icon(
                action.icon,
                color: action.destructive ? AppColors.danger : null,
              ),
              title: Text(action.label),
              onTap: () {
                Navigator.of(sheetContext).pop();
                action.run();
              },
            ),
          ),
          const SizedBox(height: 8),
        ],
      ),
    ),
  );
}

class _PaymentSheetAction {
  const _PaymentSheetAction({
    required this.icon,
    required this.label,
    required this.run,
    this.destructive = false,
  });

  final IconData icon;
  final String label;
  final VoidCallback run;
  final bool destructive;
}
