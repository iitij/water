import 'package:flutter/material.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';

import '../../features/auth/presentation/pages/edit_profile_screen.dart';
import '../../features/auth/presentation/pages/login_screen.dart';
import '../../features/auth/presentation/pages/splash_screen.dart';
import '../../features/home/presentation/home_screen.dart';
import '../../features/offline_sync/presentation/offline_sync_monitor_screen.dart';
import '../auth/session_service.dart';
import '../responsive/adaptive_scaffold.dart';
import '../theme/app_theme.dart';
import 'route_names.dart';

class AppRouter {
  AppRouter({required SessionService session}) : router = _createRouter(session);

  final GoRouter router;

  static GoRouter _createRouter(SessionService session) => GoRouter(
        initialLocation: RouteNames.splash,
        refreshListenable: session,
        redirect: (context, state) {
          final location = state.matchedLocation;
          final isPublic = location == RouteNames.splash || location == RouteNames.login;
          if (!session.isReady) {
            return location == RouteNames.splash ? null : RouteNames.splash;
          }
          if (!session.isAuthenticated) {
            return location == RouteNames.login ? null : RouteNames.login;
          }
          if (isPublic) return RouteNames.home;
          return null;
        },
        routes: <RouteBase>[
          GoRoute(
            path: RouteNames.splash,
            builder: (context, state) => const SplashScreen(),
          ),
          GoRoute(
            path: RouteNames.login,
            builder: (context, state) => const LoginScreen(),
          ),
          StatefulShellRoute.indexedStack(
            builder: (context, state, navigationShell) => AppHomeShell(shell: navigationShell),
            branches: <StatefulShellBranch>[
              StatefulShellBranch(
                routes: <RouteBase>[
                  GoRoute(
                    path: RouteNames.home,
                    builder: (context, state) => const HomeScreen(),
                  ),
                ],
              ),
              StatefulShellBranch(
                routes: <RouteBase>[
                  GoRoute(
                    path: RouteNames.offlineSync,
                    builder: (context, state) => const OfflineSyncMonitorScreen(),
                  ),
                ],
              ),
              StatefulShellBranch(
                routes: <RouteBase>[
                  GoRoute(
                    path: RouteNames.profile,
                    builder: (context, state) => const EditProfileScreen(),
                  ),
                ],
              ),
            ],
          ),
        ],
      );
}

class AppHomeShell extends StatelessWidget {
  const AppHomeShell({super.key, required this.shell});

  final StatefulNavigationShell shell;

  static const _destinations = <AdaptiveDestination>[
    AdaptiveDestination(label: 'الرئيسية', icon: Icons.home_outlined, selectedIcon: Icons.home_rounded),
    AdaptiveDestination(label: 'المزامنة', icon: Icons.sync_outlined, selectedIcon: Icons.sync_rounded),
    AdaptiveDestination(label: 'الملف الشخصي', icon: Icons.person_outline_rounded, selectedIcon: Icons.person_rounded),
  ];

  @override
  Widget build(BuildContext context) {
    final titles = <String>['الرئيسية', 'مراقب المزامنة', 'الملف الشخصي'];
    final currentIndex = shell.currentIndex.clamp(0, titles.length - 1).toInt();
    return AdaptiveScaffold(
      currentIndex: currentIndex,
      destinations: _destinations,
      onDestinationSelected: (index) => shell.goBranch(
        index,
        initialLocation: index == shell.currentIndex,
      ),
      appBar: AppBar(
        title: Text(titles[currentIndex]),
        actions: <Widget>[
          IconButton(
            tooltip: 'تبديل المظهر',
            onPressed: () => GetIt.I<AppThemeController>().toggle(),
            icon: Icon(Theme.of(context).brightness == Brightness.dark ? Icons.light_mode_rounded : Icons.dark_mode_rounded),
          ),
          const SizedBox(width: 6),
        ],
      ),
      body: shell,
    );
  }
}
