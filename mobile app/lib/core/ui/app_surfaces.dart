import 'package:flutter/material.dart';

import '../theme/app_colors.dart';
import 'app_tokens.dart';

/// The base surface used across the app: soft radius, hairline border and a
/// diffuse ambient shadow that separates cards from the tinted background.
class AppCard extends StatelessWidget {
  const AppCard({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(AppSpacing.md + 2),
    this.onTap,
    this.color,
    this.gradient,
    this.borderColor,
    this.radius = AppRadius.xl,
    this.elevated = true,
  });

  final Widget child;
  final EdgeInsetsGeometry padding;
  final VoidCallback? onTap;
  final Color? color;
  final Gradient? gradient;
  final Color? borderColor;
  final double radius;
  final bool elevated;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final dark = Theme.of(context).brightness == Brightness.dark;
    final borderRadius = BorderRadius.circular(radius);

    Widget content = Padding(padding: padding, child: child);
    if (onTap != null) {
      content = InkWell(
        onTap: onTap,
        borderRadius: borderRadius,
        splashColor: scheme.primary.withValues(alpha: .06),
        highlightColor: scheme.primary.withValues(alpha: .03),
        child: content,
      );
    }

    return Container(
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        color: gradient == null ? (color ?? scheme.surface) : null,
        gradient: gradient,
        borderRadius: borderRadius,
        border: Border.all(
          color: borderColor ??
              (dark
                  ? scheme.outlineVariant.withValues(alpha: .85)
                  : scheme.outlineVariant.withValues(alpha: .95)),
        ),
        boxShadow: elevated ? AppShadows.card(context) : null,
      ),
      child: Material(type: MaterialType.transparency, child: content),
    );
  }
}

/// A bold gradient panel used for page heroes and highlighted summaries.
class AppHeroPanel extends StatelessWidget {
  const AppHeroPanel({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(AppSpacing.xl),
    this.gradient = AppColors.oceanGradient,
    this.accent = AppColors.secondary,
    this.radius = AppRadius.xxl,
    this.showOrnaments = true,
  });

  final Widget child;
  final EdgeInsetsGeometry padding;
  final Gradient gradient;
  final Color accent;
  final double radius;
  final bool showOrnaments;

  @override
  Widget build(BuildContext context) {
    final borderRadius = BorderRadius.circular(radius);
    return Container(
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        gradient: gradient,
        borderRadius: borderRadius,
        boxShadow: AppShadows.brand(AppColors.primary, opacity: .26),
      ),
      child: Stack(
        children: <Widget>[
          if (showOrnaments) ...<Widget>[
            PositionedDirectional(
              top: -90,
              end: -50,
              child: _Glow(size: 230, color: Colors.white.withValues(alpha: .12)),
            ),
            PositionedDirectional(
              bottom: -120,
              start: -40,
              child: _Glow(size: 260, color: accent.withValues(alpha: .32)),
            ),
            PositionedDirectional(
              top: 40,
              start: 60,
              child: _Glow(size: 70, color: Colors.white.withValues(alpha: .07)),
            ),
            Positioned.fill(
              bottom: 0,
              child: IgnorePointer(
                child: CustomPaint(painter: _HeroWavePainter()),
              ),
            ),
          ],
          Padding(padding: padding, child: child),
        ],
      ),
    );
  }
}

class _Glow extends StatelessWidget {
  const _Glow({required this.size, required this.color});

  final double size;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        gradient: RadialGradient(
          colors: <Color>[color, color.withValues(alpha: 0)],
        ),
      ),
    );
  }
}

class _HeroWavePainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    if (size.width <= 0 || size.height <= 0) return;
    final base = size.height * .74;
    final paint = Paint()
      ..shader = LinearGradient(
        begin: Alignment.centerLeft,
        end: Alignment.centerRight,
        colors: <Color>[
          Colors.white.withValues(alpha: .06),
          Colors.white.withValues(alpha: .015),
        ],
      ).createShader(Rect.fromLTWH(0, base - 30, size.width, size.height - base + 30));

    for (var layer = 0; layer < 2; layer++) {
      final path = Path()..moveTo(0, base + layer * 26);
      final amplitude = 16.0 - layer * 5;
      path.cubicTo(
        size.width * .25,
        base - amplitude + layer * 22,
        size.width * .5,
        base + amplitude + layer * 20,
        size.width,
        base - 4 + layer * 24,
      );
      path.lineTo(size.width, size.height);
      path.lineTo(0, size.height);
      path.close();
      canvas.drawPath(path, paint);
    }
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

/// Section title row with an accent bar, optional subtitle and trailing action.
class SectionHeading extends StatelessWidget {
  const SectionHeading({
    super.key,
    required this.title,
    this.subtitle,
    this.trailing,
    this.icon,
  });

  final String title;
  final String? subtitle;
  final Widget? trailing;
  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Row(
      crossAxisAlignment: CrossAxisAlignment.center,
      children: <Widget>[
        Container(
          width: 4,
          height: subtitle == null ? 18 : 30,
          decoration: BoxDecoration(
            gradient: AppColors.aquaGradient,
            borderRadius: BorderRadius.circular(AppRadius.pill),
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              Row(
                children: <Widget>[
                  if (icon != null) ...<Widget>[
                    Icon(icon, size: 18, color: theme.colorScheme.primary),
                    const SizedBox(width: 6),
                  ],
                  Flexible(
                    child: Text(
                      title,
                      style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                ],
              ),
              if (subtitle != null) ...<Widget>[
                const SizedBox(height: 2),
                Text(
                  subtitle!,
                  style: theme.textTheme.bodySmall,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                ),
              ],
            ],
          ),
        ),
        if (trailing != null) ...<Widget>[const SizedBox(width: 8), trailing!],
      ],
    );
  }
}

/// Standard page body: scrollable, centred and width-limited for tablets and
/// desktop, with room reserved for the floating navigation bar.
class AppPageBody extends StatelessWidget {
  const AppPageBody({
    super.key,
    required this.children,
    this.maxWidth = 1240,
    this.bottomPadding = 118,
    this.topPadding = 12,
    this.scrollController,
    this.onRefresh,
  });

  final List<Widget> children;
  final double maxWidth;
  final double bottomPadding;
  final double topPadding;
  final ScrollController? scrollController;
  final Future<void> Function()? onRefresh;

  @override
  Widget build(BuildContext context) {
    final width = MediaQuery.sizeOf(context).width;
    final gutter = AppSpacing.gutter(width);
    final content = SingleChildScrollView(
      controller: scrollController,
      physics: const AlwaysScrollableScrollPhysics(),
      padding: EdgeInsetsDirectional.fromSTEB(gutter, topPadding, gutter, bottomPadding),
      child: Center(
        child: ConstrainedBox(
          constraints: BoxConstraints(maxWidth: maxWidth),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: children,
          ),
        ),
      ),
    );
    if (onRefresh == null) return content;
    return RefreshIndicator(onRefresh: onRefresh!, child: content);
  }
}

/// Small vertical rhythm helper so screens stay visually consistent.
class Gap extends StatelessWidget {
  const Gap(this.size, {super.key});

  final double size;

  @override
  Widget build(BuildContext context) => SizedBox(height: size, width: size);
}
