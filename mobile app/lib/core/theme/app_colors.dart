import 'package:flutter/material.dart';

/// Brand palette for the water project platform.
///
/// The palette is built around a deep "ocean ink" navy, a saturated teal and a
/// bright aqua accent, with a small set of semantic colours reserved for state.
/// Older screens reference the legacy aliases at the bottom of this file, so
/// keeping them stable lets the whole app share one visual language.
abstract final class AppColors {
  // ── Brand ────────────────────────────────────────────────────────────────
  static const primary = Color(0xFF0E7C86);
  static const primaryDark = Color(0xFF062733);
  static const primaryDeep = Color(0xFF0A4B5A);
  static const primaryBright = Color(0xFF14B8A6);
  static const secondary = Color(0xFF22D3EE);
  static const accent = Color(0xFF14B8A6);
  static const mint = Color(0xFF10B981);
  static const brandAmber = Color(0xFFF59E0B);
  static const violet = Color(0xFF7C6CF6);
  static const coral = Color(0xFFFB7185);

  // ── Semantic ─────────────────────────────────────────────────────────────
  static const success = Color(0xFF0E9F6E);
  static const warning = Color(0xFFD97706);
  static const danger = Color(0xFFE11D48);
  static const info = Color(0xFF0B84D6);
  static const neutral = Color(0xFF64748B);

  // ── Light surfaces ───────────────────────────────────────────────────────
  static const lightBackground = Color(0xFFF2F7FA);
  static const lightSurface = Color(0xFFFFFFFF);
  static const lightSurfaceAlt = Color(0xFFEAF2F7);
  static const lightBorder = Color(0xFFDFE9F0);
  static const lightInk = Color(0xFF0A1F2B);
  static const lightMuted = Color(0xFF5B7385);

  // ── Dark surfaces ────────────────────────────────────────────────────────
  static const darkBackground = Color(0xFF05131B);
  static const darkSurface = Color(0xFF0B2029);
  static const darkSurfaceAlt = Color(0xFF122E3A);
  static const darkBorder = Color(0xFF1C3B48);
  static const darkInk = Color(0xFFF2F8FB);
  static const darkMuted = Color(0xFF8FA9B6);

  // ── Gradients ────────────────────────────────────────────────────────────
  static const oceanGradient = LinearGradient(
    begin: AlignmentDirectional.topStart,
    end: AlignmentDirectional.bottomEnd,
    colors: <Color>[Color(0xFF062733), Color(0xFF0A4B5A), Color(0xFF0E7C86)],
    stops: <double>[0, .52, 1],
  );

  static const aquaGradient = LinearGradient(
    begin: AlignmentDirectional.centerStart,
    end: AlignmentDirectional.centerEnd,
    colors: <Color>[Color(0xFF0E7C86), Color(0xFF14B8A6)],
  );

  static const mintGradient = LinearGradient(
    begin: AlignmentDirectional.centerStart,
    end: AlignmentDirectional.centerEnd,
    colors: <Color>[Color(0xFF0E9F6E), Color(0xFF34D399)],
  );

  static const amberGradient = LinearGradient(
    begin: AlignmentDirectional.centerStart,
    end: AlignmentDirectional.centerEnd,
    colors: <Color>[Color(0xFFD97706), Color(0xFFFBBF24)],
  );

  static const roseGradient = LinearGradient(
    begin: AlignmentDirectional.centerStart,
    end: AlignmentDirectional.centerEnd,
    colors: <Color>[Color(0xFFE11D48), Color(0xFFFB7185)],
  );

  static const violetGradient = LinearGradient(
    begin: AlignmentDirectional.centerStart,
    end: AlignmentDirectional.centerEnd,
    colors: <Color>[Color(0xFF6D5CE7), Color(0xFF9F8BFF)],
  );

  static const skyGradient = LinearGradient(
    begin: AlignmentDirectional.centerStart,
    end: AlignmentDirectional.centerEnd,
    colors: <Color>[Color(0xFF0B84D6), Color(0xFF38BDF8)],
  );

  // ── Legacy aliases (kept for existing screens) ───────────────────────────
  static const cardBackground = lightSurface;
  static const border = lightBorder;
  static const textSecondary = neutral;
}
