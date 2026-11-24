import 'dart:async';

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/error/failures.dart';
import '../../../../core/finance/finance_feedback.dart';
import '../../../../core/finance/finance_locator.dart';
import '../../../../core/services/esc_pos/thermal_ticket.dart';
import '../../../../core/services/pdf_print_service.dart';
import '../../../../core/services/thermal_printer_service.dart';
import '../../../../core/services/whatsapp_launcher_service.dart';
import '../../../../core/settings/finance_config.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tokens.dart';
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
    // أثر «ما وصل» (الفصل 6.2): فتح واتساب = تسليم يدوي، نسجّله فوراً.
    unawaited(_reportWhatsapp(payment, via: 'click_to_chat'));
    AppFeedback.success(context, 'تم فتح واتساب بنص السند.');
    return;
  }
  if (result.outcome == WhatsAppSendOutcome.sentByApi) {
    unawaited(_reportWhatsapp(payment, via: 'meta_api'));
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

/// تسجيل نتيجة الإرسال بلا تعطيل واجهة المحصّل: لو فشل الاتصال يبقى الأثر في
/// سجل التدقيق المحلي ويُرفع لاحقاً مع بقية الأحداث.
Future<void> _reportWhatsapp(PaymentModel payment, {required String via}) async {
  try {
    await sl<PaymentsRepository>().recordWhatsappSend(payment.id, via: via);
  } catch (_) {
    // لا نُفشل الإرسال بعد نجاحه.
  }
}

/// حوار سبب الإلغاء — لا يقبل أقل من 10 أحرف تطابق قاعدة الخادم.
///
/// يعيد السبب المُدخل أو `null` عند الإلغاء.
Future<String?> askVoidReason(BuildContext context, PaymentModel payment) {
  final controller = TextEditingController();
  final formKey = GlobalKey<FormState>();

  return showDialog<String>(
    context: context,
    builder: (dialogContext) => AlertDialog(
      title: const Text('إلغاء سند القبض'),
      content: Form(
        key: formKey,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Text(
              'السند ${payment.receiptNumber} بقيمة ${payment.amount.toStringAsFixed(2)} '
              'سيُعلَّم ملغىً، وتُعاد الفواتير والأرصدة إلى ما كانت عليه. '
              'يبقى السند في السجل مع سبب الإلغاء.',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
            const SizedBox(height: 16),
            TextFormField(
              controller: controller,
              autofocus: true,
              maxLines: 3,
              maxLength: 500,
              textInputAction: TextInputAction.done,
              decoration: const InputDecoration(
                labelText: 'سبب الإلغاء (إلزامي)',
                hintText: 'مثال: سُجّل السند على مشترك خطأ أثناء التحصيل الميداني',
                border: OutlineInputBorder(),
              ),
              validator: (value) {
                final text = value?.trim() ?? '';
                if (text.length < 10) {
                  return 'اكتب سبباً واضحاً لا يقل عن 10 أحرف.';
                }
                return null;
              },
            ),
          ],
        ),
      ),
      actions: <Widget>[
        TextButton(
          onPressed: () => Navigator.of(dialogContext).pop(),
          child: const Text('تراجع'),
        ),
        FilledButton(
          style: FilledButton.styleFrom(backgroundColor: AppColors.danger),
          onPressed: () {
            if (formKey.currentState?.validate() != true) return;
            Navigator.of(dialogContext).pop(controller.text.trim());
          },
          child: const Text('تأكيد الإلغاء'),
        ),
      ],
    ),
  );
}

/// إلغاء سند قبض مع تسجيل السبب (خطة 2.0 — الفصل 5.3).
///
/// يعيد `true` عند النجاح حتى تحدّث الشاشة قائمتها.
Future<bool> voidPaymentWithReason(
  BuildContext context,
  PaymentModel payment,
) async {
  final reason = await askVoidReason(context, payment);
  if (reason == null || reason.trim().length < 10) return false;
  if (!context.mounted) return false;

  try {
    final outcome = await sl<PaymentsRepository>().voidPayment(payment.id, reason: reason);
    if (!context.mounted) return true;
    if (outcome.queuedOffline) {
      AppFeedback.info(
        context,
        'لا توجد شبكة الآن. حُفظ طلب الإلغاء في طابور المزامنة وسيُرسل '
        'تلقائياً عند عودة الاتصال؛ السند ما زال صالحاً حتى ذلك الحين.',
      );
      return false;
    }
    if (outcome.pendingApproval) {
      AppFeedback.info(
        context,
        'مبلغ السند يتجاوز حدّ الإلغاء المسموح. أُرسل طلب إلغاء للمشرف، '
        'والسند ما زال صالحاً حتى القرار.',
      );
      return false;
    }
    AppFeedback.success(context, 'أُلغي السند وسُجّل السبب في سجل التدقيق.');
    return true;
  } on AppFailure catch (failure) {
    if (!context.mounted) return false;
    AppFeedback.error(context, failure.message);
    return false;
  }
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
    if (!payment.isVoided)
      _PaymentSheetAction(
        icon: Icons.edit_outlined,
        label: 'تعديل الملاحظات',
        run: () => context.push('/payments/${payment.id}/edit', extra: payment),
      ),
    if (!payment.isVoided)
      _PaymentSheetAction(
        icon: Icons.block_outlined,
        label: 'إلغاء السند بسبب مكتوب',
        destructive: true,
        run: () async {
          final cancelled = await voidPaymentWithReason(context, payment);
          if (cancelled) await onDeleted?.call();
        },
      ),
  ];

  if (!context.mounted) return;
  final theme = Theme.of(context);
  await showModalBottomSheet<void>(
    context: context,
    showDragHandle: true,
    shape: const RoundedRectangleBorder(
      borderRadius: BorderRadius.vertical(top: Radius.circular(22)),
    ),
    builder: (sheetContext) => SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 4, 16, 12),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Row(
              children: <Widget>[
                const AppGlyph(icon: Icons.receipt_long_outlined, size: 40),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      Text(
                        'سند ${payment.receiptNumber}',
                        style: theme.textTheme.titleMedium
                            ?.copyWith(fontWeight: FontWeight.w600),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        payment.displayName,
                        style: theme.textTheme.bodySmall,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            ...actions.map(
              (action) => _PaymentSheetActionRow(
                icon: action.icon,
                label: action.label,
                color: action.destructive ? AppColors.danger : null,
                onTap: () {
                  Navigator.of(sheetContext).pop();
                  action.run();
                },
              ),
            ),
          ],
        ),
      ),
    ),
  );
}

/// صف إجراء داخل ورقة السند — نفس لغة `_SheetActionRow` في invoice_actions:
/// AppGlyph + تسمية + سهم RTL، بدل ListTile الخام.
class _PaymentSheetActionRow extends StatelessWidget {
  const _PaymentSheetActionRow({
    required this.icon,
    required this.label,
    required this.onTap,
    this.color,
  });

  final IconData icon;
  final String label;
  final void Function() onTap;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return InkWell(
      onTap: () => onTap(),
      borderRadius: BorderRadius.circular(AppRadius.md),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 10),
        child: Row(
          children: <Widget>[
            AppGlyph(icon: icon, tone: color, size: 36),
            const SizedBox(width: 12),
            Expanded(
              child: Text(
                label,
                style: theme.textTheme.bodyMedium?.copyWith(
                  fontWeight: FontWeight.w500,
                  color: color,
                ),
              ),
            ),
            Icon(
              Icons.chevron_left_rounded,
              size: 18,
              color: theme.colorScheme.onSurfaceVariant.withValues(alpha: .7),
            ),
          ],
        ),
      ),
    );
  }
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
