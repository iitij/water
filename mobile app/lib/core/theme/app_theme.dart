import 'dart:async';

import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'app_colors.dart';
import 'app_typography.dart';

/// Central theme for the platform. Both light and dark variants share the same
/// geometry (radii, paddings, borders) so screens never need brightness checks
/// just to look consistent.
abstract final class AppTheme {
  const AppTheme._();

  static ThemeData get light => _build(Brightness.light);
  static ThemeData get dark => _build(Brightness.dark);

  static bool isDark(BuildContext context) =>
      Theme.of(context).brightness == Brightness.dark;

  static ColorScheme _scheme(Brightness brightness) {
    final isDark = brightness == Brightness.dark;
    return ColorScheme(
      brightness: brightness,
      primary: isDark ? const Color(0xFF35D6C4) : AppColors.primary,
      onPrimary: isDark ? const Color(0xFF032A2C) : Colors.white,
      primaryContainer: isDark ? const Color(0xFF0D4B51) : const Color(0xFFDCF5F2),
      onPrimaryContainer: isDark ? const Color(0xFFC9FBF3) : const Color(0xFF05454C),
      secondary: isDark ? const Color(0xFF67E8F9) : AppColors.secondary,
      onSecondary: const Color(0xFF04252B),
      secondaryContainer: isDark ? const Color(0xFF0F4A55) : const Color(0xFFDDF7FB),
      onSecondaryContainer: isDark ? const Color(0xFFC7F5FC) : const Color(0xFF07505B),
      tertiary: isDark ? const Color(0xFFFBBF24) : AppColors.brandAmber,
      onTertiary: isDark ? const Color(0xFF3B2500) : Colors.white,
      tertiaryContainer: isDark ? const Color(0xFF4A3106) : const Color(0xFFFEF1D8),
      onTertiaryContainer: isDark ? const Color(0xFFFFE5B0) : const Color(0xFF754A00),
      error: isDark ? const Color(0xFFFB7185) : AppColors.danger,
      onError: isDark ? const Color(0xFF450114) : Colors.white,
      errorContainer: isDark ? const Color(0xFF5A0C22) : const Color(0xFFFFE4E9),
      onErrorContainer: isDark ? const Color(0xFFFFD5DE) : const Color(0xFF8C0A2A),
      surface: isDark ? AppColors.darkSurface : AppColors.lightSurface,
      onSurface: isDark ? AppColors.darkInk : AppColors.lightInk,
      surfaceContainerHighest: isDark ? AppColors.darkSurfaceAlt : AppColors.lightSurfaceAlt,
      onSurfaceVariant: isDark ? AppColors.darkMuted : AppColors.lightMuted,
      outline: isDark ? const Color(0xFF33525F) : const Color(0xFFB9CBD6),
      outlineVariant: isDark ? AppColors.darkBorder : AppColors.lightBorder,
      inverseSurface: isDark ? AppColors.lightSurface : AppColors.primaryDark,
      onInverseSurface: isDark ? AppColors.lightInk : Colors.white,
      shadow: Colors.black,
      scrim: Colors.black,
      surfaceTint: Colors.transparent,
    );
  }

  static ThemeData _build(Brightness brightness) {
    final isDark = brightness == Brightness.dark;
    final scheme = _scheme(brightness);
    final background = isDark ? AppColors.darkBackground : AppColors.lightBackground;
    final muted = scheme.onSurfaceVariant;
    final fieldFill = isDark ? const Color(0xFF0F2A35) : const Color(0xFFF6FAFC);

    OutlineInputBorder border(Color color, {double width = 1}) => OutlineInputBorder(
          borderRadius: BorderRadius.circular(16),
          borderSide: BorderSide(color: color, width: width),
        );

    return ThemeData(
      useMaterial3: true,
      brightness: brightness,
      colorScheme: scheme,
      scaffoldBackgroundColor: background,
      canvasColor: background,
      fontFamily: AppTypography.fontFamily,
      textTheme: AppTypography.textTheme(brightness),
      dividerColor: scheme.outlineVariant,
      applyElevationOverlayColor: false,
      visualDensity: VisualDensity.standard,
      splashFactory: InkRipple.splashFactory,
      textSelectionTheme: TextSelectionThemeData(
        cursorColor: scheme.primary,
        selectionColor: scheme.primary.withValues(alpha: .24),
        selectionHandleColor: scheme.primary,
      ),
      inputDecorationTheme: InputDecorationThemeData(
        filled: true,
        fillColor: fieldFill,
        contentPadding: const EdgeInsetsDirectional.fromSTEB(16, 17, 16, 17),
        hintStyle: TextStyle(fontFamily: AppTypography.fontFamily, color: muted.withValues(alpha: .8), fontSize: 14),
        labelStyle: TextStyle(fontFamily: AppTypography.fontFamily, color: muted, fontSize: 14),
        floatingLabelStyle: TextStyle(
          fontFamily: AppTypography.fontFamily,
          color: scheme.primary,
          fontWeight: FontWeight.w700,
          fontSize: 13,
        ),
        prefixIconColor: muted,
        suffixIconColor: muted,
        border: border(scheme.outlineVariant),
        enabledBorder: border(scheme.outlineVariant),
        disabledBorder: border(scheme.outlineVariant.withValues(alpha: .5)),
        focusedBorder: border(scheme.primary, width: 1.6),
        errorBorder: border(scheme.error),
        focusedErrorBorder: border(scheme.error, width: 1.6),
        errorStyle: TextStyle(fontFamily: AppTypography.fontFamily, color: scheme.error, fontSize: 12),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size(0, 50),
          padding: const EdgeInsets.symmetric(horizontal: 22, vertical: 13),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(15)),
          textStyle: const TextStyle(fontFamily: AppTypography.fontFamily, fontSize: 14.5, fontWeight: FontWeight.w700),
          elevation: 0,
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          minimumSize: const Size(0, 50),
          padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 13),
          foregroundColor: scheme.onSurface,
          side: BorderSide(color: scheme.outlineVariant),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(15)),
          textStyle: const TextStyle(fontFamily: AppTypography.fontFamily, fontSize: 14.5, fontWeight: FontWeight.w700),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          foregroundColor: scheme.primary,
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          textStyle: const TextStyle(fontFamily: AppTypography.fontFamily, fontSize: 13.5, fontWeight: FontWeight.w700),
        ),
      ),
      iconButtonTheme: IconButtonThemeData(
        style: ButtonStyle(
          foregroundColor: WidgetStatePropertyAll<Color>(scheme.onSurface),
          overlayColor: WidgetStatePropertyAll<Color>(scheme.primary.withValues(alpha: .08)),
        ),
      ),
      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        backgroundColor: isDark ? const Color(0xFF14323E) : AppColors.primaryDark,
        contentTextStyle: const TextStyle(fontFamily: AppTypography.fontFamily, color: Colors.white, fontSize: 13.5),
        actionTextColor: isDark ? const Color(0xFF5EEAD4) : const Color(0xFF7EE9DE),
        insetPadding: const EdgeInsets.all(16),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        elevation: 6,
      ),
      dividerTheme: DividerThemeData(color: scheme.outlineVariant, thickness: 1, space: 1),
      progressIndicatorTheme: ProgressIndicatorThemeData(
        color: scheme.primary,
        linearTrackColor: scheme.primary.withValues(alpha: .12),
        circularTrackColor: scheme.primary.withValues(alpha: .12),
      ),
      checkboxTheme: CheckboxThemeData(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(6)),
        side: BorderSide(color: scheme.outline, width: 1.4),
        fillColor: WidgetStateProperty.resolveWith((states) {
          if (states.contains(WidgetState.selected)) return scheme.primary;
          return Colors.transparent;
        }),
      ),
      listTileTheme: ListTileThemeData(
        iconColor: scheme.onSurfaceVariant,
        textColor: scheme.onSurface,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 4),
      ),
      bottomSheetTheme: BottomSheetThemeData(
        backgroundColor: scheme.surface,
        surfaceTintColor: Colors.transparent,
        modalBackgroundColor: scheme.surface,
        modalBarrierColor: Colors.black.withValues(alpha: .45),
        shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
        ),
        showDragHandle: true,
        dragHandleColor: scheme.outlineVariant,
      ),
      tooltipTheme: TooltipThemeData(
        decoration: BoxDecoration(
          color: isDark ? const Color(0xFF17363F) : AppColors.primaryDark,
          borderRadius: BorderRadius.circular(10),
        ),
        textStyle: const TextStyle(fontFamily: AppTypography.fontFamily, color: Colors.white, fontSize: 12),
        waitDuration: const Duration(milliseconds: 400),
      ),
      switchTheme: SwitchThemeData(
        thumbColor: WidgetStateProperty.resolveWith((states) {
          if (states.contains(WidgetState.selected)) return Colors.white;
          return isDark ? const Color(0xFF6B8794) : const Color(0xFF8CA3B0);
        }),
        trackColor: WidgetStateProperty.resolveWith((states) {
          if (states.contains(WidgetState.selected)) return scheme.primary;
          return isDark ? const Color(0xFF1D3B48) : const Color(0xFFDCE7EE);
        }),
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: scheme.surface,
        indicatorColor: scheme.primary.withValues(alpha: isDark ? .26 : .12),
        elevation: 0,
        labelTextStyle: WidgetStateProperty.resolveWith(
          (states) => TextStyle(
            fontFamily: AppTypography.fontFamily,
            fontSize: 12,
            fontWeight: states.contains(WidgetState.selected) ? FontWeight.w800 : FontWeight.w500,
          ),
        ),
      ),
      pageTransitionsTheme: const PageTransitionsTheme(
        builders: <TargetPlatform, PageTransitionsBuilder>{
          TargetPlatform.android: ZoomPageTransitionsBuilder(),
          TargetPlatform.iOS: CupertinoPageTransitionsBuilder(),
          TargetPlatform.windows: FadeUpwardsPageTransitionsBuilder(),
          TargetPlatform.linux: FadeUpwardsPageTransitionsBuilder(),
          TargetPlatform.macOS: CupertinoPageTransitionsBuilder(),
        },
      ),
    );
  }
}

/// Holds and persists the user's theme preference.
class AppThemeController extends ChangeNotifier {
  AppThemeController();

  static const String preferenceKey = 'ui.theme_mode';

  ThemeMode _mode = ThemeMode.system;
  bool _restored = false;

  ThemeMode get mode => _mode;
  bool get isSystem => _mode == ThemeMode.system;

  /// Restores the saved preference; safe to call more than once.
  Future<void> initialize() async {
    if (_restored) return;
    _restored = true;
    try {
      final preferences = await SharedPreferences.getInstance();
      final stored = preferences.getString(preferenceKey);
      final restored = switch (stored) {
        'light' => ThemeMode.light,
        'dark' => ThemeMode.dark,
        'system' => ThemeMode.system,
        _ => null,
      };
      if (restored != null && restored != _mode) {
        _mode = restored;
        notifyListeners();
      }
    } catch (_) {
      // Persisting the preference is a convenience; never block startup on it.
    }
  }

  void setMode(ThemeMode mode) {
    if (mode == _mode) return;
    _mode = mode;
    notifyListeners();
    unawaited(_persist());
  }

  /// Cycles light → dark → system, used by the quick toggle buttons.
  void cycle() {
    final next = switch (_mode) {
      ThemeMode.light => ThemeMode.dark,
      ThemeMode.dark => ThemeMode.system,
      ThemeMode.system => ThemeMode.light,
    };
    setMode(next);
  }

  /// Flips between light and dark. When the app follows the system, [current]
  /// lets the toggle pick the opposite of what is on screen right now.
  void toggle({Brightness? current}) {
    if (_mode == ThemeMode.dark) {
      setMode(ThemeMode.light);
      return;
    }
    if (_mode == ThemeMode.light) {
      setMode(ThemeMode.dark);
      return;
    }
    setMode(current == Brightness.dark ? ThemeMode.light : ThemeMode.dark);
  }

  Future<void> _persist() async {
    try {
      final preferences = await SharedPreferences.getInstance();
      await preferences.setString(
        preferenceKey,
        switch (_mode) {
          ThemeMode.light => 'light',
          ThemeMode.dark => 'dark',
          ThemeMode.system => 'system',
        },
      );
    } catch (_) {
      // Ignore storage failures.
    }
  }
}
