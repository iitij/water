import 'package:flutter/widgets.dart';
import 'package:go_router/go_router.dart';

abstract final class DashboardRoutes {
  static const dashboardPath = '/dashboard';
  static const dashboardName = 'dashboard';
  static const reportsPath = '/reports';
  static const reportsName = 'reports';

  /// Dev 1 imports this fragment into the core router and supplies fully
  /// configured screens, avoiding any cross-feature dependency here.
  static List<RouteBase> routes({
    required Widget Function() dashboardBuilder,
    required Widget Function() reportsBuilder,
  }) {
    return [
      GoRoute(
        path: dashboardPath,
        name: dashboardName,
        builder: (context, state) => dashboardBuilder(),
      ),
      GoRoute(
        path: reportsPath,
        name: reportsName,
        builder: (context, state) => reportsBuilder(),
      ),
    ];
  }
}
