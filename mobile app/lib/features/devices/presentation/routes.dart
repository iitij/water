import 'package:flutter/widgets.dart';
import 'package:go_router/go_router.dart';

/// مسار شاشة الأجهزة المسجّلة (S7-07).
abstract final class DeviceRoutes {
  static const devicesPath = '/devices';
  static const devicesName = 'devices';

  static List<RouteBase> routes({required Widget Function() screenBuilder}) => <RouteBase>[
        GoRoute(
          path: devicesPath,
          name: devicesName,
          builder: (context, state) => screenBuilder(),
        ),
      ];
}
