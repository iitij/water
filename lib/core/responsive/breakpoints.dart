import 'package:flutter/widgets.dart';

/// نقاط التوقف المعتمدة (الخطة §3.3-1).
enum ScreenSize { small, medium, large, extraLarge }

class Breakpoints {
  Breakpoints._();

  static const double compactMax = 600;
  static const double mediumMax = 840;
  static const double largeMax = 1200;

  /// شاشة ضيقة جداً (< 360dp): تُقلَّل الحشوات ويُستخدم `Wrap`/`FittedBox`.
  static bool isNarrow(BuildContext context) =>
      MediaQuery.sizeOf(context).width < 360;

  static bool isCompact(BuildContext context) =>
      MediaQuery.sizeOf(context).width < compactMax;

  static bool isMedium(BuildContext context) {
    final w = MediaQuery.sizeOf(context).width;
    return w >= compactMax && w < mediumMax;
  }

  static bool isExpanded(BuildContext context) =>
      MediaQuery.sizeOf(context).width >= mediumMax;

  /// وضع Master-Detail: يُفعَّل من 840dp فصاعداً (الخطة §3.3-3).
  static bool useMasterDetail(BuildContext context) =>
      MediaQuery.sizeOf(context).width >= mediumMax;

  static ScreenSize of(BuildContext context) {
    final w = MediaQuery.sizeOf(context).width;
    if (w < compactMax) return ScreenSize.small;
    if (w < mediumMax) return ScreenSize.medium;
    if (w < largeMax) return ScreenSize.large;
    return ScreenSize.extraLarge;
  }
}

/// امتدادات مقاسات السياق الذكية.
extension ResponsiveContextX on BuildContext {
  bool get isCompact => Breakpoints.isCompact(this);
  bool get isMedium => Breakpoints.isMedium(this);
  bool get isExpanded => Breakpoints.isExpanded(this);
  bool get useMasterDetail => Breakpoints.useMasterDetail(this);

  /// الحشوة القياسية: 16 على الهاتف، 24 على التابلت (و12 على الشاشات الضيقة).
  double get gutter => Breakpoints.isNarrow(this)
      ? 12
      : (Breakpoints.isCompact(this) ? 16 : 24);

  double get cardPadding => Breakpoints.isCompact(this) ? 14 : 18;
}
