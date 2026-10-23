import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';

import '../../../admin_shared/presentation/theme/admin_palette.dart';
import '../../../admin_shared/presentation/widgets/admin_widgets.dart';
import '../../domain/entities/dashboard_entities.dart';

class MonthlyRevenueChartCard extends StatelessWidget {
  const MonthlyRevenueChartCard({
    required this.revenue,
    this.title = 'التحصيل الشهري',
    this.subtitle = 'آخر 6 أشهر • المس أي نقطة لعرض القيمة الدقيقة',
    super.key,
  });

  final MonthlyRevenue revenue;
  final String title;
  final String subtitle;

  @override
  Widget build(BuildContext context) {
    return AdminCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          AdminSectionHeader(title: title, subtitle: subtitle, icon: Icons.show_chart_rounded),
          const SizedBox(height: 20),
          if (revenue.length == 0)
            const AdminEmptyView(
              title: 'لا توجد بيانات تحصيل للفترة',
              icon: Icons.query_stats_rounded,
            )
          else
            SizedBox(height: 290, child: _RevenueChart(revenue: revenue)),
        ],
      ),
    );
  }
}

class _RevenueChart extends StatelessWidget {
  const _RevenueChart({required this.revenue});

  final MonthlyRevenue revenue;

  @override
  Widget build(BuildContext context) {
    final values = revenue.values.take(revenue.length).toList(growable: false);
    final labels = revenue.labels.take(revenue.length).toList(growable: false);
    final maximum = values.fold<double>(0, (max, value) => value > max ? value : max);
    final maximumY = maximum <= 0 ? 1.0 : maximum * 1.2;
    return LineChart(
      LineChartData(
        minX: 0,
        maxX: values.length == 1 ? 1.0 : (values.length - 1).toDouble(),
        minY: 0,
        maxY: maximumY,
        clipData: const FlClipData.all(),
        gridData: FlGridData(
          drawVerticalLine: false,
          horizontalInterval: maximumY / 4,
          getDrawingHorizontalLine: (value) => FlLine(
            color: Theme.of(context).colorScheme.outlineVariant,
            strokeWidth: 1,
            dashArray: const [4, 4],
          ),
        ),
        borderData: FlBorderData(show: false),
        titlesData: FlTitlesData(
          topTitles: const AxisTitles(),
          rightTitles: const AxisTitles(),
          leftTitles: AxisTitles(
            sideTitles: SideTitles(
              showTitles: true,
              reservedSize: 58,
              interval: maximumY / 4,
              getTitlesWidget: (value, meta) {
                if (value == meta.max || value == meta.min) return const SizedBox.shrink();
                return Directionality(
                  textDirection: TextDirection.ltr,
                  child: Text(
                    AdminFormat.number(value >= 1000 ? value / 1000 : value),
                    style: Theme.of(context).textTheme.labelSmall,
                  ),
                );
              },
            ),
          ),
          bottomTitles: AxisTitles(
            sideTitles: SideTitles(
              showTitles: true,
              reservedSize: 36,
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
                    style: Theme.of(context).textTheme.labelSmall,
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
                    ),
                  ),
                )
                .toList(growable: false),
          ),
        ),
        lineBarsData: [
          LineChartBarData(
            spots: [
              for (var index = 0; index < values.length; index++)
                FlSpot(index.toDouble(), values[index]),
            ],
            isCurved: true,
            preventCurveOverShooting: true,
            curveSmoothness: .2,
            color: AdminPalette.success,
            barWidth: 3,
            isStrokeCapRound: true,
            dotData: FlDotData(
              getDotPainter: (spot, percent, bar, index) => FlDotCirclePainter(
                radius: 4,
                color: AdminPalette.success,
                strokeWidth: 2,
                strokeColor: Theme.of(context).colorScheme.surface,
              ),
            ),
            belowBarData: BarAreaData(
              show: true,
              gradient: LinearGradient(
                colors: [
                  AdminPalette.success.withAlpha(65),
                  AdminPalette.success.withAlpha(0),
                ],
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
              ),
            ),
          ),
        ],
      ),
      duration: const Duration(milliseconds: 350),
      curve: Curves.easeOutCubic,
    );
  }
}
