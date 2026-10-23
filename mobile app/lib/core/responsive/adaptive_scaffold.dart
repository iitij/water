import 'package:flutter/material.dart';

import 'breakpoints.dart';

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

/// Shared navigation frame: bottom bar on phones, right-side rail on larger screens.
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
    this.bottomBar,
  });

  final int currentIndex;
  final ValueChanged<int> onDestinationSelected;
  final List<AdaptiveDestination> destinations;
  final Widget body;
  final Widget? secondaryBody;
  final Widget? floatingActionButton;
  final PreferredSizeWidget? appBar;
  final Widget? bottomBar;

  static bool isCompact(BuildContext context) =>
      MediaQuery.sizeOf(context).width < AppBreakpoints.compact;

  static bool isMedium(BuildContext context) {
    final width = MediaQuery.sizeOf(context).width;
    return width >= AppBreakpoints.compact && width < AppBreakpoints.medium;
  }

  static bool isExpanded(BuildContext context) =>
      MediaQuery.sizeOf(context).width >= AppBreakpoints.medium;

  @override
  Widget build(BuildContext context) {
    final width = MediaQuery.sizeOf(context).width;
    if (width < AppBreakpoints.compact) {
      return Scaffold(
        appBar: appBar,
        body: body,
        floatingActionButton: floatingActionButton,
        bottomNavigationBar: bottomBar ?? NavigationBar(
          selectedIndex: currentIndex,
          onDestinationSelected: onDestinationSelected,
          destinations: destinations
              .map((destination) => NavigationDestination(
                    icon: Icon(destination.icon),
                    selectedIcon: Icon(destination.selectedIcon),
                    label: destination.label,
                  ))
              .toList(growable: false),
        ),
      );
    }

    final extended = width >= AppBreakpoints.wideRail;
    return Scaffold(
      appBar: appBar,
      floatingActionButton: floatingActionButton,
      body: Directionality(
        textDirection: TextDirection.rtl,
        child: Row(
          children: <Widget>[
            NavigationRail(
              extended: extended,
              selectedIndex: currentIndex,
              onDestinationSelected: onDestinationSelected,
              labelType: extended ? NavigationRailLabelType.none : NavigationRailLabelType.all,
              minExtendedWidth: 190,
              destinations: destinations
                  .map((destination) => NavigationRailDestination(
                        icon: Icon(destination.icon),
                        selectedIcon: Icon(destination.selectedIcon),
                        label: Text(destination.label),
                      ))
                  .toList(growable: false),
            ),
            const VerticalDivider(width: 1, thickness: 1),
            Expanded(
              flex: secondaryBody != null && width >= AppBreakpoints.medium ? 4 : 1,
              child: body,
            ),
            if (secondaryBody != null && width >= AppBreakpoints.medium) ...<Widget>[
              const VerticalDivider(width: 1, thickness: 1),
              Expanded(flex: 6, child: secondaryBody!),
            ],
          ],
        ),
      ),
    );
  }
}

class MasterDetailLayout extends StatelessWidget {
  const MasterDetailLayout({
    super.key,
    required this.master,
    required this.detail,
    this.masterFlex = 4,
    this.detailFlex = 6,
  });

  final Widget master;
  final Widget detail;
  final int masterFlex;
  final int detailFlex;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(builder: (context, constraints) {
      if (constraints.maxWidth < AppBreakpoints.medium) return master;
      return Row(
        children: <Widget>[
          Expanded(flex: masterFlex, child: master),
          const VerticalDivider(width: 1),
          Expanded(flex: detailFlex, child: detail),
        ],
      );
    });
  }
}
