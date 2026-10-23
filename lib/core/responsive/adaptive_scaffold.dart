import 'package:flutter/material.dart';

import 'breakpoints.dart';

/// وجه وجهة تنقل للهيكل المتكيف.
class AdaptiveDestination {
  const AdaptiveDestination({
    required this.label,
    required this.icon,
    required this.selectedIcon,
  });

  final String label;
  final IconData icon;
  final IconData selectedIcon;
}

/// الهيكل المتكيف (الهاتف: BottomNavigationBar — التابلت: NavigationRail + Master-Detail).
///
/// ⚠️ نسخة تعاقدية Dev 3 من الكود المرجعي في الخطة §8.3 — المالك الفعلي Dev 1.
class AdaptiveScaffold extends StatelessWidget {
  const AdaptiveScaffold({
    super.key,
    required this.currentIndex,
    required this.onDestinationSelected,
    required this.destinations,
    required this.body,
    this.secondaryBody,
    this.floatingActionButton,
    this.appBar,
  });

  final int currentIndex;
  final ValueChanged<int> onDestinationSelected;
  final List<AdaptiveDestination> destinations;
  final Widget body;

  /// يظهر في وضع Master-Detail على الشاشات الكبيرة فقط.
  final Widget? secondaryBody;
  final Widget? floatingActionButton;
  final PreferredSizeWidget? appBar;

  @override
  Widget build(BuildContext context) {
    final width = MediaQuery.sizeOf(context).width;

    if (width < Breakpoints.compactMax) {
      return Scaffold(
        appBar: appBar,
        body: body,
        floatingActionButton: floatingActionButton,
        bottomNavigationBar: NavigationBar(
          selectedIndex: currentIndex,
          onDestinationSelected: onDestinationSelected,
          destinations: destinations
              .map(
                (d) => NavigationDestination(
                  icon: Icon(d.icon),
                  selectedIcon: Icon(d.selectedIcon),
                  label: d.label,
                ),
              )
              .toList(),
        ),
      );
    }

    final isExtendedRail = width >= 1024;

    return Scaffold(
      appBar: appBar,
      floatingActionButton: floatingActionButton,
      body: Row(
        children: <Widget>[
          NavigationRail(
            extended: isExtendedRail,
            selectedIndex: currentIndex,
            onDestinationSelected: onDestinationSelected,
            labelType: isExtendedRail
                ? NavigationRailLabelType.none
                : NavigationRailLabelType.all,
            destinations: destinations
                .map(
                  (d) => NavigationRailDestination(
                    icon: Icon(d.icon),
                    selectedIcon: Icon(d.selectedIcon),
                    label: Text(d.label),
                  ),
                )
                .toList(),
          ),
          const VerticalDivider(thickness: 1, width: 1),
          Expanded(flex: 4, child: body),
          if (secondaryBody != null && width >= Breakpoints.mediumMax) ...<Widget>[
            const VerticalDivider(thickness: 1, width: 1),
            Expanded(flex: 6, child: secondaryBody!),
          ],
        ],
      ),
    );
  }
}

/// بناء متجاوب بسيط: قائمة على الهاتف، جدول/شبكة على التابلت (الخطة §3.3-4).
class ResponsiveBuilder extends StatelessWidget {
  const ResponsiveBuilder({
    super.key,
    required this.compact,
    this.medium,
    this.expanded,
  });

  final WidgetBuilder compact;
  final WidgetBuilder? medium;
  final WidgetBuilder? expanded;

  @override
  Widget build(BuildContext context) {
    if (context.isExpanded) return (expanded ?? medium ?? compact)(context);
    if (context.isMedium) return (medium ?? compact)(context);
    return compact(context);
  }
}
