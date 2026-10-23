import 'package:flutter/material.dart';

/// الهوية اللونية "AquaGlass" — الخطة §3.4-1 (مستمدة من قوالب الفاتورة والسند).
class AppColors {
  AppColors._();

  static const Color primary = Color(0xFF02687E);
  static const Color primaryDark = Color(0xFF0B2B40);
  static const Color secondary = Color(0xFF0EA5E9);
  static const Color brandAmber = Color(0xFFF59E0B);

  // ألوان دلالات مالية (مطابقة لـ Filament `->colors([...])` في InvoiceResource).
  static const Color success = Color(0xFF059669); // paid / credit
  static const Color warning = Color(0xFFD97706); // partially_paid / pending
  static const Color danger = Color(0xFFDC2626); // overdue / debit
  static const Color info = Color(0xFF0284C7); // issued
  static const Color neutral = Color(0xFF64748B); // draft

  // الوضع النهاري (افتراضي للميدان: تباين عالٍ تحت الشمس).
  static const Color lightBackground = Color(0xFFF8FAFC);
  static const Color lightSurface = Color(0xFFFFFFFF);
  static const Color lightBorder = Color(0xFFE2E8F0);

  // الوضع الليلي.
  static const Color darkBackground = Color(0xFF0F172A);
  static const Color darkSurface = Color(0xFF1E293B);
  static const Color darkBorder = Color(0xFF334155);

  static const Color tanker = Color(0xFF06B6D4); // فاتورة وايت
  static const Color subscription = brandAmber; // فاتورة اشتراك
  static const Color meter = Color(0xFF3B82F6); // فاتورة عداد

  static const LinearGradient headerGradient = LinearGradient(
    begin: Alignment.topRight,
    end: Alignment.bottomLeft,
    colors: <Color>[Color(0xFF02687E), Color(0xFF0EA5E9)],
  );
}

/// دلالات ألوان حالات الفواتير/السندات (تُطابق `->badge()` في Filament).
class FinanceStatusColors {
  FinanceStatusColors._();

  static Color of(String status) => switch (status) {
        'paid' => AppColors.success,
        'partially_paid' => AppColors.warning,
        'overdue' => AppColors.danger,
        'issued' => AppColors.info,
        'draft' => AppColors.neutral,
        'cancelled' => AppColors.neutral,
        _ => AppColors.neutral,
      };
}
