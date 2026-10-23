import 'package:flutter/material.dart';
import 'package:shimmer/shimmer.dart';

import '../theme/app_colors.dart';

/// هيكل تحميل عظمي (`Skeleton Shimmer`) — قاعدة UX §3.4-3.
class SkeletonBox extends StatelessWidget {
  const SkeletonBox({
    super.key,
    this.height = 16,
    this.width,
    this.radius = 8,
  });

  final double height;
  final double? width;
  final double radius;

  @override
  Widget build(BuildContext context) {
    final isLight = Theme.of(context).brightness == Brightness.light;
    return Container(
      height: height,
      width: width,
      decoration: BoxDecoration(
        color: isLight ? const Color(0xFFE2E8F0) : const Color(0xFF334155),
        borderRadius: BorderRadius.circular(radius),
      ),
    );
  }
}

/// بطاقة هيكل تحميل بحجم بطاقة الفاتورة/السند.
class SkeletonCard extends StatelessWidget {
  const SkeletonCard({super.key, this.lines = 3, this.dense = false});

  final int lines;
  final bool dense;

  @override
  Widget build(BuildContext context) {
    return Shimmer.fromColors(
      baseColor: Colors.transparent,
      highlightColor: Colors.white24,
      enabled: true,
      child: Container(
        padding: EdgeInsets.all(dense ? 10 : 14),
        decoration: BoxDecoration(
          color: Theme.of(context).cardColor,
          borderRadius: BorderRadius.circular(18),
          border: Border.all(color: AppColors.lightBorder),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Row(
              children: <Widget>[
                const SkeletonBox(height: 34, width: 34, radius: 12),
                const SizedBox(width: 10),
                Expanded(child: SkeletonBox(height: 14, width: double.infinity)),
                const SizedBox(width: 8),
                const SkeletonBox(height: 20, width: 64, radius: 999),
              ],
            ),
            const SizedBox(height: 12),
            for (var i = 0; i < lines; i++) ...<Widget>[
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: SkeletonBox(
                  height: 12,
                  width: i.isEven ? double.infinity : 160,
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// قائمة هياكل تحميل جاهزة للشاشات القائمة.
class SkeletonList extends StatelessWidget {
  const SkeletonList({super.key, this.count = 6, this.lines = 3});

  final int count;
  final int lines;

  @override
  Widget build(BuildContext context) {
    return ListView.separated(
      padding: const EdgeInsets.all(12),
      physics: const NeverScrollableScrollPhysics(),
      itemCount: count,
      separatorBuilder: (_, __) => const SizedBox(height: 10),
      itemBuilder: (_, __) => SkeletonCard(lines: lines),
    );
  }
}
