import 'package:flutter/material.dart';

import '../l10n/experience_strings.dart';
import '../theme/app_colors.dart';
import '../theme/app_theme.dart';
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

/// A rounded-square icon holder: the flat replacement for `CircleAvatar`.
///
/// The tint is a 7% wash of the semantic colour with a hairline border of the
/// same hue, so a row of these reads as one family instead of a candy palette.
class AppGlyph extends StatelessWidget {
  const AppGlyph({
    super.key,
    required this.icon,
    this.tone,
    this.size = 40,
    this.radius,
    this.neutral = false,
  });

  final IconData icon;
  final Color? tone;
  final double size;
  final double? radius;

  /// Use the quiet neutral surface instead of a colour wash.
  final bool neutral;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final base = AppSemantic.resolve(context, tone ?? scheme.primary);
    final background = neutral
        ? scheme.surfaceContainerHighest
        : AppTint.soft(base, context);
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        color: background,
        borderRadius: BorderRadius.circular(radius ?? (size * .32)),
        border: Border.all(
          color: neutral
              ? scheme.outlineVariant
              : base.withValues(alpha: AppTheme.isDark(context) ? .22 : .14),
        ),
      ),
      child: Icon(
        icon,
        size: size * .46,
        color: neutral ? scheme.onSurfaceVariant : base,
      ),
    );
  }
}

/// Photo or initial holder with the same squircle geometry as [AppGlyph].
class AppPhotoAvatar extends StatelessWidget {
  const AppPhotoAvatar({
    super.key,
    this.url,
    this.size = 40,
    this.icon = Icons.person_outline_rounded,
  });

  final String? url;
  final double size;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final radius = BorderRadius.circular(size * .32);
    final fallback = Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        color: scheme.surfaceContainerHighest,
        borderRadius: radius,
        border: Border.all(color: scheme.outlineVariant),
      ),
      child: Icon(icon, size: size * .46, color: scheme.onSurfaceVariant),
    );
    final url = this.url;
    if (url == null || url.trim().isEmpty) return fallback;
    return ClipRRect(
      borderRadius: radius,
      child: Image.network(
        url,
        width: size,
        height: size,
        fit: BoxFit.cover,
        errorBuilder: (_, __, ___) => fallback,
      ),
    );
  }
}

/// A compact label/value pair, the atom of the detail screens.
class AppField extends StatelessWidget {
  const AppField({
    super.key,
    required this.label,
    required this.value,
    this.icon,
    this.tone,
    this.ltr = false,
    this.crossAxisAlignment = CrossAxisAlignment.start,
  });

  final String label;
  final String value;
  final IconData? icon;
  final Color? tone;
  final bool ltr;
  final CrossAxisAlignment crossAxisAlignment;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final color = tone == null
        ? scheme.onSurface
        : AppSemantic.resolve(context, tone!);
    return Column(
      crossAxisAlignment: crossAxisAlignment,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Row(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            if (icon != null) ...<Widget>[
              Icon(
                icon,
                size: 12,
                color: scheme.onSurfaceVariant.withValues(alpha: .8),
              ),
              const SizedBox(width: 4),
            ],
            Text(label, style: theme.textTheme.labelSmall),
          ],
        ),
        const SizedBox(height: 3),
        Directionality(
          textDirection: ltr ? TextDirection.ltr : TextDirection.rtl,
          child: Text(
            value,
            style: theme.textTheme.bodyMedium?.copyWith(
              fontWeight: FontWeight.w500,
              color: color,
              height: 1.4,
            ),
          ),
        ),
      ],
    );
  }
}

/// The dark "ink" panel used for the one highlighted summary on a page —
/// outstanding balance, hub introduction, sync status.
///
/// A restrained marine wash distinguishes summaries from operational rows.
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

  /// Optional custom treatment for branded summary surfaces.
  final Gradient? gradient;

  /// Optional border tint for a highlighted state.
  final Color? accent;
  final double radius;

  /// Kept for compatibility with existing summary call sites.
  final bool showOrnaments;

  @override
  Widget build(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    final background = dark ? AppColors.darkPanel : AppColors.panel;
    final line = dark ? AppColors.darkBorder : AppColors.panelLine;

    return Container(
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        gradient:
            gradient ??
            LinearGradient(
              begin: AlignmentDirectional.topStart,
              end: AlignmentDirectional.bottomEnd,
              colors: [background, AppColors.panelElevated],
            ),
        borderRadius: BorderRadius.circular(radius),
        border: Border.all(color: accent?.withValues(alpha: .4) ?? line),
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
      padding: EdgeInsetsDirectional.fromSTEB(
        gutter,
        topPadding,
        gutter,
        bottomPadding,
      ),
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

/// Keeps a signed amount and its Arabic unit in separate direction contexts.
/// Accepts the formatted values used by existing screens, including `ريال`.
class AppAmount extends StatelessWidget {
  const AppAmount({
    super.key,
    required this.value,
    this.unit,
    this.style,
    this.unitStyle,
  });

  final String value;
  final String? unit;
  final TextStyle? style;
  final TextStyle? unitStyle;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final hasCurrency = value.trim().endsWith(ExperienceStrings.currency);
    var number = hasCurrency
        ? value
              .trim()
              .substring(
                0,
                value.trim().length - ExperienceStrings.currency.length,
              )
              .trim()
        : value.trim();
    // A formatted negative zero is visually misleading while still equal to zero.
    if (RegExp(r'^-0([,.]0+)?$').hasMatch(number)) number = number.substring(1);
    final suffix = unit ?? (hasCurrency ? ExperienceStrings.currency : null);
    return Row(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.baseline,
      textBaseline: TextBaseline.alphabetic,
      children: [
        Flexible(
          child: Directionality(
            textDirection: TextDirection.ltr,
            child: FittedBox(
              fit: BoxFit.scaleDown,
              alignment: AlignmentDirectional.centerStart,
              child: Text(
                number,
                maxLines: 1,
                style: (style ?? theme.textTheme.headlineSmall)?.copyWith(
                  fontFeatures: const [FontFeature.tabularFigures()],
                ),
              ),
            ),
          ),
        ),
        if (suffix != null && suffix.isNotEmpty) ...[
          const SizedBox(width: 5),
          Text(suffix, style: unitStyle ?? theme.textTheme.labelSmall),
        ],
      ],
    );
  }
}
