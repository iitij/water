import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../finance/finance_responsive.dart';
import '../router/finance_route_names.dart';

/// نسخة تعاقدية من `core/widgets/responsive_scaffold.dart` — المالك الفعلي Dev 1.
///
/// تبني هيكل `AdaptiveNavigationScaffold` المطلوب في الخطة §3.1: شريط سفلي على
/// الهاتف، NavigationRail على التابلت، وRail ممتد على الشاشات الكبيرة — مع الوجهات
/// المالية الخمس التي تملكها هذه الشريحة.
class FinanceScaffold extends StatefulWidget {
  const FinanceScaffold({
    super.key,
    required this.child,
    this.currentIndex = 0,
  });

  static const List<FinanceDestination> destinations = <FinanceDestination>[
    FinanceDestination(
      label: 'المحصل',
      icon: Icons.near_me_outlined,
      selectedIcon: Icons.near_me,
      location: FinanceRouteNames.quickCollector,
    ),
    FinanceDestination(
      label: 'الفواتير',
      icon: Icons.receipt_long_outlined,
      selectedIcon: Icons.receipt_long,
      location: FinanceRouteNames.invoices,
    ),
    FinanceDestination(
      label: 'السندات',
      icon: Icons.payments_outlined,
      selectedIcon: Icons.payments,
      location: FinanceRouteNames.payments,
    ),
    FinanceDestination(
      label: 'التسعير',
      icon: Icons.sell_outlined,
      selectedIcon: Icons.sell,
      location: FinanceRouteNames.tariffs,
    ),
  ];

  final Widget child;
  final int currentIndex;

  @override
  State<FinanceScaffold> createState() => _FinanceScaffoldState();
}

class _FinanceScaffoldState extends State<FinanceScaffold> {
  void _go(int index) {
    final destination = FinanceScaffold.destinations[index];
    if (index == widget.currentIndex) return;
    context.go(destination.location);
  }

  @override
  Widget build(BuildContext context) {
    final width = MediaQuery.sizeOf(context).width;
    final isTablet = width >= FinanceBreakpoints.tablet;

    if (!isTablet) {
      return Scaffold(
        body: widget.child,
        bottomNavigationBar: NavigationBar(
          selectedIndex: widget.currentIndex,
          onDestinationSelected: _go,
          labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
          destinations: <Widget>[
            for (final destination in FinanceScaffold.destinations)
              NavigationDestination(
                icon: Icon(destination.icon),
                selectedIcon: Icon(destination.selectedIcon),
                label: destination.label,
              ),
          ],
        ),
      );
    }

    return Scaffold(
      body: Row(
        children: <Widget>[
          NavigationRail(
            selectedIndex: widget.currentIndex,
            onDestinationSelected: _go,
            extended: width >= FinanceBreakpoints.desktop,
            labelType: width >= FinanceBreakpoints.desktop
                ? NavigationRailLabelType.none
                : NavigationRailLabelType.all,
            destinations: <NavigationRailDestination>[
              for (final destination in FinanceScaffold.destinations)
                NavigationRailDestination(
                  icon: Icon(destination.icon),
                  selectedIcon: Icon(destination.selectedIcon),
                  label: Text(destination.label),
                ),
            ],
          ),
          const VerticalDivider(thickness: 1, width: 1),
          Expanded(child: widget.child),
        ],
      ),
    );
  }
}

class FinanceDestination {
  const FinanceDestination({
    required this.label,
    required this.icon,
    required this.selectedIcon,
    required this.location,
  });

  final String label;
  final IconData icon;
  final IconData selectedIcon;
  final String location;
}

/// غلاف مختصر للشاشات الفرعية التي لا تحتاج شريط تنقل (تفاصيل، نماذج).
class FinanceDetailScaffold extends StatelessWidget {
  const FinanceDetailScaffold({
    super.key,
    required this.title,
    required this.body,
    this.actions,
    this.floatingActionButton,
  });

  final String title;
  final Widget body;
  final List<Widget>? actions;
  final Widget? floatingActionButton;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(title), actions: actions),
      floatingActionButton: floatingActionButton,
      body: SafeArea(child: body),
    );
  }
}

/// يربط وجهات [FinanceScaffold] بالمسارات: الوجهة الحالية تُستنتج من
/// `GoRouterState.matchedLocation`، فتبقى القائمة单一 مصدر للحقيقة.
class FinanceShell extends StatelessWidget {
  const FinanceShell({super.key, required this.state, required this.child});

  final GoRouterState state;
  final Widget child;

  static const Map<String, int> _indexOf = <String, int>{
    FinanceRouteNames.quickCollector: 0,
    FinanceRouteNames.invoices: 1,
    FinanceRouteNames.payments: 2,
    FinanceRouteNames.tariffs: 3,
  };

  @override
  Widget build(BuildContext context) {
    final location = state.matchedLocation;
    final matches = _indexOf.entries
        .where((entry) => location.startsWith(entry.key))
        .toList(growable: false);

    return FinanceScaffold(
      currentIndex: matches.isEmpty ? 0 : matches.first.value,
      child: child,
    );
  }
}
