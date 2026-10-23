import 'package:flutter/material.dart';

import 'app_colors.dart';
import 'currency_formatter.dart';

/// شارة حالة ملونة موحدة — نسخة محسّنة تطابق نظام البطاقات الحديث في التطبيق.
class StatusBadge extends StatelessWidget {
  const StatusBadge({super.key, required this.label, required this.color});

  final String label;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: color.withValues(alpha: 0.25)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Container(
            width: 6,
            height: 6,
            decoration: BoxDecoration(color: color, shape: BoxShape.circle),
          ),
          const SizedBox(width: 6),
          Flexible(
            child: Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(fontSize: 11, fontWeight: FontWeight.w800, color: color),
            ),
          ),
        ],
      ),
    );
  }
}

/// مبلغ ملون دلالياً: موجب أخضر (دائن) / سالب أحمر (مدين) — بأرقام جدولية ثابتة.
class BalanceText extends StatelessWidget {
  const BalanceText(this.value, {super.key, this.fontSize = 15, this.fontWeight = FontWeight.bold});
  final num value;
  final double fontSize;
  final FontWeight fontWeight;

  @override
  Widget build(BuildContext context) => Directionality(
        textDirection: TextDirection.ltr,
        child: Text(
          CurrencyFormatter.signed(value),
          style: TextStyle(
            fontSize: fontSize,
            fontWeight: fontWeight,
            color: value > 0
                ? AppColors.success
                : value < 0
                    ? AppColors.danger
                    : AppColors.neutral,
          ),
        ),
      );
}

/// شريط إجمالي المبالغ (Sum Summarizer) لشاشات القوالب — بطاقة فاتحة ذات ظل ناعم
/// بدلاً من البطاقة الداكنة التي كانت تخرج عن السياق في الوضع الفاتح.
class SumBar extends StatelessWidget {
  const SumBar({super.key, required this.items});

  final List<({String label, num value, Color color})> items;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 15),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: AlignmentDirectional.centerStart,
          end: AlignmentDirectional.centerEnd,
          colors: <Color>[
            AppColors.primary.withValues(alpha: 0.07),
            AppColors.primary.withValues(alpha: 0.015),
          ],
        ),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: AppColors.primary.withValues(alpha: 0.14)),
        boxShadow: <BoxShadow>[
          BoxShadow(
            color: AppColors.primaryDark.withValues(alpha: 0.05),
            blurRadius: 18,
            offset: const Offset(0, 6),
          ),
        ],
      ),
      child: Wrap(
        spacing: 24,
        runSpacing: 12,
        children: <Widget>[
          for (final it in items)
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(it.label, style: TextStyle(color: AppColors.neutral, fontSize: 12)),
                const SizedBox(height: 3),
                Directionality(
                  textDirection: TextDirection.ltr,
                  child: Text(
                    CurrencyFormatter.format(it.value),
                    style: TextStyle(
                      color: it.color,
                      fontSize: 17,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ),
              ],
            ),
        ],
      ),
    );
  }
}

/// شاشة فارغة حديثة: رمز داخل دائرة مخففة + نص — تطابق `AdminEmptyView`.
class EmptyState extends StatelessWidget {
  const EmptyState({
    super.key,
    required this.message,
    this.title,
    this.icon = Icons.inbox_outlined,
    this.color,
  });

  final String message;
  final String? title;
  final IconData icon;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final effectiveColor = color ?? AppColors.neutral;
    return Center(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 32),
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 420),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Container(
                width: 70,
                height: 70,
                decoration: BoxDecoration(
                  color: effectiveColor.withValues(alpha: 0.1),
                  shape: BoxShape.circle,
                ),
                child: Icon(icon, size: 32, color: effectiveColor),
              ),
              const SizedBox(height: 16),
              if (title != null)
                Text(title!, textAlign: TextAlign.center, style: Theme.of(context).textTheme.titleMedium),
              const SizedBox(height: 6),
              Text(message, textAlign: TextAlign.center, style: Theme.of(context).textTheme.bodyMedium?.copyWith(color: AppColors.neutral)),
            ],
          ),
        ),
      ),
    );
  }
}

Future<bool> confirmAction(BuildContext context, String title, String body) async {
  final result = await showDialog<bool>(
    context: context,
    builder: (ctx) => AlertDialog(
      icon: const Icon(Icons.help_outline_rounded, size: 30, color: AppColors.primary),
      title: Text(title, style: const TextStyle(fontWeight: FontWeight.w800)),
      content: Text(body),
      actions: <Widget>[
        TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('إلغاء')),
        FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('تأكيد')),
      ],
    ),
  );
  return result ?? false;
}

/// تخطيط Master-Detail: قائمة يمين (40%) + تفاصيل يسار (60%) عند >= 840dp،
/// وفي الهاتف تُعرض القائمة فقط ويُفتح التفصيل كصفحة (عبر onOpenDetail).
class MasterDetailLayout extends StatelessWidget {
  const MasterDetailLayout({
    super.key,
    required this.master,
    required this.detail,
    required this.hasSelection,
  });

  final Widget master;
  final Widget detail;
  final bool hasSelection;

  @override
  Widget build(BuildContext context) {
    final width = MediaQuery.sizeOf(context).width;
    if (width < 840) return master;
    return Row(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        SizedBox(
          width: width * 0.4,
          child: master,
        ),
        const VerticalDivider(width: 1),
        Expanded(
          child: hasSelection
              ? detail
              : EmptyState(message: 'اختر عنصراً من القائمة لعرض تفاصيله', icon: Icons.touch_app_outlined),
        ),
      ],
    );
  }
}
