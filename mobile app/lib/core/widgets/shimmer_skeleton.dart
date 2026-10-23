import 'package:flutter/material.dart';
import 'package:shimmer/shimmer.dart';

import '../theme/app_colors.dart';

class ShimmerSkeleton extends StatelessWidget {
  const ShimmerSkeleton({super.key, this.height = 84, this.width = double.infinity, this.radius = 18});

  final double height;
  final double width;
  final double radius;

  @override
  Widget build(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    final base = dark ? AppColors.darkSurfaceAlt : AppColors.surfaceAlt;
    final highlight = dark ? AppColors.darkBorder : AppColors.surface;
    return Shimmer.fromColors(
      baseColor: base,
      highlightColor: highlight,
      child: Container(
        height: height,
        width: width,
        decoration: BoxDecoration(color: base, borderRadius: BorderRadius.circular(radius)),
      ),
    );
  }
}
