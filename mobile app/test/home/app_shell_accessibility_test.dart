import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/auth/session_service.dart';
import 'package:water_project/core/l10n/app_strings.dart';
import 'package:water_project/core/navigation/app_shell.dart';
import 'package:water_project/core/theme/app_theme.dart';
import 'package:water_project/features/offline_sync/services/sync_queue_manager.dart';

class _Session extends Mock implements SessionService {}

class _Queue extends Mock implements SyncQueueManager {}

void main() {
  setUpAll(() async {
    final font = FontLoader('IBMPlexSansArabic');
    for (final weight in ['Regular', 'Medium', 'SemiBold', 'Bold']) {
      font.addFont(
        rootBundle.load('assets/fonts/IBMPlexSansArabic-$weight.ttf'),
      );
    }
    await font.load();
    final icons = FontLoader('MaterialIcons')
      ..addFont(rootBundle.load('fonts/MaterialIcons-Regular.otf'));
    await icons.load();
  });
  tearDown(() => GetIt.I.reset());

  testWidgets(
    'real five-tab shell fits 320dp at 2x and tabs keep their navigation',
    (tester) async {
      tester.view.physicalSize = const Size(320, 900);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final session = _Session();
      when(() => session.user).thenReturn({'name': 'عبدالرحمن صاحب المشروع'});
      final queue = _Queue();
      when(() => queue.isOnline).thenReturn(true);
      when(() => queue.pendingCount).thenReturn(2);
      GetIt.I.registerSingleton<SessionService>(session);
      GetIt.I.registerSingleton<SyncQueueManager>(queue);
      final router = GoRouter(
        initialLocation: '/branch0',
        routes: [
          StatefulShellRoute.indexedStack(
            builder: (context, state, shell) => AppHomeShell(shell: shell),
            branches: [
              for (var index = 0; index < 5; index++)
                StatefulShellBranch(
                  routes: [
                    GoRoute(
                      path: '/branch$index',
                      builder: (_, _) => Center(child: Text('content-$index')),
                    ),
                  ],
                ),
            ],
          ),
        ],
      );
      addTearDown(router.dispose);
      await tester.pumpWidget(
        MaterialApp.router(
          routerConfig: router,
          theme: AppTheme.light,
          locale: const Locale('ar'),
          supportedLocales: const [Locale('ar')],
          localizationsDelegates: GlobalMaterialLocalizations.delegates,
          builder: (context, child) => MediaQuery(
            data: MediaQuery.of(
              context,
            ).copyWith(textScaler: const TextScaler.linear(2)),
            child: child!,
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
      final strings = AppStrings.ar;
      final labels = [
        strings.navHome,
        strings.navField,
        strings.navFinance,
        strings.navSubscribers,
        strings.navMore,
      ];
      for (var index = 0; index < labels.length; index++) {
        final label = find.text(labels[index]);
        expect(label, findsOneWidget);
        await tester.tap(label);
        await tester.pumpAndSettle();
        expect(find.text('content-$index'), findsOneWidget);
        expect(tester.takeException(), isNull);
      }
    },
  );
}
