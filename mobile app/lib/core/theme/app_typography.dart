import 'package:flutter/material.dart';

/// Typography scale for the platform. Tajawal is bundled with the app so the
/// Arabic interface keeps identical metrics on every device, including Windows.
abstract final class AppTypography {
  const AppTypography._();

  static const fontFamily = 'Tajawal';

  static TextTheme textTheme(Brightness brightness) {
    final ink = brightness == Brightness.dark ? const Color(0xFFF2F8FB) : const Color(0xFF0A1F2B);
    final muted = brightness == Brightness.dark ? const Color(0xFFA9C1CC) : const Color(0xFF5B7385);

    TextStyle style(
      double size,
      FontWeight weight, {
      double height = 1.35,
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
      displayLarge: style(40, FontWeight.w800, height: 1.16),
      displayMedium: style(34, FontWeight.w800, height: 1.18),
      displaySmall: style(28, FontWeight.w800, height: 1.2),
      headlineLarge: style(28, FontWeight.w800, height: 1.22),
      headlineMedium: style(24, FontWeight.w800, height: 1.26),
      headlineSmall: style(21, FontWeight.w700, height: 1.3),
      titleLarge: style(19, FontWeight.w700, height: 1.32),
      titleMedium: style(16, FontWeight.w700, height: 1.36),
      titleSmall: style(14, FontWeight.w700, height: 1.4),
      bodyLarge: style(16, FontWeight.w400, height: 1.62),
      bodyMedium: style(14, FontWeight.w400, height: 1.6),
      bodySmall: style(12.5, FontWeight.w400, height: 1.55, color: muted),
      labelLarge: style(14, FontWeight.w700, height: 1.25),
      labelMedium: style(12.5, FontWeight.w600, height: 1.25),
      labelSmall: style(11.5, FontWeight.w600, height: 1.25, color: muted, letterSpacing: .1),
    );
  }
}
