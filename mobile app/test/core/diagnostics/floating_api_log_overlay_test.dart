import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get_it/get_it.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:water_project/core/diagnostics/api_log_manager.dart';
import 'package:water_project/core/security/developer_access_service.dart';
import 'package:water_project/features/diagnostics/presentation/widgets/floating_api_log_overlay.dart';

void main() {
  for (final visible in [false, true]) {
    testWidgets('logging during child build is safe (visible: $visible)', (
      tester,
    ) async {
      final manager = ApiLogManager()..showFloatingPill = visible;
      final access = visible ? _developerAccess() : null;
      GetIt.I.registerSingleton<ApiLogManager>(manager);
      addTearDown(() async {
        await GetIt.I.reset();
        manager.dispose();
        access?.dispose();
      });
      if (access != null) {
        SharedPreferences.setMockInitialValues(<String, Object>{});
        GetIt.I.registerSingleton<DeveloperAccessService>(access);
        await tester.runAsync(() async {
          expect(await access.unlock('77288'), DeveloperUnlockResult.success);
          await access.setFeatureEnabled('api_logs', true);
        });
      }
      await tester.pumpWidget(
        MaterialApp(
          home: FloatingApiLogOverlay(
            child: Builder(
              builder: (_) {
                manager.logAppError('invoice', StateError('original error'));
                return const SizedBox();
              },
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
      expect(manager.errorCount, 1);
      expect(find.text('API: 1'), visible ? findsOneWidget : findsNothing);
    });
  }

  testWidgets('floating API log pill follows the live developer grant', (
    tester,
  ) async {
    SharedPreferences.setMockInitialValues(<String, Object>{});
    final manager = ApiLogManager()..showFloatingPill = true;
    final access = _developerAccess();
    GetIt.I.registerSingleton<ApiLogManager>(manager);
    GetIt.I.registerSingleton<DeveloperAccessService>(access);
    addTearDown(() async {
      await GetIt.I.reset();
      manager.dispose();
      access.dispose();
    });

    await tester.pumpWidget(
      const MaterialApp(
        home: FloatingApiLogOverlay(child: SizedBox.shrink()),
      ),
    );
    expect(find.text('API: 0'), findsNothing);

    await tester.runAsync(() async {
      expect(await access.unlock('77288'), DeveloperUnlockResult.success);
    });
    await tester.pump();
    expect(find.text('API: 0'), findsNothing);
    await tester.runAsync(() => access.setFeatureEnabled('api_logs', true));
    await tester.pump();
    expect(find.text('API: 0'), findsOneWidget);

    access.lock();
    await tester.pump();
    expect(find.text('API: 0'), findsNothing);
  });
}

DeveloperAccessService _developerAccess() {
  final now = DateTime.utc(2026, 10, 29, 12);
  final dio = Dio(BaseOptions(baseUrl: 'https://example.test/api/'));
  dio.interceptors.add(
    InterceptorsWrapper(
      onRequest: (options, handler) => handler.resolve(
        Response<Map<String, dynamic>>(
          requestOptions: options,
          statusCode: 200,
          data: <String, dynamic>{
            'authorized': true,
            'grant': 'temporary-grant',
            'expires_at': now.add(const Duration(minutes: 15)).toIso8601String(),
          },
        ),
      ),
    ),
  );
  return DeveloperAccessService(dio: dio, now: () => now);
}
