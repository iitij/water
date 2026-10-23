import 'package:flutter/widgets.dart';
import 'package:go_router/go_router.dart';

abstract final class ExportRoutes {
  static const centerPath = '/exports';
  static const centerName = 'export-center';

  static List<RouteBase> routes({required Widget Function() centerBuilder}) {
    return [
      GoRoute(
        path: centerPath,
        name: centerName,
        builder: (context, state) => centerBuilder(),
      ),
    ];
  }
}
