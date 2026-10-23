import 'package:flutter/widgets.dart';
import 'package:go_router/go_router.dart';

abstract final class UsersRolesRoutes {
  static const usersPath = '/users';
  static const usersName = 'users-management';
  static const rolesPath = '/roles';
  static const rolesName = 'roles-permissions';

  static List<RouteBase> routes({
    required Widget Function() usersBuilder,
    required Widget Function() rolesBuilder,
  }) {
    return [
      GoRoute(
        path: usersPath,
        name: usersName,
        builder: (context, state) => usersBuilder(),
      ),
      GoRoute(
        path: rolesPath,
        name: rolesName,
        builder: (context, state) => rolesBuilder(),
      ),
    ];
  }
}
