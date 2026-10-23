import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';

import '../../../admin_shared/presentation/theme/admin_palette.dart';
import '../../../admin_shared/presentation/widgets/admin_widgets.dart';
import '../../domain/entities/dashboard_entities.dart';

class StatsOverviewGrid extends StatelessWidget {
  const StatsOverviewGrid({required this.stats, super.key});

  final DashboardStats stats;

  @override
  Widget build(BuildContext context) {
    final cards = [
      _StatCard(
        title: 'المشتركون النشطون',
        value: AdminFormat.number(stats.activeSubscribersCount),
        description: 'من أصل ${AdminFormat.number(stats.totalSubscribersCount)} مشترك',
        icon: Icons.groups_rounded,
        color: AdminPalette.primary,
        sparkline: stats.subscribersSparkline,
      ),
      _StatCard(
        title: 'إجمالي الديون المستحقة',
        value: AdminFormat.compactMoney(stats.outstandingAmount),
        fullValue: AdminFormat.money(stats.outstandingAmount),
        description: '${AdminFormat.number(stats.unpaidInvoicesCount)} فاتورة غير مسددة',
        icon: Icons.account_balance_wallet_outlined,
        color: AdminPalette.danger,
      ),
      _StatCard(
        title: 'إيرادات هذا الشهر',
        value: AdminFormat.compactMoney(stats.monthlyCollection),
        fullValue: AdminFormat.money(stats.monthlyCollection),
        description: '${AdminFormat.number(stats.readingsThisMonth)} قراءة هذا الشهر',
        icon: Icons.payments_outlined,
        color: AdminPalette.success,
        sparkline: stats.collectionSparkline,
      ),
      _StatCard(
        title: 'الفواتير المتأخرة',
        value: AdminFormat.number(stats.overdueInvoicesCount),
        description: stats.overdueInvoicesCount > 0 ? 'تحتاج متابعة' : 'لا توجد متأخرات 🎉',
        icon: Icons.schedule_rounded,
        color: stats.overdueInvoicesCount > 0
            ? AdminPalette.danger
            : AdminPalette.success,
      ),
    ];
    return LayoutBuilder(
      builder: (context, constraints) {
        final columns = constraints.maxWidth >= 600 ? 4 : 2;
        final cardWidth =
            (constraints.maxWidth - (12 * (columns - 1))) / columns;
        final cardHeight = constraints.maxWidth < 360 ? 215.0 : 190.0;
        final aspectRatio = cardWidth / cardHeight;
        return GridView.count(
          crossAxisCount: columns,
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          crossAxisSpacing: 12,
          mainAxisSpacing: 12,
          childAspectRatio: aspectRatio,
          children: cards,
        );
      },
    );
  }
}

class _StatCard extends StatelessWidget {
  const _StatCard({
    required this.title,
    required this.value,
    required this.description,
    required this.icon,
    required this.color,
    this.fullValue,
    this.sparkline = const [],
  });

  final String title;
  final String value;
  final String? fullValue;
  final String description;
  final IconData icon;
  final Color color;
  final List<double> sparkline;

  @override
  Widget build(BuildContext context) {
    return AdminCard(
      padding: const EdgeInsets.all(14),
      child: LayoutBuilder(
        builder: (context, constraints) {
          final showChart = sparkline.length > 1 && constraints.maxHeight >= 145;
          final valueText = FittedBox(
            fit: BoxFit.scaleDown,
            alignment: AlignmentDirectional.centerStart,
            child: Directionality(
              textDirection: TextDirection.ltr,
              child: Text(
                value,
                maxLines: 1,
                style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                      fontWeight: FontWeight.w900,
                      color: AdminPalette.primaryDark,
                    ),
              ),
            ),
          );
          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                children: [
                  Container(
                    padding: const EdgeInsets.all(8),
                    decoration: BoxDecoration(
                      color: color.withAlpha(24),
                      borderRadius: BorderRadius.circular(12),
                    ),
                    child: Icon(icon, color: color, size: 21),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      title,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context).textTheme.labelLarge?.copyWith(
                            fontWeight: FontWeight.w700,
                          ),
                    ),
                  ),
                ],
              ),
              const Spacer(),
              fullValue == null
                  ? valueText
                  : Tooltip(message: fullValue!, child: valueText),
              const SizedBox(height: 5),
              Text(
                description,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: Theme.of(context).colorScheme.onSurfaceVariant,
                    ),
              ),
              if (showChart) ...[
                const SizedBox(height: 8),
                SizedBox(height: 30, child: Sparkline(values: sparkline, color: color)),
              ],
            ],
          );
        },
      ),
    );
  }
}

class Sparkline extends StatelessWidget {
  const Sparkline({required this.values, required this.color, super.key});

  final List<double> values;
  final Color color;

  @override
  Widget build(BuildContext context) {
    if (values.isEmpty) return const SizedBox.shrink();
    final maximum = values.reduce((a, b) => a > b ? a : b);
    final minimum = values.reduce((a, b) => a < b ? a : b);
    return LineChart(
      LineChartData(
        minX: 0,
        maxX: values.length == 1 ? 1.0 : (values.length - 1).toDouble(),
        minY: minimum == maximum ? minimum - 1 : minimum,
        maxY: minimum == maximum ? maximum + 1 : maximum,
        gridData: const FlGridData(show: false),
        titlesData: const FlTitlesData(show: false),
        borderData: FlBorderData(show: false),
        lineTouchData: const LineTouchData(enabled: false),
        lineBarsData: [
          LineChartBarData(
            spots: [
              for (var index = 0; index < values.length; index++)
                FlSpot(index.toDouble(), values[index]),
            ],
            isCurved: true,
            preventCurveOverShooting: true,
            color: color,
            barWidth: 2.5,
            dotData: const FlDotData(show: false),
            belowBarData: BarAreaData(
              show: true,
              gradient: LinearGradient(
                colors: [color.withAlpha(55), color.withAlpha(0)],
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
              ),
            ),
          ),
        ],
      ),
      duration: const Duration(milliseconds: 250),
    );
  }
}
