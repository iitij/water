import 'package:flutter/material.dart';

import '../../features/invoices/domain/accounting/finance_enums.dart';
import '../theme/app_colors.dart';
import '../ui/app_tokens.dart';

/// رقم يُعرض دائماً من اليسار إلى اليمين داخل واجهة RTL.
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

/// تصنيف الفاتورة: حبّة حيادية بأيقونة ملوّنة فقط — بلا خلفية ملوّنة.
class InvoiceTypeBadge extends StatelessWidget {
  const InvoiceTypeBadge({super.key, required this.type, this.compact = false});

  final String type;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final (label, icon, color) = switch (type) {
      'meter' => ('عداد', Icons.speed_rounded, AppColors.primary),
      _ => (type, Icons.receipt_long_rounded, AppColors.neutral),
    };
    return _Badge(
      label: label,
      icon: icon,
      color: color,
      compact: compact,
    );
  }
}

/// موقع المشترك (مربع/مسار) كحبّة حيادية واحدة.
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

    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: highlighted ? scheme.primary.withValues(alpha: .08) : Colors.transparent,
        borderRadius: BorderRadius.circular(AppRadius.pill),
        border: Border.all(
          color: highlighted
              ? scheme.primary.withValues(alpha: .24)
              : scheme.outlineVariant,
        ),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Icon(
            Icons.alt_route_rounded,
            size: 12,
            color: highlighted ? scheme.primary : scheme.onSurfaceVariant,
          ),
          const SizedBox(width: 4),
          Text(
            text,
            style: theme.textTheme.labelSmall?.copyWith(
              color: highlighted ? scheme.primary : scheme.onSurfaceVariant,
              fontWeight: FontWeight.w500,
            ),
          ),
        ],
      ),
    );
  }
}

/// طريقة الدفع كحبّة حيادية بأيقونة.
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
    return _Badge(label: label, icon: icon, color: AppColors.neutral, compact: compact);
  }
}

class _Badge extends StatelessWidget {
  const _Badge({
    required this.label,
    required this.icon,
    required this.color,
    required this.compact,
  });

  final String label;
  final IconData icon;
  final Color color;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return Container(
      padding: EdgeInsets.symmetric(
        horizontal: compact ? 7 : 9,
        vertical: compact ? 2 : 4,
      ),
      decoration: BoxDecoration(
        color: scheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(AppRadius.pill),
        border: Border.all(color: scheme.outlineVariant),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Icon(icon, size: compact ? 11 : 12, color: AppSemantic.resolve(context, color)),
          const SizedBox(width: 4),
          Text(
            label,
            style: TextStyle(
              fontSize: compact ? 10 : 11,
              fontWeight: FontWeight.w500,
              color: scheme.onSurfaceVariant,
              height: 1.2,
            ),
          ),
        ],
      ),
    );
  }
}
