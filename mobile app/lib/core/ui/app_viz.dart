import 'dart:math' as math;

import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';

import '../theme/app_colors.dart';
import 'app_controls.dart';
import 'app_tokens.dart';

/// Smooth sparkline used inside metric cards. Deliberately chromeless: no
/// axes, no grid and no touch handling so it reads as a tiny trend glyph.
class AppSparkline extends StatelessWidget {
  const AppSparkline({
    super.key,
    required this.values,
    required this.color,
    this.height = 34,
  });

  final List<double> values;
  final Color color;
  final double height;

  @override
  Widget build(BuildContext context) {
    if (values.length < 2) {
      return SizedBox(height: height);
    }
    final maximum = values.reduce(math.max);
    final minimum = values.reduce(math.min);
    final padding = (maximum - minimum).abs() < .001 ? 1.0 : (maximum - minimum) * .18;
    return SizedBox(
      height: height,
      child: LineChart(
        LineChartData(
          minX: 0,
          maxX: (values.length - 1).toDouble(),
          minY: minimum - padding,
          maxY: maximum + padding,
          gridData: const FlGridData(show: false),
          titlesData: const FlTitlesData(show: false),
          borderData: FlBorderData(show: false),
          lineTouchData: const LineTouchData(enabled: false),
          lineBarsData: <LineChartBarData>[
            LineChartBarData(
              spots: <FlSpot>[
                for (var index = 0; index < values.length; index++)
                  FlSpot(index.toDouble(), values[index]),
              ],
              isCurved: true,
              curveSmoothness: .26,
              preventCurveOverShooting: true,
              color: color,
              barWidth: 2.4,
              isStrokeCapRound: true,
              dotData: const FlDotData(show: false),
              belowBarData: BarAreaData(
                show: true,
                gradient: LinearGradient(
                  begin: Alignment.topCenter,
                  end: Alignment.bottomCenter,
                  colors: <Color>[color.withValues(alpha: .26), color.withValues(alpha: 0)],
                ),
              ),
            ),
          ],
        ),
        duration: AppMotion.slow,
      ),
    );
  }
}

/// Circular progress ring with a gradient stroke and centred content.
class AppProgressRing extends StatelessWidget {
  const AppProgressRing({
    super.key,
    required this.value,
    this.size = 96,
    this.strokeWidth = 9,
    this.color = AppColors.primary,
    this.child,
  });

  /// Value between 0 and 1.
  final double value;
  final double size;
  final double strokeWidth;
  final Color color;
  final Widget? child;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return SizedBox.square(
      dimension: size,
      child: TweenAnimationBuilder<double>(
        tween: Tween<double>(begin: 0, end: value.clamp(0, 1).toDouble()),
        duration: const Duration(milliseconds: 900),
        curve: Curves.easeOutCubic,
        builder: (context, animated, _) => CustomPaint(
          painter: _RingPainter(
            progress: animated,
            strokeWidth: strokeWidth,
            color: color,
            track: scheme.outlineVariant.withValues(alpha: .55),
          ),
          child: Center(child: child),
        ),
      ),
    );
  }
}

class _RingPainter extends CustomPainter {
  const _RingPainter({
    required this.progress,
    required this.strokeWidth,
    required this.color,
    required this.track,
  });

  final double progress;
  final double strokeWidth;
  final Color color;
  final Color track;

  @override
  void paint(Canvas canvas, Size size) {
    final center = size.center(Offset.zero);
    final radius = (size.shortestSide - strokeWidth) / 2;
    final trackPaint = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = strokeWidth
      ..strokeCap = StrokeCap.round
      ..color = track;
    canvas.drawCircle(center, radius, trackPaint);

    if (progress <= 0) return;
    final rect = Rect.fromCircle(center: center, radius: radius);
    final sweep = (2 * math.pi * progress).clamp(0, 2 * math.pi);
    final gradient = SweepGradient(
      startAngle: -math.pi / 2,
      endAngle: 3 * math.pi / 2,
      colors: <Color>[color.withValues(alpha: .55), color],
    ).createShader(rect);

    final paint = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = strokeWidth
      ..strokeCap = StrokeCap.round
      ..shader = gradient;
    canvas.drawArc(rect, -math.pi / 2, sweep, false, paint);
  }

  @override
  bool shouldRepaint(covariant _RingPainter oldDelegate) =>
      oldDelegate.progress != progress ||
      oldDelegate.color != color ||
      oldDelegate.strokeWidth != strokeWidth;
}

/// Compact bar chart for seven values (days or weeks).
class AppMiniBars extends StatelessWidget {
  const AppMiniBars({
    super.key,
    required this.values,
    this.color = AppColors.primary,
    this.height = 56,
    this.labels = const <String>[],
  });

  final List<double> values;
  final Color color;
  final double height;
  final List<String> labels;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    if (values.isEmpty) return SizedBox(height: height);
    final maximum = values.reduce(math.max);
    return Column(
      children: <Widget>[
        SizedBox(
          height: height,
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: <Widget>[
              for (var index = 0; index < values.length; index++)
                Expanded(
                  child: Padding(
                    padding: EdgeInsetsDirectional.only(
                      start: index == 0 ? 0 : 4,
                      end: index == values.length - 1 ? 0 : 4,
                    ),
                    child: TweenAnimationBuilder<double>(
                      tween: Tween<double>(begin: 0, end: maximum <= 0 ? 0 : values[index] / maximum),
                      duration: Duration(milliseconds: 600 + index * 70),
                      curve: Curves.easeOutCubic,
                      builder: (context, factor, _) => Align(
                        alignment: Alignment.bottomCenter,
                        child: Container(
                          height: (height - 2) * factor.clamp(.05, 1),
                          decoration: BoxDecoration(
                            borderRadius: BorderRadius.circular(AppRadius.xs),
                            gradient: LinearGradient(
                              begin: Alignment.bottomCenter,
                              end: Alignment.topCenter,
                              colors: <Color>[
                                color.withValues(alpha: .45),
                                color,
                              ],
                            ),
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ),
        if (labels.isNotEmpty) ...<Widget>[
          const SizedBox(height: 7),
          Row(
            children: <Widget>[
              for (var index = 0; index < values.length; index++)
                Expanded(
                  child: Text(
                    index < labels.length ? labels[index] : '',
                    textAlign: TextAlign.center,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(fontSize: 10.5, color: scheme.onSurfaceVariant),
                  ),
                ),
            ],
          ),
        ],
      ],
    );
  }
}

/// Up/down delta pill used next to metrics.
class AppTrendChip extends StatelessWidget {
  const AppTrendChip({
    super.key,
    required this.value,
    this.suffix = '%',
    this.positiveIsGood = true,
    this.inverse = false,
  });

  final double value;
  final String suffix;
  final bool positiveIsGood;
  final bool inverse;

  @override
  Widget build(BuildContext context) {
    final negative = inverse ? value > 0 : value < 0;
    final color = negative ? AppColors.danger : AppColors.success;
    final formatted = value.abs() % 1 == 0 ? value.abs().toStringAsFixed(0) : value.abs().toStringAsFixed(1);
    return AppPill(
      label: '$formatted$suffix',
      icon: negative ? Icons.south_east_rounded : Icons.north_east_rounded,
      color: color,
      dense: true,
    );
  }
}
