import 'package:flutter/widgets.dart';
import 'package:go_router/go_router.dart';

abstract final class SettingsRoutes {
  static const studioPath = '/settings';
  static const studioName = 'settings-studio';

  static List<RouteBase> routes({
    Widget Function()? studioBuilder,
    Widget Function(String? section)? sectionBuilder,
  }) {
    assert(studioBuilder != null || sectionBuilder != null);
    return [
      GoRoute(
        path: studioPath,
        name: studioName,
        builder: (context, state) => sectionBuilder != null
            ? sectionBuilder(state.uri.queryParameters['section'])
            : studioBuilder!(),
      ),
    ];
  }
}
