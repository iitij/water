import 'package:flutter/widgets.dart';
import 'package:go_router/go_router.dart';

abstract final class SettingsRoutes {
  static const studioPath = '/settings';
  static const studioName = 'settings-studio';

  static List<RouteBase> routes({required Widget Function() studioBuilder}) {
    return [
      GoRoute(
        path: studioPath,
        name: studioName,
        builder: (context, state) => studioBuilder(),
      ),
    ];
  }
}
