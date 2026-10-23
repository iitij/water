import 'package:flutter/material.dart';

import '../../features/invoices/domain/accounting/finance_enums.dart';
import '../theme/app_colors.dart';

class LtrNumberText extends StatelessWidget {
  const LtrNumberText(this.text, {super.key, this.style, this.textAlign});

  final String text;
  final TextStyle? style;
  final TextAlign? textAlign;

  @override
  Widget build(BuildContext context) {
    return Directionality(
      textDirection: TextDirection.ltr,
      child: Text(text, style: style, textAlign: textAlign),
    );
  }
}

class InvoiceTypeBadge extends StatelessWidget {
  const InvoiceTypeBadge({super.key, required this.type, this.compact = false});

  final String type;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final (label, color) = switch (type) {
      'meter' => ('عداد', AppColors.primary),
      'subscription' => ('اشتراك', AppColors.info),
      'tanker' => ('وايت', AppColors.warning),
      _ => (type, AppColors.neutral),
    };

    return Container(
      padding: EdgeInsets.symmetric(
        horizontal: compact ? 6 : 8,
        vertical: compact ? 2 : 4,
      ),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(4),
        border: Border.all(color: color.withValues(alpha: 0.3)),
      ),
      child: Text(
        label,
        style: TextStyle(
          fontSize: compact ? 10 : 11,
          fontWeight: FontWeight.bold,
          color: color,
        ),
      ),
    );
  }
}

class PathChip extends StatelessWidget {
  const PathChip({
    super.key,
    this.squareName,
    this.pathNumber,
    this.highlighted = false,
  });

  final String? squareName;
  final Object? pathNumber;
  final bool highlighted;

  @override
  Widget build(BuildContext context) {
    final pathStr = pathNumber?.toString();
    final text = [
      if (squareName != null && squareName!.trim().isNotEmpty) 'مربع: $squareName',
      if (pathStr != null && pathStr.trim().isNotEmpty) 'مسار: $pathStr',
    ].join(' / ');

    if (text.isEmpty) return const SizedBox.shrink();

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: highlighted ? AppColors.primary.withValues(alpha: 0.15) : AppColors.cardBackground,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(
          color: highlighted ? AppColors.primary : AppColors.border,
        ),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(Icons.alt_route_rounded, size: 12, color: highlighted ? AppColors.primary : AppColors.textSecondary),
          const SizedBox(width: 4),
          Text(
            text,
            style: TextStyle(
              fontSize: 11,
              fontWeight: highlighted ? FontWeight.bold : FontWeight.normal,
              color: highlighted ? AppColors.primary : AppColors.textSecondary,
            ),
          ),
        ],
      ),
    );
  }
}

class PaymentMethodBadge extends StatelessWidget {
  const PaymentMethodBadge({super.key, required this.method, this.compact = false});

  final Object? method;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final methodStr = method is PaymentMethod
        ? (method as PaymentMethod).wire
        : (method?.toString() ?? '');
    final (String label, IconData icon) = switch (methodStr) {
      'cash' => ('نقداً', Icons.payments_outlined),
      'bank_transfer' => ('تحويل بنكي', Icons.account_balance_outlined),
      'check' => ('شيك', Icons.receipt_long_outlined),
      'network' => ('شبكة', Icons.credit_card_outlined),
      _ => (methodStr, Icons.payment_outlined),
    };

    return Container(
      padding: EdgeInsets.symmetric(
        horizontal: compact ? 6 : 8,
        vertical: compact ? 2 : 4,
      ),
      decoration: BoxDecoration(
        color: AppColors.neutral.withValues(alpha: 0.1),
        borderRadius: BorderRadius.circular(6),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: compact ? 11 : 13, color: AppColors.textSecondary),
          const SizedBox(width: 4),
          Text(
            label,
            style: TextStyle(
              fontSize: compact ? 10 : 11,
              color: AppColors.textSecondary,
            ),
          ),
        ],
      ),
    );
  }
}
