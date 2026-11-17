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
  static const Color meter = AppColors.info;



  /// إصدار فواتير استهلاك المياه هو النوع المالي الوحيد المتاح.
  static Color forEngine(String engine) => meter;
}
