import 'package:flutter/material.dart';

/// Green identity shared by the app's light and dark workspaces.
/// Green marks navigation and actions; amber and red retain their
/// operational meaning and always appear alongside a readable label.
/// Legacy names remain available to keep existing screens on the same palette.
abstract final class AppColors {
  // ── Brand accent ─────────────────────────────────────────────────────────
  /// The single brand colour. Primary actions, active navigation, key figures.
  static const accent = Color(0xFF176B4D);

  /// Pressed / hover state of [accent].
  static const accentStrong = Color(0xFF0E5038);

  /// Low-emphasis accent wash for selected rows, active tabs and panels.
  static const accentSoft = Color(0xFFDDF3E7);

  /// Text and icons placed on top of [accent].
  static const onAccent = Color(0xFFFFFFFF);

  // ── Ink (light theme text) ───────────────────────────────────────────────
  static const ink = Color(0xFF17352B);
  static const inkSoft = Color(0xFF38594B);
  static const muted = Color(0xFF4F685C);

  // ── Light surfaces ───────────────────────────────────────────────────────
  static const paper = Color(0xFFF2F7F3);
  static const surface = Color(0xFFFFFFFF);
  static const surfaceAlt = Color(0xFFE8F0E9);
  static const border = Color(0xFFD5E3D9);
  static const borderStrong = Color(0xFF9EB9A9);

  // ── Ink panel (dark summary cards, splash, login) ────────────────────────
  static const panel = Color(0xFF16372B);
  static const panelElevated = Color(0xFF24513D);
  static const panelLine = Color(0x24FFFFFF);

  /// Accent used *inside* the ink panel: lighter than [accent] so it keeps
  /// contrast on the dark surface.
  static const accentOnPanel = Color(0xFF8DE4B6);
  static const onPanel = Color(0xFFFFFFFF);
  static const onPanelSoft = Color(0xFFD5EBDD);
  static const onPanelMuted = Color(0xFFB7D3C2);

  // ── Dark surfaces ────────────────────────────────────────────────────────
  static const darkPaper = Color(0xFF0B1513);
  static const darkSurface = Color(0xFF162420);
  static const darkSurfaceAlt = Color(0xFF263A32);
  static const darkBorder = Color(0xFF365347);
  static const darkBorderStrong = Color(0xFF557467);
  static const darkInk = Color(0xFFF2F7F3);
  static const darkInkSoft = Color(0xFFD8E9DD);
  static const darkMuted = Color(0xFFB6CFC0);
  static const darkAccent = Color(0xFF58CB99);
  static const darkAccentSoft = Color(0xFF1B4432);
  static const darkPanel = darkSurface;

  // ── Semantic (text, dots, charts only) ───────────────────────────────────
  static const success = Color(0xFF157347);
  static const warning = Color(0xFF9A5B00);
  static const danger = Color(0xFFB02A37);
  static const info = Color(0xFF24639A);
  static const neutral = muted;

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
