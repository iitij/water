import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../../core/utils/currency_formatter.dart';
import '../../../../core/utils/json_utils.dart';
import '../../../../core/widgets/finance_badges.dart';
import '../../../invoices/domain/accounting/finance_enums.dart';
import '../../data/models/payment_model.dart';
import '../widgets/payment_actions.dart';

/// `PaymentCard` — تسليم Dev 3 → Dev 5 (`SCR-17`) وDev 4 (سندات التحصيل في `SCR-19/20`).
///
/// تصميم v3 — «بطاقة هادئة»:
///  - المستفيد والمبلغ هما البطلان؛ رقم السند وطريقة الدفع سطر ثانوي.
///  - سطر معلومات واحد بلا إطارات (تاريخ · فاتورة · الحساب · مرجع · المحصّل)
///    بدل حبّات مُحيّطة مكدّسة، وفتح قائمة الإجراءات بالضغط المطوّل.
class PaymentCard extends StatelessWidget {
  const PaymentCard({
    super.key,
    required this.payment,
    this.onTap,
    this.dense = false,
    this.showAccountScope = true,
  });

  final PaymentModel payment;
  final VoidCallback? onTap;
  final bool dense;
  final bool showAccountScope;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final amount = CurrencyFormatter.withLabel(payment.amount);

    return Padding(
      padding: EdgeInsets.symmetric(vertical: dense ? 3 : 4),
      child: AppCard(
        padding: EdgeInsets.zero,
        onTap: onTap,
        child: InkWell(
          borderRadius: BorderRadius.circular(AppRadius.xl),
          onLongPress: () => showPaymentActionSheet(context, payment: payment),
          child: Padding(
            padding: EdgeInsets.all(dense ? 11 : 13),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    Container(
                      width: 36,
                      height: 36,
                      decoration: BoxDecoration(
                        color: AppTint.soft(AppColors.success, context),
                        shape: BoxShape.circle,
                      ),
                      child: Icon(
                        Icons.payments_rounded,
                        size: 18,
                        color: AppSemantic.resolve(context, AppColors.success),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: <Widget>[
                          Text(
                            payment.displayName,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                              fontSize: 14.5,
                              fontWeight: FontWeight.w600,
                              height: 1.3,
                            ),
                          ),
                          const SizedBox(height: 2),
                          Row(
                            children: <Widget>[
                              Flexible(
                                child: LtrNumberText(
                                  payment.receiptNumber.isEmpty
                                      ? 'سند غير مرقّم'
                                      : payment.receiptNumber,
                                  style: TextStyle(
                                    fontSize: 11.5,
                                    fontWeight: FontWeight.w500,
                                    letterSpacing: .2,
                                    color: scheme.onSurfaceVariant,
                                  ),
                                ),
                              ),
                              const SizedBox(width: 6),
                              Icon(
                                _methodIcon(payment.method),
                                size: 12,
                                color: scheme.onSurfaceVariant,
                              ),
                              const SizedBox(width: 3),
                              Text(
                                _methodLabel(payment.method),
                                style: TextStyle(
                                  fontSize: 11,
                                  color: scheme.onSurfaceVariant,
                                ),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: 8),
                    LtrNumberText(
                      amount,
                      style: TextStyle(
                        fontSize: 16,
                        fontWeight: FontWeight.w600,
                        letterSpacing: -.3,
                        color: AppSemantic.resolve(context, AppColors.success),
                        fontFeatures: const <FontFeature>[FontFeature.tabularFigures()],
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 10),
                Wrap(
                  spacing: 12,
                  runSpacing: 4,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: <Widget>[
                    _QuietMeta(
                      icon: Icons.event_outlined,
                      text: DateFormat.day(payment.paymentDate),
                      ltr: true,
                    ),
                    if (payment.invoiceBillNumber != null)
                      _QuietMeta(
                        icon: Icons.receipt_long_outlined,
                        text: payment.invoiceBillNumber!,
                      ),
                    if (showAccountScope)
                      _QuietMeta(
                        icon: payment.affectsMeterAccount
                            ? Icons.speed_outlined
                            : Icons.person_outline,
                        text: payment.affectsMeterAccount ? 'حساب العداد' : 'حساب المشترك',
                        tone: payment.affectsMeterAccount ? AppColors.info : null,
                      ),
                    if (payment.referenceNumber != null &&
                        payment.referenceNumber!.isNotEmpty)
                      _QuietMeta(
                        icon: Icons.tag_outlined,
                        text: payment.referenceNumber!,
                        ltr: true,
                      ),
                    if (payment.collectorName != null && payment.collectorName!.isNotEmpty)
                      _QuietMeta(
                        icon: Icons.badge_outlined,
                        text: payment.collectorName!,
                      ),
                    if (payment.whatsappSent)
                      const _QuietMeta(
                        icon: Icons.chat_bubble_outline,
                        text: 'أُرسل واتساب',
                        tone: AppColors.success,
                      ),
                    if (payment.isDeleted)
                      const _QuietMeta(
                        icon: Icons.delete_outline,
                        text: 'محذوف',
                        tone: AppColors.danger,
                      ),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  static String _methodLabel(Object? method) {
    final methodStr =
        method is PaymentMethod ? method.wire : (method?.toString() ?? '');
    return switch (methodStr) {
      'cash' => 'نقداً',
      'bank_transfer' => 'تحويل بنكي',
      'check' => 'شيك',
      'network' => 'شبكة',
      _ => methodStr,
    };
  }

  static IconData _methodIcon(Object? method) {
    final methodStr =
        method is PaymentMethod ? method.wire : (method?.toString() ?? '');
    return switch (methodStr) {
      'cash' => Icons.payments_outlined,
      'bank_transfer' => Icons.account_balance_outlined,
      'check' => Icons.receipt_long_outlined,
      'network' => Icons.credit_card_outlined,
      _ => Icons.payment_outlined,
    };
  }
}

/// عنصر معلومات صامت — أيقونة + نص بلا إطار.
class _QuietMeta extends StatelessWidget {
  const _QuietMeta({required this.icon, required this.text, this.tone, this.ltr = false});

  final IconData icon;
  final String text;
  final Color? tone;
  final bool ltr;

  @override
  Widget build(BuildContext context) {
    final color = tone == null
        ? Theme.of(context).colorScheme.onSurfaceVariant
        : AppSemantic.resolve(context, tone!);
    final style = TextStyle(
      fontSize: 11,
      fontWeight: FontWeight.w500,
      color: color,
      height: 1.3,
    );
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Icon(icon, size: 13, color: color),
        const SizedBox(width: 4),
        if (ltr)
          Directionality(textDirection: TextDirection.ltr, child: Text(text, style: style))
        else
          Text(text, style: style),
      ],
    );
  }
}
