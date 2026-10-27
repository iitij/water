import 'dart:async';

import 'package:flutter/material.dart';

import '../../../../core/finance/finance_feedback.dart';
import '../../../../core/finance/finance_locator.dart';
import '../../../../core/settings/finance_config.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_controls.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../../core/utils/arabic_number_spellout.dart';
import '../../../../core/utils/currency_formatter.dart';
import '../../../../core/utils/json_utils.dart';
import '../../../../core/widgets/finance_badges.dart';
import '../../../invoices/data/models/invoice_model.dart';
import '../../data/models/payment_model.dart';
import '../widgets/payment_actions.dart';

/// نتيجة التحصيل الناجحة — تعرض رقم السند والتفقيط والمتبقي، وتوفر الطباعة
/// الحرارية والواتساب والـ PDF بضغطة واحدة (قاعدة «مسار التالي» §3.4-2).
class ReceiptSuccessSheet extends StatefulWidget {
  const ReceiptSuccessSheet({
    super.key,
    required this.payment,
    this.receiptData,
    this.invoice,
    this.queuedOffline = false,
    this.onDone,
  });

  final PaymentModel payment;
  final ReceiptDataModel? receiptData;
  final InvoiceModel? invoice;

  /// `true` عندما أُدرجت العملية في طابور المزامنة (لا يوجد رقم سند من الخادم بعد).
  final bool queuedOffline;
  final VoidCallback? onDone;

  static Future<void> show(
    BuildContext context, {
    required PaymentModel payment,
    ReceiptDataModel? receiptData,
    InvoiceModel? invoice,
    bool queuedOffline = false,
    VoidCallback? onDone,
  }) {
    return showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => ReceiptSuccessSheet(
        payment: payment,
        receiptData: receiptData,
        invoice: invoice,
        queuedOffline: queuedOffline,
        onDone: onDone,
      ),
    );
  }

  @override
  State<ReceiptSuccessSheet> createState() => _ReceiptSuccessSheetState();
}

class _ReceiptSuccessSheetState extends State<ReceiptSuccessSheet> {
  bool _autoSent = false;

  @override
  void initState() {
    super.initState();
    _maybeAutoSend();
  }

  /// `whatsapp_auto_send_payment` + `payment.created`: يرسل تلقائياً بعد الحفظ.
  void _maybeAutoSend() {
    final config = sl<FinanceConfigRepository>().cached;
    if (!config.autoSendWhatsappPayment ||
        !config.whatsappEnabled ||
        _autoSent) {
      return;
    }
    if (widget.payment.subscriberPhone == null) return;
    _autoSent = true;
    unawaited(
      Future<void>.microtask(() async {
        if (!mounted) return;
        await sendPaymentWhatsapp(
          context,
          widget.payment,
          receiptData: widget.receiptData,
          invoice: widget.invoice,
        );
      }),
    );
  }

  @override
  Widget build(BuildContext context) {
    final payment = widget.payment;
    final receipt = widget.receiptData;

    return SafeArea(
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(18, 6, 18, 18),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Center(
              child: AppGlyph(
                icon: widget.queuedOffline
                    ? Icons.cloud_upload_outlined
                    : Icons.check_circle_rounded,
                tone: widget.queuedOffline
                    ? AppColors.warning
                    : AppColors.success,
                size: 62,
              ),
            ),
            const SizedBox(height: 10),
            Text(
              widget.queuedOffline
                  ? 'الدفعة في طابور المزامنة'
                  : 'تم التحصيل بنجاح',
              textAlign: TextAlign.center,
              style: Theme.of(
                context,
              ).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w600),
            ),
            const SizedBox(height: 2),
            Text(
              payment.receiptNumber.isEmpty
                  ? 'سند محلي — يُرقَّم عند المزامنة'
                  : 'سند قبض ${payment.receiptNumber}',
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodySmall,
            ),
            const SizedBox(height: 14),
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: AppColors.success.withValues(alpha: 0.07),
                borderRadius: BorderRadius.circular(AppRadius.md),
                border: Border.all(
                  color: AppColors.success.withValues(alpha: 0.2),
                ),
              ),
              child: Column(
                children: <Widget>[
                  FittedBox(
                    fit: BoxFit.scaleDown,
                    child: LtrNumberText(
                      CurrencyFormatter.withLabel(payment.amount),
                      style: const TextStyle(
                        fontSize: 22,
                        fontWeight: FontWeight.w600,
                        height: 1.15,
                        letterSpacing: -.4,
                        fontFeatures: <FontFeature>[
                          FontFeature.tabularFigures(),
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    ArabicNumberSpellout.spelloutYemeniRial(payment.amount),
                    textAlign: TextAlign.center,
                    style: const TextStyle(fontSize: 12, height: 1.5),
                  ),
                  const Divider(height: 20),
                  _Row(label: 'استلمنا من', value: payment.displayName),
                  _Row(
                    label: 'التاريخ',
                    value: DateFormat.day(payment.paymentDate),
                  ),
                  _Row(label: 'طريقة الدفع', value: payment.method.label),
                  if (payment.invoiceBillNumber != null)
                    _Row(
                      label: 'بموجب فاتورة',
                      value: payment.invoiceBillNumber!,
                    ),
                  if (receipt != null) ...<Widget>[
                    _Row(
                      label: 'متبقي الفاتورة',
                      value: CurrencyFormatter.debt(receipt.invoiceRemaining),
                      tone: receipt.invoiceRemaining > 0
                          ? AppColors.danger
                          : AppColors.success,
                    ),
                    _Row(
                      label: 'إجمالي مستحق الحساب',
                      value: CurrencyFormatter.debt(receipt.outstandingBalance),
                    ),
                    if (receipt.hasSurplus)
                      _Row(
                        label: 'رصيد دائن زائد',
                        value: CurrencyFormatter.withLabel(
                          receipt.creditBalance,
                        ),
                        tone: AppColors.success,
                      ),
                  ],
                  if (receipt != null && receipt.isFullySettled)
                    const Padding(
                      padding: EdgeInsets.only(top: 8),
                      child: Text(
                        'تم سداد الفاتورة بالكامل ✅',
                        style: TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.w600,
                          color: AppColors.success,
                        ),
                      ),
                    ),
                ],
              ),
            ),
            const SizedBox(height: 16),
            LayoutBuilder(
              builder: (context, constraints) {
                final actions = <Widget>[
                  AppSecondaryButton(
                    label: 'طباعة',
                    icon: Icons.print_outlined,
                    height: 44,
                    onPressed: () => printPaymentThermal(
                      context,
                      payment,
                      receiptData: receipt,
                      invoice: widget.invoice,
                    ),
                  ),
                  AppSecondaryButton(
                    label: 'PDF',
                    icon: Icons.picture_as_pdf_outlined,
                    height: 44,
                    onPressed: () => openPaymentPdfPreview(context, payment),
                  ),
                  if (sl<FinanceConfigRepository>().cached.whatsappEnabled)
                    AppSecondaryButton(
                      label: 'واتساب',
                      icon: Icons.chat_outlined,
                      height: 44,
                      onPressed: () => sendPaymentWhatsapp(
                        context,
                        payment,
                        receiptData: receipt,
                        invoice: widget.invoice,
                      ),
                    ),
                ];
                if (constraints.maxWidth < 400 ||
                    MediaQuery.textScalerOf(context).scale(16) > 23) {
                  return Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: <Widget>[
                      for (var i = 0; i < actions.length; i++) ...<Widget>[
                        if (i > 0) const SizedBox(height: 8),
                        actions[i],
                      ],
                    ],
                  );
                }
                return Row(
                  children: <Widget>[
                    for (var i = 0; i < actions.length; i++) ...<Widget>[
                      if (i > 0) const SizedBox(width: 8),
                      Expanded(child: actions[i]),
                    ],
                  ],
                );
              },
            ),
            const SizedBox(height: 10),
            AppPrimaryButton(
              label: 'تم',
              onPressed: () {
                Navigator.of(context).pop();
                widget.onDone?.call();
              },
            ),
          ],
        ),
      ),
    );
  }
}

class _Row extends StatelessWidget {
  const _Row({required this.label, required this.value, this.tone});

  final String label;
  final String value;
  final Color? tone;

  @override
  Widget build(BuildContext context) {
    if (value.isEmpty) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: LayoutBuilder(
        builder: (context, constraints) {
          final labelText = Text(label, style: const TextStyle(fontSize: 12));
          final valueText = Text(
            value,
            style: TextStyle(
              fontSize: 12,
              fontWeight: FontWeight.w600,
              color: tone,
            ),
          );
          if (constraints.maxWidth < 360 ||
              MediaQuery.textScalerOf(context).scale(16) > 23) {
            return Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                labelText,
                const SizedBox(height: 2),
                valueText,
                const SizedBox(height: 6),
              ],
            );
          }
          return Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              Expanded(flex: 2, child: labelText),
              const SizedBox(width: 12),
              Expanded(flex: 3, child: valueText),
            ],
          );
        },
      ),
    );
  }
}

/// تأكيد الحذف موحد بين الوحدتين (يُصدَّر هنا ليستخدمه `payments_list_screen`).
Future<bool> confirmDeletePayment(BuildContext context, PaymentModel payment) {
  return ConfirmDialog.show(
    context,
    title: 'حذف سند قبض',
    message: 'سيُحذف السند حذفًا ناعمًا ويُعاد احتساب الفواتير المرتبطة.',
    details: <String>[
      'السند: ${payment.receiptNumber}',
      'المبلغ: ${CurrencyFormatter.withLabel(payment.amount)}',
    ],
    confirmLabel: 'حذف',
    destructive: true,
  );
}
