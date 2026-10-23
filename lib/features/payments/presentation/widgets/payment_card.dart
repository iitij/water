import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/utils/currency_formatter.dart';
import '../../../../core/utils/json_utils.dart';
import '../../data/models/payment_model.dart';
import '../widgets/payment_actions.dart';

/// `PaymentCard` — تسليم Dev 3 → Dev 5 (`SCR-17`) وDev 4 (سندات التحصيل في `SCR-19/20`).
///
/// تعرض سطرًا مضغوطاً (بطاقة) أو موسّعاً (تفاصيل) مع شارة «حساب العداد/حساب
/// المشترك» التي تُظهر قاعدة عزل الحسابات §3.4-1، وتفتح قائمة الإجراءات عند
/// الضغط المطوّل.
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
    final amount = CurrencyFormatter.withLabel(payment.amount);

    return Card(
      margin: EdgeInsets.symmetric(vertical: dense ? 4 : 6),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      child: InkWell(
        borderRadius: BorderRadius.circular(14),
        onTap: onTap,
        onLongPress: () => showPaymentActionSheet(context, payment: payment),
        child: Padding(
          padding: EdgeInsets.all(dense ? 10 : 12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              Row(
                children: <Widget>[
                  Expanded(
                    child: Text(
                      payment.receiptNumber.isEmpty ? 'سند غير مرقّم' : payment.receiptNumber,
                      style: const TextStyle(
                        fontSize: 14,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ),
                  Text(
                    amount,
                    style: const TextStyle(
                      fontSize: 14,
                      fontWeight: FontWeight.w800,
                      color: AppColors.success,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 4),
              Row(
                children: <Widget>[
                  Expanded(
                    child: Text(
                      payment.displayName,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontSize: 12),
                    ),
                  ),
                  PaymentMethodBadge(method: payment.method, compact: true),
                ],
              ),
              if (!dense) const SizedBox(height: 6),
              Wrap(
                spacing: 6,
                runSpacing: 6,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: <Widget>[
                  _MetaChip(
                    icon: Icons.event_outlined,
                    label: DateFormat.day(payment.paymentDate),
                  ),
                  if (payment.invoiceBillNumber != null)
                    _MetaChip(
                      icon: Icons.receipt_long_outlined,
                      label: payment.invoiceBillNumber!,
                    ),
                  if (showAccountScope)
                    _MetaChip(
                      icon: payment.affectsMeterAccount
                          ? Icons.speed_outlined
                          : Icons.person_outline,
                      label: payment.affectsMeterAccount
                          ? 'حساب العداد'
                          : 'حساب المشترك',
                      tone: payment.affectsMeterAccount
                          ? AppColors.info
                          : AppColors.neutral,
                    ),
                  if (payment.referenceNumber != null &&
                      payment.referenceNumber!.isNotEmpty)
                    _MetaChip(
                      icon: Icons.tag_outlined,
                      label: payment.referenceNumber!,
                    ),
                  if (payment.collectorName != null &&
                      payment.collectorName!.isNotEmpty)
                    _MetaChip(
                      icon: Icons.badge_outlined,
                      label: payment.collectorName!,
                    ),
                  if (payment.isDeleted)
                    const _MetaChip(
                      icon: Icons.delete_outline,
                      label: 'محذوف',
                      tone: AppColors.danger,
                    ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _MetaChip extends StatelessWidget {
  const _MetaChip({
    required this.icon,
    required this.label,
    this.tone,
  });

  final IconData icon;
  final String label;
  final Color? tone;

  @override
  Widget build(BuildContext context) {
    final color = tone ?? AppColors.neutral;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Icon(icon, size: 13, color: color),
          const SizedBox(width: 4),
          Text(
            label,
            style: TextStyle(fontSize: 11, color: color, fontWeight: FontWeight.w600),
          ),
        ],
      ),
    );
  }
}
