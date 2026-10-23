import 'package:flutter/material.dart';

import 'app_colors.dart';

/// Typography scale for the platform.
///
/// IBM Plex Sans Arabic is bundled with the app (see `pubspec.yaml`), so the
/// Arabic interface keeps identical metrics on every device, including Windows.
/// The scale is deliberately small: four sizes carry most of the interface
/// (26 / 17 / 13.5 / 11) and weight — not colour — creates hierarchy.
abstract final class AppTypography {
  const AppTypography._();

  static const fontFamily = 'IBMPlexSansArabic';

  static TextTheme textTheme(Brightness brightness) {
    final dark = brightness == Brightness.dark;
    final ink = dark ? AppColors.darkInk : AppColors.ink;
    final muted = dark ? AppColors.darkMuted : AppColors.muted;

    TextStyle style(
      double size,
      FontWeight weight, {
      double height = 1.4,
      Color? color,
      double letterSpacing = 0,
    }) =>
        TextStyle(
          fontFamily: fontFamily,
          fontSize: size,
          height: height,
          fontWeight: weight,
          color: color ?? ink,
          letterSpacing: letterSpacing,
        );

    return TextTheme(
      displayLarge: style(34, FontWeight.w600, height: 1.18, letterSpacing: -.4),
      displayMedium: style(30, FontWeight.w600, height: 1.2, letterSpacing: -.3),
      displaySmall: style(26, FontWeight.w600, height: 1.24, letterSpacing: -.2),
      headlineLarge: style(24, FontWeight.w600, height: 1.26, letterSpacing: -.2),
      headlineMedium: style(21, FontWeight.w600, height: 1.3, letterSpacing: -.2),
      headlineSmall: style(19, FontWeight.w600, height: 1.32),
      titleLarge: style(17, FontWeight.w600, height: 1.34, letterSpacing: -.1),
      titleMedium: style(15, FontWeight.w600, height: 1.4),
      titleSmall: style(13.5, FontWeight.w600, height: 1.45),
      bodyLarge: style(15, FontWeight.w400, height: 1.65),
      bodyMedium: style(13.5, FontWeight.w400, height: 1.6),
      bodySmall: style(12, FontWeight.w400, height: 1.55, color: muted),
      labelLarge: style(13, FontWeight.w600, height: 1.3),
      labelMedium: style(12, FontWeight.w500, height: 1.3),
      labelSmall: style(11, FontWeight.w500, height: 1.3, color: muted, letterSpacing: .1),
    );
  }

  /// The tabular, wide-tracked style used for money and identifiers. Numbers
  /// are always rendered left-to-right inside an RTL layout.
  static TextStyle numeric(BuildContext context, {double size = 26, FontWeight weight = FontWeight.w600}) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    return TextStyle(
      fontFamily: fontFamily,
      fontSize: size,
      fontWeight: weight,
      height: 1.15,
      letterSpacing: -.4,
      color: dark ? AppColors.darkInk : AppColors.ink,
      fontFeatures: const <FontFeature>[FontFeature.tabularFigures()],
    );
  }
}
