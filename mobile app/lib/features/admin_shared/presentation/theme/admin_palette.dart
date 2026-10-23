import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';

/// Palette used by the admin/operations widgets.
///
/// It now mirrors the product design tokens so admin surfaces do not introduce
/// a second visual language: one teal accent, one ink family and four muted
/// semantic colours that only appear on status text, icons and charts.
abstract final class AdminPalette {
  static const primary = AppColors.accent;
  static const primaryDark = AppColors.panel;
  static const accent = AppColors.accent;
  static const amber = AppColors.warning;
  static const success = AppColors.success;
  static const warning = AppColors.warning;
  static const danger = AppColors.danger;
  static const info = AppColors.info;
  static const neutral = AppColors.neutral;
  static const subscribersExport = Color(0xFF2F5D7C);
  static const invoicesExport = Color(0xFF157347);
  static const readingsExport = Color(0xFF3C6E71);
  static const terminal = AppColors.panel;
  static const whatsappLog = AppColors.success;
  static const queueLog = Color(0xFF2F5D7C);
}
