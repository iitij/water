import 'package:flutter/material.dart';

/// Brand palette for the water project platform.
///
/// The palette is intentionally small: **one** brand accent (deep teal), one
/// ink family for text, three neutral surfaces, and four semantic colours that
/// only appear on status text, dots and charts.
///
/// Rules that keep the product looking like one calm system:
/// * no decorative gradients — surfaces are flat and separated by hairlines;
/// * no colour per module — modules are told apart by icon and typography;
/// * colour never carries meaning on its own; it always sits next to a label.
///
/// Older screens still reference the legacy aliases at the bottom of the file,
/// so the whole app shares the new language without touching every screen.
abstract final class AppColors {
  // ── Brand accent ─────────────────────────────────────────────────────────
  /// The single brand colour. Primary actions, active navigation, key figures.
  static const accent = Color(0xFF0C6B60);

  /// Pressed / hover state of [accent].
  static const accentStrong = Color(0xFF095149);

  /// Low-emphasis accent wash for selected rows, active tabs and panels.
  static const accentSoft = Color(0xFFE4EFEC);

  /// Text and icons placed on top of [accent].
  static const onAccent = Color(0xFFFFFFFF);

  // ── Ink (light theme text) ───────────────────────────────────────────────
  static const ink = Color(0xFF0F1A18);
  static const inkSoft = Color(0xFF46534F);
  static const muted = Color(0xFF78857F);

  // ── Light surfaces ───────────────────────────────────────────────────────
  static const paper = Color(0xFFF4F6F5);
  static const surface = Color(0xFFFFFFFF);
  static const surfaceAlt = Color(0xFFEDF1EF);
  static const border = Color(0xFFE4E9E7);
  static const borderStrong = Color(0xFFD3DBD8);

  // ── Ink panel (dark summary cards, splash, login) ────────────────────────
  static const panel = Color(0xFF0F1A18);
  static const panelElevated = Color(0xFF16221F);
  static const panelLine = Color(0x24FFFFFF);

  /// Accent used *inside* the ink panel: lighter than [accent] so it keeps
  /// contrast on the dark surface.
  static const accentOnPanel = Color(0xFF5FC9B9);
  static const onPanel = Color(0xFFFFFFFF);
  static const onPanelSoft = Color(0xB3FFFFFF);
  static const onPanelMuted = Color(0x80FFFFFF);

  // ── Dark surfaces ────────────────────────────────────────────────────────
  static const darkPaper = Color(0xFF0A0F0E);
  static const darkSurface = Color(0xFF121A18);
  static const darkSurfaceAlt = Color(0xFF18221F);
  static const darkBorder = Color(0xFF24302D);
  static const darkBorderStrong = Color(0xFF32413D);
  static const darkInk = Color(0xFFE9EFED);
  static const darkInkSoft = Color(0xFFAFBCB7);
  static const darkMuted = Color(0xFF7E8C88);
  static const darkAccent = Color(0xFF5FC9B9);
  static const darkAccentSoft = Color(0xFF16302B);
  static const darkPanel = Color(0xFF16211E);

  // ── Semantic (text, dots, charts only) ───────────────────────────────────
  static const success = Color(0xFF157347);
  static const warning = Color(0xFF9A5B00);
  static const danger = Color(0xFFB02A37);
  static const info = Color(0xFF2F5D7C);
  static const neutral = Color(0xFF6E7C78);

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
  static const amberGradient = LinearGradient(colors: <Color>[warning, warning]);
  static const roseGradient = LinearGradient(colors: <Color>[danger, danger]);
  static const violetGradient = LinearGradient(colors: <Color>[violet, violet]);
  static const skyGradient = LinearGradient(colors: <Color>[info, info]);

  // ── Legacy field aliases (kept for existing screens) ─────────────────────
  static const cardBackground = surface;
  static const textSecondary = neutral;
}
