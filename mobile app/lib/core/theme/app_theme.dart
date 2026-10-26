import 'dart:async';

import 'package:flutter/cupertino.dart' show CupertinoPageTransitionsBuilder;
import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'app_colors.dart';
import 'app_typography.dart';

/// Central theme for the platform.
///
/// Both light and dark variants share the same geometry (radii, padding,
/// hairlines). Nothing in the theme uses gradients, coloured shadows or
/// elevation on content surfaces; depth comes from one border token and one
/// background token.
abstract final class AppTheme {
  const AppTheme._();

  static ThemeData get light => _build(Brightness.light);
  static ThemeData get dark => _build(Brightness.dark);

  static bool isDark(BuildContext context) =>
      Theme.of(context).brightness == Brightness.dark;

  static ColorScheme _scheme(Brightness brightness) {
    final dark = brightness == Brightness.dark;
    return ColorScheme(
      brightness: brightness,
      primary: dark ? AppColors.darkAccent : AppColors.accent,
      onPrimary: dark ? const Color(0xFF092943) : AppColors.onAccent,
      primaryContainer: dark ? AppColors.darkAccentSoft : AppColors.accentSoft,
      onPrimaryContainer: dark ? AppColors.darkAccent : AppColors.accentStrong,
      secondary: dark ? AppColors.darkAccent : AppColors.accent,
      onSecondary: dark ? const Color(0xFF092943) : AppColors.onAccent,
      secondaryContainer: dark
          ? AppColors.darkAccentSoft
          : AppColors.accentSoft,
      onSecondaryContainer: dark
          ? AppColors.darkAccent
          : AppColors.accentStrong,
      tertiary: dark ? const Color(0xFFE0B052) : AppColors.warning,
      onTertiary: dark ? const Color(0xFF2B1C00) : Colors.white,
      tertiaryContainer: dark
          ? const Color(0xFF3A2A08)
          : const Color(0xFFFBF0DC),
      onTertiaryContainer: dark
          ? const Color(0xFFF4D9A3)
          : const Color(0xFF6B3F00),
      error: dark ? const Color(0xFFF08A95) : AppColors.danger,
      onError: dark ? const Color(0xFF3B0A10) : Colors.white,
      errorContainer: dark ? const Color(0xFF43151B) : const Color(0xFFFBEAEC),
      onErrorContainer: dark
          ? const Color(0xFFF8C6CC)
          : const Color(0xFF7D1A25),
      surface: dark ? AppColors.darkSurface : AppColors.surface,
      onSurface: dark ? AppColors.darkInk : AppColors.ink,
      surfaceContainerHighest: dark
          ? AppColors.darkSurfaceAlt
          : AppColors.surfaceAlt,
      onSurfaceVariant: dark ? AppColors.darkMuted : AppColors.muted,
      outline: dark ? AppColors.darkBorderStrong : AppColors.borderStrong,
      outlineVariant: dark ? AppColors.darkBorder : AppColors.border,
      inverseSurface: dark ? AppColors.surface : AppColors.panel,
      onInverseSurface: dark ? AppColors.ink : AppColors.onPanel,
      shadow: Colors.black,
      scrim: Colors.black,
      surfaceTint: Colors.transparent,
    );
  }

  static ThemeData _build(Brightness brightness) {
    final dark = brightness == Brightness.dark;
    final scheme = _scheme(brightness);
    final background = dark ? AppColors.darkPaper : AppColors.paper;
    final muted = scheme.onSurfaceVariant;
    final fieldFill = dark ? AppColors.darkSurfaceAlt : AppColors.surface;

    OutlineInputBorder border(Color color, {double width = 1}) =>
        OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
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
        selectionColor: scheme.primary.withValues(alpha: .22),
        selectionHandleColor: scheme.primary,
      ),
      inputDecorationTheme: InputDecorationThemeData(
        filled: true,
        fillColor: fieldFill,
        isDense: false,
        contentPadding: const EdgeInsetsDirectional.fromSTEB(14, 15, 14, 15),
        hintStyle: TextStyle(
          fontFamily: AppTypography.fontFamily,
          color: muted,
          fontSize: 13.5,
        ),
        labelStyle: TextStyle(
          fontFamily: AppTypography.fontFamily,
          color: muted,
          fontSize: 13.5,
        ),
        floatingLabelStyle: TextStyle(
          fontFamily: AppTypography.fontFamily,
          color: scheme.primary,
          fontWeight: FontWeight.w600,
          fontSize: 12.5,
        ),
        prefixIconColor: muted,
        suffixIconColor: muted,
        border: border(scheme.outlineVariant),
        enabledBorder: border(scheme.outlineVariant),
        disabledBorder: border(scheme.outlineVariant.withValues(alpha: .5)),
        focusedBorder: border(scheme.primary, width: 1.4),
        errorBorder: border(scheme.error),
        focusedErrorBorder: border(scheme.error, width: 1.4),
        errorStyle: TextStyle(
          fontFamily: AppTypography.fontFamily,
          color: scheme.error,
          fontSize: 11.5,
        ),
      ),
      appBarTheme: AppBarTheme(
        backgroundColor: background,
        surfaceTintColor: Colors.transparent,
        foregroundColor: scheme.onSurface,
        elevation: 0,
        scrolledUnderElevation: 0,
        centerTitle: false,
        titleTextStyle: AppTypography.textTheme(brightness).titleLarge
            ?.copyWith(fontWeight: FontWeight.w600, color: scheme.onSurface),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size(0, 48),
          padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 12),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
          ),
          textStyle: const TextStyle(
            fontFamily: AppTypography.fontFamily,
            fontSize: 13.5,
            fontWeight: FontWeight.w600,
          ),
          elevation: 0,
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          minimumSize: const Size(0, 48),
          padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 12),
          foregroundColor: scheme.onSurface,
          side: BorderSide(color: scheme.outlineVariant),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
          ),
          textStyle: const TextStyle(
            fontFamily: AppTypography.fontFamily,
            fontSize: 13.5,
            fontWeight: FontWeight.w600,
          ),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          foregroundColor: scheme.primary,
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(10),
          ),
          textStyle: const TextStyle(
            fontFamily: AppTypography.fontFamily,
            fontSize: 12.5,
            fontWeight: FontWeight.w600,
          ),
        ),
      ),
      iconButtonTheme: IconButtonThemeData(
        style: ButtonStyle(
          foregroundColor: WidgetStatePropertyAll<Color>(scheme.onSurface),
          overlayColor: WidgetStatePropertyAll<Color>(
            scheme.primary.withValues(alpha: .07),
          ),
        ),
      ),
      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        backgroundColor: dark ? AppColors.darkSurfaceAlt : AppColors.panel,
        contentTextStyle: const TextStyle(
          fontFamily: AppTypography.fontFamily,
          color: Colors.white,
          fontSize: 13,
        ),
        actionTextColor: dark ? AppColors.darkAccent : AppColors.accentSoft,
        insetPadding: const EdgeInsets.all(14),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        elevation: 4,
      ),
      dividerTheme: DividerThemeData(
        color: scheme.outlineVariant,
        thickness: 1,
        space: 1,
      ),
      progressIndicatorTheme: ProgressIndicatorThemeData(
        color: scheme.primary,
        linearTrackColor: scheme.primary.withValues(alpha: .1),
        circularTrackColor: scheme.primary.withValues(alpha: .1),
      ),
      checkboxTheme: CheckboxThemeData(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(5)),
        side: BorderSide(color: scheme.outline, width: 1.3),
        fillColor: WidgetStateProperty.resolveWith((states) {
          if (states.contains(WidgetState.selected)) return scheme.primary;
          return Colors.transparent;
        }),
      ),
      listTileTheme: ListTileThemeData(
        iconColor: scheme.onSurfaceVariant,
        textColor: scheme.onSurface,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 2),
      ),
      bottomSheetTheme: BottomSheetThemeData(
        backgroundColor: scheme.surface,
        surfaceTintColor: Colors.transparent,
        modalBackgroundColor: scheme.surface,
        modalBarrierColor: Colors.black.withValues(alpha: .4),
        shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
        ),
        showDragHandle: true,
        dragHandleColor: scheme.outlineVariant,
      ),
      dialogTheme: DialogThemeData(
        backgroundColor: scheme.surface,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(18),
          side: BorderSide(color: scheme.outlineVariant),
        ),
        titleTextStyle: AppTypography.textTheme(
          brightness,
        ).titleLarge?.copyWith(fontWeight: FontWeight.w600),
      ),
      tooltipTheme: TooltipThemeData(
        decoration: BoxDecoration(
          color: dark ? AppColors.darkSurfaceAlt : AppColors.panel,
          borderRadius: BorderRadius.circular(8),
        ),
        textStyle: const TextStyle(
          fontFamily: AppTypography.fontFamily,
          color: Colors.white,
          fontSize: 11.5,
        ),
        waitDuration: const Duration(milliseconds: 400),
      ),
      switchTheme: SwitchThemeData(
        thumbColor: WidgetStateProperty.resolveWith((states) {
          if (states.contains(WidgetState.selected)) return Colors.white;
          return dark ? AppColors.darkMuted : AppColors.borderStrong;
        }),
        trackColor: WidgetStateProperty.resolveWith((states) {
          if (states.contains(WidgetState.selected)) return scheme.primary;
          return dark ? AppColors.darkSurfaceAlt : AppColors.surfaceAlt;
        }),
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: scheme.surface,
        surfaceTintColor: Colors.transparent,
        indicatorColor: scheme.primary.withValues(alpha: dark ? .22 : .1),
        elevation: 0,
        labelTextStyle: WidgetStateProperty.resolveWith(
          (states) => TextStyle(
            fontFamily: AppTypography.fontFamily,
            fontSize: 11,
            fontWeight: states.contains(WidgetState.selected)
                ? FontWeight.w600
                : FontWeight.w500,
            color: states.contains(WidgetState.selected)
                ? scheme.primary
                : muted,
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
      await preferences.setString(preferenceKey, switch (_mode) {
        ThemeMode.light => 'light',
        ThemeMode.dark => 'dark',
        ThemeMode.system => 'system',
      });
    } catch (_) {
      // Ignore storage failures.
    }
  }
}
