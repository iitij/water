import 'package:flutter/widgets.dart';

/// نقاط التوقف المعتمدة (الخطة 3.3) + Master-Detail عند >= 840dp.
class Breakpoints {
  static const double medium = 600;
  static const double expanded = 840;
  static bool isExpanded(BuildContext c) =>
      MediaQuery.sizeOf(c).width >= expanded;
  static bool isMedium(BuildContext c) => MediaQuery.sizeOf(c).width >= medium;
}
