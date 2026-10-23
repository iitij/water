import 'package:flutter/material.dart';

class AppTypography {
  const AppTypography._();

  static const fontFamily = 'IBMPlexSansArabic';

  static TextTheme textTheme(Brightness brightness) {
    final color = brightness == Brightness.dark
        ? const Color(0xFFF1F5F9)
        : const Color(0xFF102A43);
    return TextTheme(
      displayLarge: TextStyle(fontFamily: fontFamily, fontSize: 34, height: 1.15, fontWeight: FontWeight.w800, color: color),
      displayMedium: TextStyle(fontFamily: fontFamily, fontSize: 30, height: 1.2, fontWeight: FontWeight.w800, color: color),
      displaySmall: TextStyle(fontFamily: fontFamily, fontSize: 26, height: 1.2, fontWeight: FontWeight.w700, color: color),
      headlineLarge: TextStyle(fontFamily: fontFamily, fontSize: 26, height: 1.25, fontWeight: FontWeight.w800, color: color),
      headlineMedium: TextStyle(fontFamily: fontFamily, fontSize: 22, height: 1.3, fontWeight: FontWeight.w700, color: color),
      headlineSmall: TextStyle(fontFamily: fontFamily, fontSize: 20, height: 1.3, fontWeight: FontWeight.w700, color: color),
      titleLarge: TextStyle(fontFamily: fontFamily, fontSize: 20, height: 1.3, fontWeight: FontWeight.w700, color: color),
      titleMedium: TextStyle(fontFamily: fontFamily, fontSize: 16, height: 1.35, fontWeight: FontWeight.w700, color: color),
      titleSmall: TextStyle(fontFamily: fontFamily, fontSize: 14, height: 1.35, fontWeight: FontWeight.w600, color: color),
      bodyLarge: TextStyle(fontFamily: fontFamily, fontSize: 16, height: 1.5, color: color),
      bodyMedium: TextStyle(fontFamily: fontFamily, fontSize: 14, height: 1.5, color: color),
      bodySmall: TextStyle(fontFamily: fontFamily, fontSize: 12, height: 1.45, color: color.withValues(alpha: .72)),
      labelLarge: TextStyle(fontFamily: fontFamily, fontSize: 14, height: 1.2, fontWeight: FontWeight.w700, color: color),
      labelMedium: TextStyle(fontFamily: fontFamily, fontSize: 12, height: 1.2, fontWeight: FontWeight.w600, color: color),
      labelSmall: TextStyle(fontFamily: fontFamily, fontSize: 11, height: 1.2, fontWeight: FontWeight.w500, color: color),
    );
  }
}
