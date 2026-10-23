import 'package:flutter/material.dart';
import 'package:shimmer/shimmer.dart';

class ShimmerSkeleton extends StatelessWidget {
  const ShimmerSkeleton({super.key, this.height = 84, this.width = double.infinity, this.radius = 18});

  final double height;
  final double width;
  final double radius;

  @override
  Widget build(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    final base = dark ? const Color(0xFF1D2926) : const Color(0xFFECEFED);
    final highlight = dark ? const Color(0xFF283632) : const Color(0xFFF7F9F8);
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
