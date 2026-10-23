import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_controls.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../admin_shared/presentation/theme/admin_palette.dart';
import '../../../admin_shared/presentation/widgets/admin_widgets.dart';
import '../../domain/entities/dashboard_entities.dart';

class MonthlyRevenueChartCard extends StatelessWidget {
  const MonthlyRevenueChartCard({
    required this.revenue,
    this.title = 'التحصيل الشهري',
    this.subtitle = 'آخر 6 أشهر • المس أي نقطة لعرض القيمة',
    super.key,
  });

  final MonthlyRevenue revenue;
  final String title;
  final String subtitle;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final length = revenue.length;
    final values = revenue.values.take(length).toList(growable: false);
    final labels = revenue.labels.take(length).toList(growable: false);
    final total = values.fold<double>(0, (sum, value) => sum + value);
    final average = values.isEmpty ? 0.0 : total / values.length;
    var bestIndex = 0;
    for (var index = 1; index < values.length; index++) {
      if (values[index] > values[bestIndex]) bestIndex = index;
    }

    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          SectionHeading(
            title: title,
            subtitle: subtitle,
            icon: Icons.show_chart_rounded,
            trailing: AppPill(
              label: AdminFormat.compactMoney(total),
              icon: Icons.account_balance_wallet_outlined,
              color: AppColors.success,
              dense: true,
            ),
          ),
          const Gap(AppSpacing.md),
          if (length == 0)
            const AdminEmptyView(
              title: 'لا توجد بيانات تحصيل للفترة',
              message: 'ابدأ بتسجيل المدفوعات لعرض حركة الإيرادات.',
              icon: Icons.query_stats_rounded,
            )
          else ...<Widget>[
            Row(
              children: <Widget>[
                Expanded(
                  child: _ChartStat(
                    label: 'إجمالي الفترة',
                    value: AdminFormat.compactMoney(total),
                    color: AppColors.success,
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: _ChartStat(
                    label: 'المتوسط الشهري',
                    value: AdminFormat.compactMoney(average),
                    color: AppColors.info,
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: _ChartStat(
                    label: 'أعلى شهر',
                    value: labels[bestIndex].split(' ').first,
                    color: AppColors.violet,
                  ),
                ),
              ],
            ),
            const Gap(AppSpacing.lg),
            SizedBox(
              height: 250,
              child: Directionality(
                textDirection: TextDirection.ltr,
                child: _RevenueChart(
                  values: values,
                  labels: labels,
                  color: theme.colorScheme.primary,
                  gridColor: theme.colorScheme.outlineVariant,
                  surfaceColor: theme.colorScheme.surface,
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _ChartStat extends StatelessWidget {
  const _ChartStat({required this.label, required this.value, required this.color});

  final String label;
  final String value;
  final Color color;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 9),
      decoration: BoxDecoration(
        color: color.withValues(alpha: theme.brightness == Brightness.dark ? .14 : .07),
        borderRadius: BorderRadius.circular(AppRadius.sm),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Text(
            label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.labelSmall,
          ),
          const SizedBox(height: 2),
          Directionality(
            textDirection: TextDirection.ltr,
            child: Text(
              value,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              textAlign: TextAlign.start,
              style: theme.textTheme.titleSmall?.copyWith(
                color: color,
                fontWeight: FontWeight.w800,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _RevenueChart extends StatelessWidget {
  const _RevenueChart({
    required this.values,
    required this.labels,
    required this.color,
    required this.gridColor,
    required this.surfaceColor,
  });

  final List<double> values;
  final List<String> labels;
  final Color color;
  final Color gridColor;
  final Color surfaceColor;

  @override
  Widget build(BuildContext context) {
    final maximum = values.fold<double>(0, (max, value) => value > max ? value : max);
    final maximumY = maximum <= 0 ? 1.0 : maximum * 1.22;
    final interval = maximumY / 4;

    return LineChart(
      LineChartData(
        minX: 0,
        maxX: values.length == 1 ? 1.0 : (values.length - 1).toDouble(),
        minY: 0,
        maxY: maximumY,
        clipData: const FlClipData.all(),
        gridData: FlGridData(
          drawVerticalLine: false,
          horizontalInterval: interval,
          getDrawingHorizontalLine: (value) => FlLine(
            color: gridColor.withValues(alpha: .65),
            strokeWidth: 1,
            dashArray: const <int>[4, 5],
          ),
        ),
        borderData: FlBorderData(show: false),
        titlesData: FlTitlesData(
          topTitles: const AxisTitles(),
          rightTitles: const AxisTitles(),
          leftTitles: AxisTitles(
            sideTitles: SideTitles(
              showTitles: true,
              reservedSize: 50,
              interval: interval,
              getTitlesWidget: (value, meta) {
                if (value <= 0) return const SizedBox.shrink();
                return Padding(
                  padding: const EdgeInsetsDirectional.only(end: 6),
                  child: Text(
                    AdminFormat.number(value >= 1000 ? value / 1000 : value),
                    style: TextStyle(fontSize: 10.5, color: Theme.of(context).colorScheme.onSurfaceVariant),
                  ),
                );
              },
            ),
          ),
          bottomTitles: AxisTitles(
            sideTitles: SideTitles(
              showTitles: true,
              reservedSize: 34,
              interval: 1,
              getTitlesWidget: (value, meta) {
                final index = value.toInt();
                if (index < 0 || index >= labels.length || value != index) {
                  return const SizedBox.shrink();
                }
                return Padding(
                  padding: const EdgeInsets.only(top: 10),
                  child: Text(
                    labels[index].split(' ').first,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      fontSize: 10.5,
                      fontWeight: FontWeight.w600,
                      color: Theme.of(context).colorScheme.onSurfaceVariant,
                    ),
                  ),
                );
              },
            ),
          ),
        ),
        lineTouchData: LineTouchData(
          handleBuiltInTouches: true,
          touchTooltipData: LineTouchTooltipData(
            getTooltipColor: (_) => AdminPalette.primaryDark,
            tooltipRoundedRadius: 12,
            getTooltipItems: (spots) => spots
                .map(
                  (spot) => LineTooltipItem(
                    '${labels[spot.x.toInt()]}\n${AdminFormat.money(spot.y)}',
                    const TextStyle(
                      color: Colors.white,
                      fontWeight: FontWeight.w700,
                      fontSize: 12,
                    ),
                  ),
                )
                .toList(growable: false),
          ),
        ),
        lineBarsData: <LineChartBarData>[
          LineChartBarData(
            spots: <FlSpot>[
              for (var index = 0; index < values.length; index++)
                FlSpot(index.toDouble(), values[index]),
            ],
            isCurved: true,
            preventCurveOverShooting: true,
            curveSmoothness: .22,
            color: color,
            barWidth: 3,
            isStrokeCapRound: true,
            dotData: FlDotData(
              show: true,
              getDotPainter: (spot, percent, bar, index) => FlDotCirclePainter(
                radius: 3.4,
                color: color,
                strokeWidth: 2,
                strokeColor: surfaceColor,
              ),
            ),
            belowBarData: BarAreaData(
              show: true,
              gradient: LinearGradient(
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
                colors: <Color>[
                  color.withValues(alpha: .34),
                  color.withValues(alpha: .02),
                ],
              ),
            ),
          ),
        ],
      ),
      duration: const Duration(milliseconds: 420),
      curve: Curves.easeOutCubic,
    );
  }
}
