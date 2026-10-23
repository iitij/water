import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_controls.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tiles.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../../core/ui/app_viz.dart';
import '../../../admin_shared/presentation/widgets/admin_widgets.dart';
import '../../domain/entities/dashboard_entities.dart';

/// Visual summary of how much of the billed money has actually been collected.
class CollectionProgressCard extends StatelessWidget {
  const CollectionProgressCard({
    required this.stats,
    this.onOpenReports,
    super.key,
  });

  final DashboardStats stats;
  final VoidCallback? onOpenReports;

  double get _rate {
    final denominator = stats.monthlyCollection + stats.outstandingAmount;
    if (denominator <= 0) return 0;
    return (stats.monthlyCollection / denominator).clamp(0, 1).toDouble();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final percentage = (_rate * 100).round();
    final rateColor = percentage >= 70
        ? AppColors.success
        : percentage >= 40
            ? AppColors.warning
            : AppColors.danger;

    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          const SectionHeading(
            title: 'صحة التحصيل',
            subtitle: 'نسبة المقبوضات مقابل الديون المستحقة',
            icon: Icons.donut_large_rounded,
          ),
          const Gap(AppSpacing.lg),
          Row(
            crossAxisAlignment: CrossAxisAlignment.center,
            children: <Widget>[
              AppProgressRing(
                value: _rate,
                size: 108,
                strokeWidth: 10,
                color: rateColor,
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    Directionality(
                      textDirection: TextDirection.ltr,
                      child: Text(
                        '$percentage%',
                        style: theme.textTheme.titleLarge?.copyWith(
                          fontWeight: FontWeight.w800,
                          height: 1.1,
                        ),
                      ),
                    ),
                    Text('تحصيل', style: theme.textTheme.labelSmall),
                  ],
                ),
              ),
              const SizedBox(width: 18),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: <Widget>[
                    _MetricLine(
                      label: 'محصّل هذا الشهر',
                      value: AdminFormat.compactMoney(stats.monthlyCollection),
                      color: AppColors.success,
                    ),
                    const SizedBox(height: 10),
                    _MetricLine(
                      label: 'ديون مستحقة',
                      value: AdminFormat.compactMoney(stats.outstandingAmount),
                      color: AppColors.danger,
                    ),
                    const SizedBox(height: 10),
                    _MetricLine(
                      label: 'فواتير متأخرة',
                      value: AdminFormat.number(stats.overdueInvoicesCount),
                      color: AppColors.warning,
                    ),
                  ],
                ),
              ),
            ],
          ),
          if (onOpenReports != null) ...<Widget>[
            const Gap(AppSpacing.md),
            Align(
              alignment: AlignmentDirectional.centerStart,
              child: TextButton.icon(
                onPressed: onOpenReports,
                icon: const Icon(Icons.analytics_outlined, size: 17),
                label: const Text('تحليل مفصل في التقارير'),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _MetricLine extends StatelessWidget {
  const _MetricLine({
    required this.label,
    required this.value,
    required this.color,
  });

  final String label;
  final String value;
  final Color color;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Row(
      children: <Widget>[
        Container(
          width: 9,
          height: 9,
          decoration: BoxDecoration(color: color, shape: BoxShape.circle),
        ),
        const SizedBox(width: 9),
        Expanded(
          child: Text(
            label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.bodySmall?.copyWith(fontWeight: FontWeight.w600),
          ),
        ),
        const SizedBox(width: 8),
        Directionality(
          textDirection: TextDirection.ltr,
          child: Text(
            value,
            maxLines: 1,
            style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w800),
          ),
        ),
      ],
    );
  }
}

/// Small helper card that turns a headline number into a call to action.
class InsightCard extends StatelessWidget {
  const InsightCard({
    super.key,
    required this.title,
    required this.message,
    required this.icon,
    required this.color,
    this.actionLabel,
    this.onAction,
  });

  final String title;
  final String message;
  final IconData icon;
  final Color color;
  final String? actionLabel;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final dark = theme.brightness == Brightness.dark;
    return Container(
      padding: const EdgeInsets.all(AppSpacing.lg),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: AlignmentDirectional.topStart,
          end: AlignmentDirectional.bottomEnd,
          colors: <Color>[
            color.withValues(alpha: dark ? .22 : .1),
            color.withValues(alpha: dark ? .08 : .03),
          ],
        ),
        borderRadius: BorderRadius.circular(AppRadius.xl),
        border: Border.all(color: color.withValues(alpha: dark ? .3 : .16)),
      ),
      child: Row(
        children: <Widget>[
          AppIconBubble(icon: icon, color: color, size: 44, filled: true),
          const SizedBox(width: 13),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Text(title, style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
                const SizedBox(height: 3),
                Text(message, style: theme.textTheme.bodySmall?.copyWith(height: 1.5)),
              ],
            ),
          ),
          if (onAction != null) ...<Widget>[
            const SizedBox(width: 8),
            AppPill(
              label: actionLabel ?? 'عرض',
              icon: Icons.arrow_back_rounded,
              color: color,
              onTap: onAction,
            ),
          ],
        ],
      ),
    );
  }
}
