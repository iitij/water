import 'package:flutter/material.dart';

import '../theme/app_colors.dart';
import '../utils/compound_search_parser.dart';

/// شارة حالة ملونة موحّدة (`StatusBadge`) — مطابقة لسلوك Filament `->badge()->colors([...])`.
class StatusBadge extends StatelessWidget {
  const StatusBadge({
    super.key,
    required this.label,
    required this.color,
    this.icon,
    this.compact = false,
  });

  /// يبني الشارة من مفتاح الحالة الخام (`paid`, `overdue`, ...).
  factory StatusBadge.fromStatus(String status, {bool compact = false}) {
    final (label, icon) = switch (status) {
      'paid' => ('مسددة', Icons.check_circle_outline),
      'partially_paid' => ('مسددة جزئياً', Icons.payments_outlined),
      'overdue' => ('متأخرة', Icons.warning_amber_rounded),
      'issued' => ('صادرة', Icons.description_outlined),
      'draft' => ('مسودة', Icons.edit_note_outlined),
      'cancelled' => ('ملغاة', Icons.block_outlined),
      'uninvoiced' => ('غير مفوترة', Icons.receipt_long_outlined),
      'invoiced' => ('مفوترة', Icons.task_alt),
      'completed' => ('مكتمل', Icons.local_shipping_outlined),
      'pending' => ('معلق', Icons.schedule),
      _ => (status, Icons.help_outline),
    };

    return StatusBadge(
      label: label,
      color: FinanceStatusColors.of(status),
      icon: icon,
      compact: compact,
    );
  }

  final String label;
  final Color color;
  final IconData? icon;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: EdgeInsets.symmetric(
        horizontal: compact ? 6 : 10,
        vertical: compact ? 2 : 5,
      ),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: color.withValues(alpha: 0.35)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          if (icon != null) ...<Widget>[
            Icon(icon, size: compact ? 12 : 15, color: color),
            const SizedBox(width: 4),
          ],
          Text(
            label,
            style: TextStyle(
              color: color,
              fontWeight: FontWeight.w700,
              fontSize: compact ? 11 : 12,
            ),
          ),
        ],
      ),
    );
  }
}

/// شارة نوع الفاتورة (`meter` / `subscription` / `tanker`).
class InvoiceTypeBadge extends StatelessWidget {
  const InvoiceTypeBadge({super.key, required this.type, this.compact = false});

  final String type;
  final bool compact;

  static (String, Color, IconData) describe(String type) => switch (type) {
        'subscription' => ('اشتراك', AppColors.subscription, Icons.assignment_outlined),
        'tanker' => ('وايت', AppColors.tanker, Icons.local_shipping_outlined),
        _ => ('عداد', AppColors.meter, Icons.speed_outlined),
      };

  @override
  Widget build(BuildContext context) {
    final (label, color, icon) = describe(type);
    return StatusBadge(label: label, color: color, icon: icon, compact: compact);
  }
}

/// شارة طريقة الدفع (نقدي/تحويل بنكي/شيك) — مطابقة لـ `PaymentResource`.
class PaymentMethodBadge extends StatelessWidget {
  const PaymentMethodBadge({super.key, required this.method, this.compact = false});

  final String method;
  final bool compact;

  static String label(String method) => switch (method) {
        'bank_transfer' => 'تحويل بنكي',
        'check' => 'شيك',
        _ => 'نقدي',
      };

  static Color color(String method) => switch (method) {
        'bank_transfer' => AppColors.info,
        'check' => AppColors.brandAmber,
        _ => AppColors.success,
      };

  static IconData icon(String method) => switch (method) {
        'bank_transfer' => Icons.account_balance_outlined,
        'check' => Icons.event_note_outlined,
        _ => Icons.payments_outlined,
      };

  @override
  Widget build(BuildContext context) => StatusBadge(
        label: label(method),
        color: color(method),
        icon: icon(method),
        compact: compact,
      );
}

/// وسم مسار مركّب (`4/1`) مع أيقونة — يُستخدم في نتائج المحصل السريع.
class PathChip extends StatelessWidget {
  const PathChip({
    super.key,
    required this.squareName,
    required this.pathNumber,
    this.highlighted = false,
  });

  final String? squareName;
  final int? pathNumber;
  final bool highlighted;

  @override
  Widget build(BuildContext context) {
    final color = highlighted ? AppColors.primary : AppColors.neutral;
    final text = pathNumber == null
        ? (squareName ?? '—')
        : '${squareName ?? ''}${squareName == null ? '' : ' / '}$pathNumber';
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Directionality(
        textDirection: TextDirection.ltr,
        child: Text(
          text,
          style: TextStyle(color: color, fontWeight: FontWeight.w700, fontSize: 11),
        ),
      ),
    );
  }
}

/// نص رقمي داخل LTR إلزامي (الأرقام لا تنقلب في RTL).
class LtrNumberText extends StatelessWidget {
  const LtrNumberText(
    this.text, {
    super.key,
    this.style,
    this.textAlign = TextAlign.start,
  });

  final String text;
  final TextStyle? style;
  final TextAlign textAlign;

  @override
  Widget build(BuildContext context) => Directionality(
        textDirection: TextDirection.ltr,
        child: Text(
          CompoundSearchQuery.normalizeDigits(text),
          textAlign: textAlign,
          style: style,
        ),
      );
}
