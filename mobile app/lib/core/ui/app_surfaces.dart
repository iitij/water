import 'package:flutter/material.dart';

import '../theme/app_colors.dart';
import 'app_tokens.dart';

/// The base surface used across the app.
///
/// Flat white (or `darkSurface`) with one hairline border and no shadow: the
/// contrast against the tinted page background is enough to read the card, and
/// removing shadows keeps long lists calm on low-end screens.
class AppCard extends StatelessWidget {
  const AppCard({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(AppSpacing.md),
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

  /// Deprecated: gradients were removed from the system. Accepted for
  /// compatibility and ignored.
  final Gradient? gradient;
  final Color? borderColor;
  final double radius;

  /// Deprecated: cards are flat. Accepted for compatibility and ignored.
  final bool elevated;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final borderRadius = BorderRadius.circular(radius);

    Widget content = Padding(padding: padding, child: child);
    if (onTap != null) {
      content = InkWell(
        onTap: onTap,
        borderRadius: borderRadius,
        splashColor: scheme.primary.withValues(alpha: .05),
        highlightColor: scheme.primary.withValues(alpha: .03),
        child: content,
      );
    }

    return Container(
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        color: color ?? scheme.surface,
        borderRadius: borderRadius,
        border: Border.all(color: borderColor ?? scheme.outlineVariant),
      ),
      child: Material(type: MaterialType.transparency, child: content),
    );
  }
}

/// The dark "ink" panel used for the one highlighted summary on a page —
/// outstanding balance, hub introduction, sync status.
///
/// It is intentionally flat: solid ink, a hairline inner border and generous
/// padding. The accent colour appears only inside the panel's primary action.
class AppHeroPanel extends StatelessWidget {
  const AppHeroPanel({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(AppSpacing.lg),
    this.gradient,
    this.accent,
    this.radius = AppRadius.xxl,
    this.showOrnaments = true,
  });

  final Widget child;
  final EdgeInsetsGeometry padding;

  /// Deprecated: ignored. Kept so existing call sites compile.
  final Gradient? gradient;

  /// Deprecated: ignored.
  final Color? accent;
  final double radius;

  /// Deprecated: ignored (no decorative ornaments).
  final bool showOrnaments;

  @override
  Widget build(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    final background = dark ? AppColors.darkPanel : AppColors.panel;
    final line = dark ? AppColors.darkBorder : AppColors.panelLine;

    return Container(
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        color: background,
        borderRadius: BorderRadius.circular(radius),
        border: Border.all(color: line),
      ),
      child: Padding(padding: padding, child: child),
    );
  }
}

/// Section title row: a quiet label, an optional one-line description and an
/// optional trailing action. No accent bars, no icons competing with the title.
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
    final scheme = theme.colorScheme;
    return Row(
      crossAxisAlignment: CrossAxisAlignment.center,
      children: <Widget>[
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Row(
                children: <Widget>[
                  if (icon != null) ...<Widget>[
                    Icon(icon, size: 16, color: scheme.onSurfaceVariant),
                    const SizedBox(width: 6),
                  ],
                  Flexible(
                    child: Text(
                      title,
                      style: theme.textTheme.titleLarge,
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
    this.maxWidth = 1120,
    this.bottomPadding = 110,
    this.topPadding = 14,
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

/// Hairline used to separate rows inside a card.
class AppDivider extends StatelessWidget {
  const AppDivider({super.key, this.indent = 0, this.endIndent = 0});

  final double indent;
  final double endIndent;

  @override
  Widget build(BuildContext context) {
    return Container(
      height: 1,
      margin: EdgeInsetsDirectional.only(start: indent, end: endIndent),
      color: Theme.of(context).colorScheme.outlineVariant,
    );
  }
}

/// Small vertical rhythm helper so screens stay visually consistent.
class Gap extends StatelessWidget {
  const Gap(this.size, {super.key});

  final double size;

  @override
  Widget build(BuildContext context) => SizedBox(height: size, width: size);
}
