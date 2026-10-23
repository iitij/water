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
    if (width < 600) return 18;
    if (width < 1000) return 24;
    return 32;
  }
}

/// Corner radii. Large, soft radii are what give the UI its modern feel.
abstract final class AppRadius {
  const AppRadius._();

  static const double xs = 10;
  static const double sm = 12;
  static const double md = 16;
  static const double lg = 20;
  static const double xl = 26;
  static const double xxl = 32;
  static const double pill = 999;

  static BorderRadius all(double value) => BorderRadius.circular(value);
  static BorderRadius get card => BorderRadius.circular(xl);
  static BorderRadius get field => BorderRadius.circular(md);
  static BorderRadius get pillAll => BorderRadius.circular(pill);
}

/// Motion tokens. Animations stay short so the app feels fast on low-end phones.
abstract final class AppMotion {
  const AppMotion._();

  static const Duration fast = Duration(milliseconds: 150);
  static const Duration normal = Duration(milliseconds: 260);
  static const Duration slow = Duration(milliseconds: 460);
  static const Duration press = Duration(milliseconds: 90);
}

/// Ambient shadow recipes for cards and floating surfaces.
abstract final class AppShadows {
  const AppShadows._();

  static List<BoxShadow> card(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    return <BoxShadow>[
      BoxShadow(
        color: Colors.black.withValues(alpha: dark ? .28 : .045),
        blurRadius: 26,
        offset: const Offset(0, 12),
        spreadRadius: -6,
      ),
      BoxShadow(
        color: AppColors.primaryDark.withValues(alpha: dark ? .18 : .03),
        blurRadius: 6,
        offset: const Offset(0, 2),
      ),
    ];
  }

  static List<BoxShadow> brand(Color color, {double opacity = .3}) => <BoxShadow>[
        BoxShadow(
          color: color.withValues(alpha: opacity),
          blurRadius: 26,
          offset: const Offset(0, 12),
          spreadRadius: -8,
        ),
      ];

  static List<BoxShadow> floating(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    return <BoxShadow>[
      BoxShadow(
        color: Colors.black.withValues(alpha: dark ? .45 : .1),
        blurRadius: 30,
        offset: const Offset(0, 12),
        spreadRadius: -8,
      ),
    ];
  }
}

/// Small helpers for tinted brand surfaces.
abstract final class AppTint {
  const AppTint._();

  /// Soft colour wash used behind icons, avatars and chips.
  static Color soft(Color color, BuildContext context) =>
      color.withValues(alpha: Theme.of(context).brightness == Brightness.dark ? .18 : .1);

  static LinearGradient gradient(Color color, BuildContext context) => LinearGradient(
        begin: AlignmentDirectional.topStart,
        end: AlignmentDirectional.bottomEnd,
        colors: <Color>[
          color.withValues(alpha: Theme.of(context).brightness == Brightness.dark ? .34 : .2),
          color.withValues(alpha: Theme.of(context).brightness == Brightness.dark ? .12 : .06),
        ],
      );
}
