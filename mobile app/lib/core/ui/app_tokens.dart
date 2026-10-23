import 'package:flutter/material.dart';

import '../theme/app_colors.dart';

/// Layout constants shared by every screen.
abstract final class AppSpacing {
  const AppSpacing._();

  static const double xxs = 4;
  static const double xs = 8;
  static const double sm = 12;
  static const double md = 16;
  static const double lg = 20;
  static const double xl = 24;
  static const double xxl = 32;
  static const double xxxl = 44;

  /// Standard page gutter used by the shell pages.
  static double gutter(double width) {
    if (width < 360) return 14;
    if (width < 600) return 16;
    if (width < 1000) return 24;
    return 32;
  }
}

/// Corner radii. The system keeps corners modest — 12 for controls, 16–18 for
/// cards and 22 for full-width summary panels.
abstract final class AppRadius {
  const AppRadius._();

  static const double xs = 8;
  static const double sm = 10;
  static const double md = 12;
  static const double lg = 16;
  static const double xl = 18;
  static const double xxl = 22;
  static const double pill = 999;

  static BorderRadius all(double value) => BorderRadius.circular(value);
  static BorderRadius get card => BorderRadius.circular(xl);
  static BorderRadius get field => BorderRadius.circular(md);
  static BorderRadius get pillAll => BorderRadius.circular(pill);
}

/// Motion tokens. Animations stay short so the app feels fast on low-end phones.
abstract final class AppMotion {
  const AppMotion._();

  static const Duration fast = Duration(milliseconds: 140);
  static const Duration normal = Duration(milliseconds: 220);
  static const Duration slow = Duration(milliseconds: 380);
  static const Duration press = Duration(milliseconds: 90);
  static const Curve curve = Curves.easeOutCubic;
}

/// Shadow recipes.
///
/// The system is flat: cards are separated by a hairline border, not by
/// shadow. Only surfaces that actually float above content (the navigation
/// bar, sheets, menus) get elevation.
abstract final class AppShadows {
  const AppShadows._();

  /// Cards and panels stay flat.
  static List<BoxShadow> card(BuildContext context) => const <BoxShadow>[];

  /// Brand glow is no longer used; kept so older screens still compile.
  static List<BoxShadow> brand(Color color, {double opacity = .3}) => const <BoxShadow>[];

  /// The single elevation recipe, reserved for floating chrome.
  static List<BoxShadow> floating(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    return <BoxShadow>[
      BoxShadow(
        color: Colors.black.withValues(alpha: dark ? .5 : .06),
        blurRadius: 24,
        offset: const Offset(0, 10),
        spreadRadius: -6,
      ),
    ];
  }
}

/// Small helpers for tinted brand surfaces. Tints are very light (6–7%) and
/// never sit behind body copy.
abstract final class AppTint {
  const AppTint._();

  /// Soft colour wash used behind icons and avatars.
  static Color soft(Color color, BuildContext context) {
    final resolved = AppSemantic.resolve(context, color);
    return resolved.withValues(
      alpha: Theme.of(context).brightness == Brightness.dark ? .16 : .07,
    );
  }

  /// Flat tint (the interface no longer uses decorative gradients).
  static LinearGradient gradient(Color color, BuildContext context) =>
      LinearGradient(colors: <Color>[soft(color, context), soft(color, context)]);

  /// Neutral surface wash used for icon holders.
  static Color neutral(BuildContext context) =>
      Theme.of(context).brightness == Brightness.dark
          ? AppColors.darkSurfaceAlt
          : AppColors.surfaceAlt;
}

/// Semantic tints shared by notices, badges and chart legends.
abstract final class AppSemantic {
  const AppSemantic._();

  static Color resolve(BuildContext context, Color color) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    if (!dark) return color;
    if (color == AppColors.success) return const Color(0xFF6FD3A0);
    if (color == AppColors.warning) return const Color(0xFFE0B052);
    if (color == AppColors.danger) return const Color(0xFFF08A95);
    if (color == AppColors.info) return const Color(0xFF7DB4DA);
    if (color == AppColors.primary ||
        color == AppColors.accent ||
        color == AppColors.accentOnPanel ||
        color == AppColors.secondary) {
      return AppColors.darkAccent;
    }
    if (color == AppColors.neutral || color == AppColors.violet) {
      return AppColors.darkInkSoft;
    }
    return color;
  }
}
