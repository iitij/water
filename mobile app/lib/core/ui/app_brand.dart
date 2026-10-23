import 'package:flutter/material.dart';

import '../config/app_config.dart';
import '../theme/app_colors.dart';
import 'app_tokens.dart';

/// The product mark: a rounded squircle with the aqua water-drop artwork.
///
/// The bundled bitmap (`assets/branding/app_mark.png`) is generated from
/// `assets/branding/app_icon_full.png` by `scripts/generate_brand_assets.py`,
/// which also emits the launcher icons and splash drawables for every
/// platform so the in-app mark and the home-screen icon always match.
class AppBrandMark extends StatelessWidget {
  const AppBrandMark({
    super.key,
    this.size = 64,
    this.glow = true,
    this.semanticLabel = AppConfig.appName,
  });

  /// Edge length of the square mark.
  final double size;

  /// Whether to paint the soft aqua halo behind the mark.
  final bool glow;

  /// Accessibility label; pass `null` when the mark is purely decorative.
  final String? semanticLabel;

  @override
  Widget build(BuildContext context) {
    final mark = Image.asset(
      'assets/branding/app_mark.png',
      width: size,
      height: size,
      filterQuality: FilterQuality.high,
      excludeFromSemantics: true,
    );
    final content = glow
        ? DecoratedBox(
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(size * .26),
              boxShadow: AppShadows.brand(AppColors.primaryBright, opacity: .34),
            ),
            child: mark,
          )
        : mark;

    if (semanticLabel == null) {
      return ExcludeSemantics(child: content);
    }
    return Semantics(
      label: semanticLabel,
      image: true,
      child: ExcludeSemantics(child: content),
    );
  }
}

/// Mark plus product name, used on the splash and auth headers.
class AppBrandLockup extends StatelessWidget {
  const AppBrandLockup({
    super.key,
    this.markSize = 56,
    this.title = AppConfig.appName,
    this.subtitle,
    this.onDark = true,
    this.useMarkGlow = false,
  });

  final double markSize;
  final String title;
  final String? subtitle;
  final bool onDark;
  final bool useMarkGlow;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final titleColor = onDark ? Colors.white : theme.colorScheme.onSurface;
    final subtitleColor = onDark
        ? Colors.white.withValues(alpha: .78)
        : theme.colorScheme.onSurfaceVariant;

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        AppBrandMark(size: markSize, glow: useMarkGlow),
        const SizedBox(width: 13),
        Flexible(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              Text(
                title,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.titleLarge?.copyWith(
                  color: titleColor,
                  fontWeight: FontWeight.w800,
                ),
              ),
              if (subtitle != null) ...<Widget>[
                const SizedBox(height: 2),
                Text(
                  subtitle!,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.labelSmall?.copyWith(color: subtitleColor),
                ),
              ],
            ],
          ),
        ),
      ],
    );
  }
}
