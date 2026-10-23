import 'package:flutter/material.dart';
import 'app_colors.dart';
import 'currency_formatter.dart';

/// شارة حالة ملونة موحدة.
class StatusBadge extends StatelessWidget {
  const StatusBadge({super.key, required this.label, required this.color});
  final String label;
  final Color color;
  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
        decoration: BoxDecoration(
          color: color.withValues(alpha: 0.12),
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: color.withValues(alpha: 0.4)),
        ),
        child: Text(label,
            style: TextStyle(color: color, fontSize: 12, fontWeight: FontWeight.w600)),
      );
}

/// مبلغ ملون دلالياً: موجب أخضر (دائن) / سالب أحمر (مدين).
class BalanceText extends StatelessWidget {
  const BalanceText(this.value, {super.key, this.fontSize = 15});
  final num value;
  final double fontSize;
  @override
  Widget build(BuildContext context) => Directionality(
        textDirection: TextDirection.ltr,
        child: Text(
          CurrencyFormatter.signed(value),
          style: TextStyle(
            fontSize: fontSize,
            fontWeight: FontWeight.bold,
            color: value > 0
                ? AppColors.success
                : value < 0
                    ? AppColors.danger
                    : AppColors.neutral,
          ),
        ),
      );
}

/// شريط إجمالي المبالغ (Sum Summarizer) لشاشتي SCR-19 / SCR-20.
class SumBar extends StatelessWidget {
  const SumBar({super.key, required this.items});
  final List<({String label, num value, Color color})> items;
  @override
  Widget build(BuildContext context) => Card(
        color: AppColors.primaryDark,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
          child: Wrap(
            spacing: 24,
            runSpacing: 8,
            children: [
              for (final it in items)
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(it.label,
                        style: const TextStyle(color: Colors.white70, fontSize: 12)),
                    Directionality(
                      textDirection: TextDirection.ltr,
                      child: Text(CurrencyFormatter.format(it.value),
                          style: TextStyle(
                              color: it.color,
                              fontSize: 17,
                              fontWeight: FontWeight.bold)),
                    ),
                  ],
                ),
            ],
          ),
        ),
      );
}

class EmptyState extends StatelessWidget {
  const EmptyState({super.key, required this.message, this.icon = Icons.inbox_outlined});
  final String message;
  final IconData icon;
  @override
  Widget build(BuildContext context) => Center(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Icon(icon, size: 56, color: AppColors.neutral),
          const SizedBox(height: 12),
          Text(message, style: const TextStyle(color: AppColors.neutral)),
        ]),
      );
}

Future<bool> confirmAction(BuildContext context, String title, String body) async {
  final result = await showDialog<bool>(
    context: context,
    builder: (ctx) => AlertDialog(
      title: Text(title),
      content: Text(body),
      actions: [
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
    return Row(children: [
      SizedBox(width: width * 0.4, child: master),
      const VerticalDivider(width: 1),
      Expanded(
        child: hasSelection
            ? detail
            : const EmptyState(message: 'اختر عنصراً من القائمة لعرض تفاصيله', icon: Icons.touch_app_outlined),
      ),
    ]);
  }
}
