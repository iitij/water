/// ألوان الوحدة المالية — الامتدادات التي لا توجد بعد في `core/theme/app_colors.dart` عند Dev 1.
///
/// `AppColors` عند Dev 1 يعرّف اللوحة العامة (primary/secondary/success/warning/danger/info/
/// neutral + أسطح الوضعين). ألوان المحركات الثلاثة وترويسة الشاشات تخص Dev 3، وتُضاف هناك
/// عند الدمج (انظر `docs/handoff/DEV3_FINANCE_MODULE.md` §9).
library;
import 'package:flutter/material.dart';

import '../theme/app_colors.dart';

class FinanceColors {
  FinanceColors._();

  /// فاتورة العداد.
  static const Color meter = Color(0xFF0EA5E9);

  /// اشتراك/رسوم دورية.
  static const Color subscription = Color(0xFF8B5CF6);

  /// فاتورة وايت (نقل بالصهريج).
  static const Color tanker = Color(0xFFF59E0B);

  /// ترويسة `FinanceScaffold` — تُبنى من اللون الأساسي محلياً كي تتبع ثيم Dev 1.
  static LinearGradient get headerGradient => const LinearGradient(
        begin: AlignmentDirectional.centerStart,
        end: AlignmentDirectional.centerEnd,
        colors: <Color>[AppColors.primary, AppColors.primaryDark],
      );

  /// لون المحرك حسب نوع الفاتورة (`meter|subscription|tanker`).
  static Color forEngine(String engine) => switch (engine) {
        'subscription' => subscription,
        'tanker' => tanker,
        _ => meter,
      };
}
