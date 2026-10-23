/// أدوات التجاوب الخاصة بالوحدة المالية، مبنية على `core/responsive/` عند Dev 1
/// (`AppBreakpoints` + `AdaptiveScaffold.isCompact/isMedium/isExpanded`).
import 'package:flutter/material.dart';

import '../responsive/adaptive_scaffold.dart';
import '../responsive/breakpoints.dart';

/// مقاسات تنسيق القوائم/البطاقات داخل شاشات Dev 3.
class FinanceBreakpoints {
  FinanceBreakpoints._();

  /// أقل عرض يبقى فيه صف البطاقة المفرد مقروءاً (رقم المشترك + المبلغ + الزر).
  static const double compactWidth = 360;

  /// لوحة/طية: يبدأ عندها Use Grid و`NavigationRail`.
  static double get tablet => AppBreakpoints.medium;

  /// شاشة مكتبية: شريط جانبي موسّع مع تسميات.
  static double get desktop => AppBreakpoints.wideRail;
}

extension FinanceLayoutX on BuildContext {
  /// عرض الشاشة بالبكسل.
  double get screenWidth => MediaQuery.sizeOf(this).width;

  /// قائمة جانبية/شبكة بدل قائمة عمودية (يُستعمل في SCR-14/17 وتفاصيل الفاتورة).
  bool get useMasterDetail => !AdaptiveScaffold.isCompact(this);

  bool get compactWidth => screenWidth < FinanceBreakpoints.compactWidth;

  bool get tablet => AdaptiveScaffold.isExpanded(this);
}
