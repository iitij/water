import 'package:flutter/material.dart';

import '../ui/app_surfaces.dart';
import '../ui/app_tokens.dart';

/// Formerly a frosted-glass panel. The design system is flat now, so this is a
/// plain surface card with the same API.
class AquaGlassCard extends StatelessWidget {
  const AquaGlassCard({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(AppSpacing.lg),
  });

  final Widget child;
  final EdgeInsetsGeometry padding;

  @override
  Widget build(BuildContext context) {
    return AppCard(padding: padding, radius: AppRadius.xxl, child: child);
  }
}
