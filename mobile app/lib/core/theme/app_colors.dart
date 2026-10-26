import 'package:flutter/material.dart';

/// Marine blue identity shared by the app's light and dark workspaces.
/// Blue marks navigation and actions; green, amber and red retain their
/// operational meaning and always appear alongside a readable label.
/// Legacy names remain available to keep existing screens on the same palette.
abstract final class AppColors {
  // ── Brand accent ─────────────────────────────────────────────────────────
  /// The single brand colour. Primary actions, active navigation, key figures.
  static const accent = Color(0xFF1265C8);

  /// Pressed / hover state of [accent].
  static const accentStrong = Color(0xFF084991);

  /// Low-emphasis accent wash for selected rows, active tabs and panels.
  static const accentSoft = Color(0xFFE8F2FF);

  /// Text and icons placed on top of [accent].
  static const onAccent = Color(0xFFFFFFFF);

  // ── Ink (light theme text) ───────────────────────────────────────────────
  static const ink = Color(0xFF142B46);
  static const inkSoft = Color(0xFF3E5570);
  static const muted = Color(0xFF566A80);

  // ── Light surfaces ───────────────────────────────────────────────────────
  static const paper = Color(0xFFF1F5FA);
  static const surface = Color(0xFFFFFFFF);
  static const surfaceAlt = Color(0xFFEAF0F7);
  static const border = Color(0xFFDBE4EF);
  static const borderStrong = Color(0xFFBACADB);

  // ── Ink panel (dark summary cards, splash, login) ────────────────────────
  static const panel = Color(0xFF102C4D);
  static const panelElevated = Color(0xFF17416B);
  static const panelLine = Color(0x24FFFFFF);

  /// Accent used *inside* the ink panel: lighter than [accent] so it keeps
  /// contrast on the dark surface.
  static const accentOnPanel = Color(0xFF7CD9FF);
  static const onPanel = Color(0xFFFFFFFF);
  static const onPanelSoft = Color(0xFFD7E6F5);
  static const onPanelMuted = Color(0xFFB5CBE0);

  // ── Dark surfaces ────────────────────────────────────────────────────────
  static const darkPaper = Color(0xFF0B1423);
  static const darkSurface = Color(0xFF142238);
  static const darkSurfaceAlt = Color(0xFF1B2D47);
  static const darkBorder = Color(0xFF2A405D);
  static const darkBorderStrong = Color(0xFF48617D);
  static const darkInk = Color(0xFFF2F7FF);
  static const darkInkSoft = Color(0xFFCBDBEC);
  static const darkMuted = Color(0xFFADC1D8);
  static const darkAccent = Color(0xFF77CFFF);
  static const darkAccentSoft = Color(0xFF193B5B);
  static const darkPanel = Color(0xFF123658);

  // ── Semantic (text, dots, charts only) ───────────────────────────────────
  static const success = Color(0xFF157347);
  static const warning = Color(0xFF9A5B00);
  static const danger = Color(0xFFB02A37);
  static const info = Color(0xFF24639A);
  static const neutral = Color(0xFF566A80);

  // ── Legacy brand aliases ─────────────────────────────────────────────────
  // Kept so existing screens compile; they now resolve to the single accent.
  static const primary = accent;
  static const primaryDark = panel;
  static const primaryDeep = accentStrong;
  static const primaryBright = accent;
  static const secondary = accent;
  static const mint = success;
  static const brandAmber = warning;
  static const violet = Color(0xFF4C6470);
  static const coral = Color(0xFF8A5A52);

  /// لون واتساب التجاري — يُستخدم على أيقونة المشاركة فقط.
  static const whatsapp = Color(0xFF25D366);

  // ── Legacy surface aliases ───────────────────────────────────────────────
  static const lightBackground = paper;
  static const lightSurface = surface;
  static const lightSurfaceAlt = surfaceAlt;
  static const lightBorder = border;
  static const lightInk = ink;
  static const lightMuted = muted;

  static const darkBackground = darkPaper;

  // ── Legacy gradients ─────────────────────────────────────────────────────
  // Deprecated: the interface is flat. These remain as near-solid fallbacks
  // for screens that have not migrated yet, and are ignored by `AppHeroPanel`.
  static const oceanGradient = LinearGradient(
    begin: AlignmentDirectional.topStart,
    end: AlignmentDirectional.bottomEnd,
    colors: <Color>[panel, panel],
  );

  static const aquaGradient = LinearGradient(
    begin: AlignmentDirectional.centerStart,
    end: AlignmentDirectional.centerEnd,
    colors: <Color>[accent, accent],
  );

  static const mintGradient = LinearGradient(colors: <Color>[success, success]);
  static const amberGradient = LinearGradient(
    colors: <Color>[warning, warning],
  );
  static const roseGradient = LinearGradient(colors: <Color>[danger, danger]);
  static const violetGradient = LinearGradient(colors: <Color>[violet, violet]);
  static const skyGradient = LinearGradient(colors: <Color>[info, info]);

  // ── Legacy field aliases (kept for existing screens) ─────────────────────
  static const cardBackground = surface;
  static const textSecondary = neutral;
}
