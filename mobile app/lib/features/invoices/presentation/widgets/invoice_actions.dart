import 'package:flutter/material.dart';

import '../../../../core/finance/finance_feedback.dart';
import '../../../../core/finance/finance_locator.dart';
import '../../../../core/services/pdf_print_service.dart';
import '../../../../core/services/thermal_printer_service.dart';
import '../../../../core/services/whatsapp_launcher_service.dart';
import '../../../../core/settings/finance_config.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../../core/widgets/pdf_preview_sheet.dart';
import '../../../payments/presentation/sheets/collect_payment_sheet.dart';
import '../../data/models/invoice_model.dart';
import '../../data/datasources/invoices_remote_datasource.dart';
import '../../../../core/error/failures.dart';
import '../../domain/repositories/invoices_repository.dart';
import '../cubit/invoices_cubit.dart';
import '../thermal/invoice_thermal_ticket.dart';
import '../whatsapp/invoice_whatsapp_builder.dart';

/// مجموعة إجراءات الفاتورة (تحصيل / PDF / حراري / واتساب / إعادة احتساب / حذف)
/// معبّأة في دوال واحدة يستدعيها: `SCR-13`, `SCR-14`, `SCR-15`, وودجات
/// فؤاد (`SCR-05/07/19/20`) وأسامة (`SCR-03`).

/// معاينة وطباعة الـ PDF الرسمي (`A5 Landscape`).
Future<void> openInvoicePdfPreview(BuildContext context, InvoiceModel invoice) {
  final repository = sl<InvoicesRepository>();
  return PdfPreviewSheet.open(
    context,
    title: 'فاتورة ${invoice.billNumber}',
    service: sl<PdfPrintService>(),
    fileName: 'invoice-${invoice.billNumber}.pdf',
    loader: () => sl<PdfPrintService>().fetchPdf(
      repository.pdfPath(invoice.id),
      fallbackUrl: repository.printFallbackPath(invoice.id),
    ),
  );
}

/// الطباعة الحرارية عبر البلوتوث (58mm/80mm) مع التفقيط العربي.
Future<void> printInvoiceThermal(BuildContext context, InvoiceModel invoice) async {
  final service = sl<ThermalPrinterService>();
  final result = await service.printTicket(buildInvoiceThermalTicket(invoice));
  if (!context.mounted) return;

  if (result.isSuccess) {
    AppFeedback.success(context, result.message ?? 'تمت الطباعة.');
    return;
  }

  if (result.status == ThermalPrintStatus.notConnected) {
    final address = await _promptPrinterAddress(context, service);
    if (!context.mounted) return;
    if (address == null || address.isEmpty) {
      AppFeedback.error(context, result.message ?? 'لم تُاختر طابعة.');
      return;
    }
    final retry = await service.printTicket(buildInvoiceThermalTicket(invoice));
    if (!context.mounted) return;
    if (retry.isSuccess) {
      AppFeedback.success(context, 'تمت الطباعة على ${retry.deviceAddress}');
    } else {
      AppFeedback.error(context, retry.message ?? 'تعذّرت الطباعة الحرارية.');
    }
    return;
  }

  AppFeedback.error(context, result.message ?? 'تعذّرت الطباعة الحرارية.');
}

Future<String?> _promptPrinterAddress(
  BuildContext context,
  ThermalPrinterService service,
) async {
  final controller = TextEditingController(text: service.savedAddress ?? '');
  final confirmed = await showDialog<bool>(
    context: context,
    builder: (dialogContext) => AlertDialog(
      title: const Text('طابعة البلوتوث الحرارية'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          const Text(
            'أدخل عنوان MAC للطابعة المقترنة بجهازك، مثال: 00:11:22:33:44:55',
          ),
          const SizedBox(height: 12),
          TextField(
            controller: controller,
            autofillHints: const <String>[],
            decoration: const InputDecoration(labelText: 'عنوان الطابعة'),
          ),
        ],
      ),
      actions: <Widget>[
        TextButton(
          onPressed: () => Navigator.of(dialogContext).pop(false),
          child: const Text('إلغاء'),
        ),
        FilledButton(
          onPressed: () => Navigator.of(dialogContext).pop(true),
          child: const Text('حفظ وطباعة'),
        ),
      ],
    ),
  );

  if (confirmed != true) return null;
  final address = controller.text.trim();
  if (address.isEmpty) return null;
  await service.saveAddress(address);
  return address;
}

/// إرسال واتساب حسب طريقة النظام: `click_to_chat` (رابط مباشر) أو
/// `meta_api`/`local_api` (إرسال PDF من الخادم).
Future<void> sendInvoiceWhatsapp(BuildContext context, InvoiceModel invoice) async {
  final configRepository = sl<FinanceConfigRepository>();
  final config = await configRepository.refresh();
  final result = await sl<WhatsAppLauncherService>().send(
    InvoiceWhatsAppBuilder(configRepository: configRepository).build(
      invoice,
      overrideConfig: config,
    ),
  );
  if (!context.mounted) return;

  if (result.outcome == WhatsAppSendOutcome.launched) {
    AppFeedback.success(context, 'تم فتح واتساب بنص الفاتورة.');
    return;
  }
  if (result.outcome == WhatsAppSendOutcome.sentByApi) {
    AppFeedback.success(context, 'أرسل الخادم الفاتورة PDF عبر واتساب.');
    return;
  }
  if (result.outcome == WhatsAppSendOutcome.noPhone) {
    AppFeedback.error(context, 'لا يوجد رقم هاتف صالح للمشترك.');
    return;
  }
  if (result.outcome == WhatsAppSendOutcome.disabled) {
    AppFeedback.info(context, 'واتساب معطّل من إعدادات النظام.');
    return;
  }
  AppFeedback.error(context, result.error ?? 'فشل الإرسال.');
}

/// تحصيل دفعة — يفتح `CollectPaymentSheet` ثم يحدّث القائمة محلياً إن وُجد cubit.
Future<bool> collectInvoicePayment(
  BuildContext context,
  InvoiceModel invoice, {
  InvoicesCubit? cubit,
}) async {
  final saved = await CollectPaymentSheet.show(context, invoice: invoice);
  if (!saved) return false;

  try {
    final updated = await sl<InvoicesRepository>().byId(invoice.id);
    cubit?.applyLocalInvoiceUpdate(updated);
  } on Object {
    // الحفظ نجح لكن جلب التحديث تعذّر (شبكة) — تبقى المعاينة المحلية كما هي.
  }
  return true;
}

/// Cancellation must be confirmed online; no optimistic ledger mutation.
Future<InvoiceModel?> cancelInvoiceWithReason(BuildContext context, InvoiceModel invoice) async {
  if (invoice.isDeleted || invoice.status.wire == 'draft' || invoice.status.wire == 'cancelled') return null;
  var reason = '';
  final confirmed = await showDialog<bool>(
    context: context,
    builder: (dialogContext) => AlertDialog(
      title: Text('إلغاء الفاتورة ${invoice.billNumber}'),
      content: TextField(
        maxLines: 3,
        maxLength: 1000,
        onChanged: (value) => reason = value.trim(),
        decoration: const InputDecoration(
          labelText: 'سبب الإلغاء (10 أحرف على الأقل)',
          helperText: 'سيبقى المستند الأصلي وتُسجل حركة عكسية.',
        ),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(dialogContext, false), child: const Text('رجوع')),
        FilledButton(onPressed: () => Navigator.pop(dialogContext, true), child: const Text('تأكيد الإلغاء')),
      ],
    ),
  );
  if (confirmed != true || !context.mounted) return null;
  if (reason.length < 10) {
    AppFeedback.error(context, 'سبب الإلغاء يجب ألا يقل عن 10 أحرف.');
    return null;
  }
  try {
    final updated = await sl<InvoicesRemoteDataSource>().cancel(invoice.id, reason);
    if (context.mounted) AppFeedback.success(context, 'أُلغيت الفاتورة بحركة عكسية موثقة.');
    return updated;
  } on AppFailure catch (failure) {
    if (context.mounted) AppFeedback.error(context, failure.message);
    return null;
  }
}

/// غلاف يربط بطاقة الفاتورة بإجراءاتها (يُستخدم في القوائم).
class InvoiceCardActions extends StatelessWidget {
  const InvoiceCardActions({
    super.key,
    required this.invoice,
    required this.siblings,
    required this.cubit,
    required this.child,
  });

  final InvoiceModel invoice;
  final List<InvoiceModel> siblings;
  final InvoicesCubit cubit;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return _ActionMenuHost(
      invoice: invoice,
      cubit: cubit,
      child: child,
    );
  }
}

/// قائمة منبثضة (Long-press / زر خيارات) لكل الإجراءات — تحلّ محل أعمدة الأزرار
/// المزدحمة على الهواتف، وتبقى كلها بلمسة واحدة من البطاقة نفسها.
class _ActionMenuHost extends StatelessWidget {
  const _ActionMenuHost({required this.invoice, required this.cubit, required this.child});

  final InvoiceModel invoice;
  final InvoicesCubit cubit;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onLongPress: () =>
          showInvoiceActionSheet(context, invoice: invoice, cubit: cubit),
      child: child,
    );
  }
}

/// قائمة الإجراءات الكاملة (يستعملها أيضاً زر الخيارات في `SCR-15`).
Future<void> showInvoiceActionSheet(
  BuildContext context, {
  required InvoiceModel invoice,
  InvoicesCubit? cubit,
}) async {
  final config = await sl<FinanceConfigRepository>().refresh();
  if (!context.mounted) return;

  final actions = <_SheetAction>[
    if (!invoice.isDeleted && invoice.status.isOpenForCollection)
      _SheetAction(
        icon: Icons.payments_outlined,
        label: 'تحصيل دفعة',
        color: Theme.of(context).colorScheme.primary,
        onTap: () => collectInvoicePayment(context, invoice, cubit: cubit),
      ),
    _SheetAction(
      icon: Icons.picture_as_pdf_outlined,
      label: 'معاينة وطباعة PDF',
      onTap: () => openInvoicePdfPreview(context, invoice),
    ),
    _SheetAction(
      icon: Icons.print_outlined,
      label: 'طباعة حرارية بلوتوث',
      onTap: () => printInvoiceThermal(context, invoice),
    ),
    if (!invoice.isDeleted && invoice.status.wire != 'cancelled' && config.whatsappEnabled)
      _SheetAction(
        icon: Icons.chat_outlined,
        label: config.isApiSend ? 'إرسال واتساب (PDF)' : 'إرسال واتساب',
        color: AppColors.whatsapp,
        onTap: () => sendInvoiceWhatsapp(context, invoice),
      ),
    if (!invoice.isDeleted && invoice.status.wire == 'draft')
      _SheetAction(
        icon: Icons.refresh,
        label: 'إعادة احتساب (Recalculate)',
        onTap: () async {
          final ok = await cubit?.recalculate(invoice.id);
          if (!context.mounted) return;
          if (ok == null) {
            AppFeedback.error(context, 'تعذّرت إعادة الاحتساب.');
          } else {
            AppFeedback.success(context, 'تمت إعادة احتساب المسودة.');
          }
        },
      ),
    if (!invoice.isDeleted && invoice.status.wire != 'draft' && invoice.status.wire != 'cancelled')
      _SheetAction(
        icon: Icons.block_outlined,
        label: 'إلغاء الفاتورة بحركة عكسية',
        color: Theme.of(context).colorScheme.error,
        onTap: () async {
          final updated = await cancelInvoiceWithReason(context, invoice);
          if (updated != null) cubit?.applyLocalInvoiceUpdate(updated);
        },
      ),
    if (!invoice.isDeleted && invoice.status.wire == 'draft' && cubit != null)
      _SheetAction(
        icon: Icons.delete_outline,
        label: 'حذف المسودة',
        color: Theme.of(context).colorScheme.error,
        onTap: () async {
          final confirmed = await ConfirmDialog.show(context,
            title: 'حذف مسودة الفاتورة', message: 'المسودة غير مرحلة مالياً.',
            confirmLabel: 'حذف', destructive: true);
          if (!confirmed) return;
          await cubit.deleteInvoice(invoice.id);
        },
      ),
  ];

  await showModalBottomSheet<void>(
    context: context,
    showDragHandle: true,
    shape: const RoundedRectangleBorder(
      borderRadius: BorderRadius.vertical(top: Radius.circular(AppRadius.xxl)),
    ),
    builder: (sheetContext) => SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(8, 0, 8, 12),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Padding(
              padding: const EdgeInsetsDirectional.fromSTEB(10, 4, 10, 8),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Text(
                    'فاتورة ${invoice.billNumber}',
                    style: Theme.of(sheetContext).textTheme.titleLarge?.copyWith(
                          fontWeight: FontWeight.w600,
                        ),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    invoice.beneficiaryName,
                    style: Theme.of(sheetContext).textTheme.bodySmall,
                  ),
                ],
              ),
            ),
            const Divider(height: 1),
            const SizedBox(height: 6),
            ...actions.map(
              (action) => _SheetActionRow(
                icon: action.icon,
                label: action.label,
                color: action.color,
                onTap: () async {
                  Navigator.of(sheetContext).pop();
                  await action.onTap();
                },
              ),
            ),
          ],
        ),
      ),
    ),
  );
}

/// صف إجراء هادئ: حبّة أيقونة + عنوان + سهم — بديل `ListTile` الخام.
class _SheetActionRow extends StatelessWidget {
  const _SheetActionRow({
    required this.icon,
    required this.label,
    required this.onTap,
    this.color,
  });

  final IconData icon;
  final String label;
  final Future<void> Function() onTap;
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

class _SheetAction {
  const _SheetAction({
    required this.icon,
    required this.label,
    required this.onTap,
    this.color,
  });

  final IconData icon;
  final String label;
  final Future<void> Function() onTap;
  final Color? color;
}
