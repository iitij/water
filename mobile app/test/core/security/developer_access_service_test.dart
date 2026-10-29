import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get_it/get_it.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:water_project/core/diagnostics/api_log_manager.dart';
import 'package:water_project/core/network/interceptors/api_log_interceptor.dart';
import 'package:water_project/core/router/app_router.dart';
import 'package:water_project/core/router/route_names.dart';
import 'package:water_project/core/security/developer_access_service.dart';
import 'package:water_project/features/navigation/presentation/pages/developer_mode_sheet.dart';

void main() {
  late Dio dio;
  late DateTime now;
  late DeveloperAccessService access;

  setUp(() {
    SharedPreferences.setMockInitialValues(<String, Object>{});
    now = DateTime.utc(2026, 10, 27, 12);
    dio = Dio(BaseOptions(baseUrl: 'https://example.test/api/'));
    access = DeveloperAccessService(dio: dio, now: () => now);
  });

  tearDown(() => access.dispose());

  test(
    'successful unlock allows local visibility choices and expires locally',
    () async {
      dio.httpClientAdapter = _ReplyAdapter((options) async {
        expect(options.path, 'developer/verify-pin');
        expect(options.data, {'pin': '77288'});
        return _jsonReply(200, {
          'authorized': true,
          'grant': 'grant-value',
          'expires_at': now.add(const Duration(minutes: 15)).toIso8601String(),
        });
      });

      expect(await access.unlock('77288'), DeveloperUnlockResult.success);
      expect(access.isUnlocked, isTrue);
      await access.loadVisibility();
      expect(access.isFeatureEnabled('mobile_logs'), isTrue);
      expect(access.isFeatureEnabled('whatsapp_settings'), isFalse);
      await access.setFeatureEnabled('whatsapp_settings', true);
      expect(access.isFeatureEnabled('whatsapp_settings'), isTrue);
      final reopened = DeveloperAccessService(dio: dio, now: () => now);
      addTearDown(reopened.dispose);
      await reopened.loadVisibility();
      expect(reopened.isFeatureEnabled('whatsapp_settings'), isTrue);
      expect(access.grantOptions.headers!['X-Developer-Grant'], 'grant-value');

      now = now.add(const Duration(minutes: 15));
      expect(access.isUnlocked, isFalse);
      expect(access.grant, isNull);
      expect(() => access.grantOptions, throwsStateError);
    },
  );

  test('wrong PIN, rate limit, and outage fail closed', () async {
    dio.httpClientAdapter = _ReplyAdapter(
      (options) async => _jsonReply(422, {'message': 'wrong'}),
    );
    expect(await access.unlock('00000'), DeveloperUnlockResult.wrongPin);
    expect(access.isUnlocked, isFalse);

    dio.httpClientAdapter = _ReplyAdapter(
      (options) async => _jsonReply(429, {'message': 'limited'}),
    );
    expect(await access.unlock('77288'), DeveloperUnlockResult.rateLimited);

    dio.httpClientAdapter = _ReplyAdapter((options) async {
      throw DioException(
        requestOptions: options,
        type: DioExceptionType.connectionError,
      );
    });
    expect(await access.unlock('77288'), DeveloperUnlockResult.unavailable);
    expect(access.isUnlocked, isFalse);
  });

  test(
    'developer routes require a live grant even when visibility persists',
    () async {
      await access.loadVisibility();
      expect(access.isFeatureEnabled('diagnostics'), isTrue);
      expect(
        AppRouter.developerRedirectFor(RouteNames.diagnostics, access),
        RouteNames.moreHub,
      );
      expect(
        AppRouter.developerRedirectFor(RouteNames.apiLogs, access),
        RouteNames.moreHub,
      );
      expect(
        AppRouter.developerRedirectFor(RouteNames.apiLogs, null),
        RouteNames.moreHub,
      );
      expect(
        AppRouter.developerRedirectFor(RouteNames.offlineSync, access),
        isNull,
      );

      dio.httpClientAdapter = _ReplyAdapter((options) async {
        return _jsonReply(200, {
          'authorized': true,
          'grant': 'temporary-grant',
          'expires_at': now.add(const Duration(minutes: 15)).toIso8601String(),
        });
      });
      expect(await access.unlock('77288'), DeveloperUnlockResult.success);
      expect(
        AppRouter.developerRedirectFor(RouteNames.diagnostics, access),
        isNull,
      );
      expect(
        AppRouter.developerRedirectFor(RouteNames.apiLogs, access),
        RouteNames.moreHub,
      );
      await access.setFeatureEnabled('api_logs', true);
      expect(
        AppRouter.developerRedirectFor(RouteNames.apiLogs, access),
        isNull,
      );

      now = now.add(const Duration(minutes: 15));
      expect(
        AppRouter.developerRedirectFor(RouteNames.diagnostics, access),
        RouteNames.moreHub,
      );
      expect(
        AppRouter.developerRedirectFor(RouteNames.apiLogs, access),
        RouteNames.moreHub,
      );
      expect(
        AppRouter.developerRedirectFor(RouteNames.offlineSync, access),
        isNull,
      );
      access.lock();
    },
  );

  test(
    'API log interceptor never stores PIN, grant, or nested tokens',
    () async {
      final manager = ApiLogManager();
      dio.interceptors.add(ApiLogInterceptor(logManager: manager));
      dio.httpClientAdapter = _ReplyAdapter(
        (options) async => _jsonReply(200, {
          'authorized': true,
          'grant': 'server-grant-secret',
          'nested': {'access_token': 'nested-secret'},
        }),
      );

      await dio.post<Map<String, dynamic>>(
        'developer/verify-pin',
        data: {
          'pin': '77288',
          'nested': {'token': 'request-secret'},
        },
        options: Options(
          headers: {
            'Authorization': 'Bearer auth-secret',
            'X-Developer-Grant': 'header-secret',
          },
        ),
      );
      final log = jsonEncode(manager.logs.single.toJson());
      for (final secret in [
        '77288',
        'auth-secret',
        'header-secret',
        'request-secret',
        'server-grant-secret',
        'nested-secret',
      ]) {
        expect(log, isNot(contains(secret)));
      }
    },
  );

  testWidgets('developer sheet hides tools until PIN and changes local visibility', (
    tester,
  ) async {
    dio.httpClientAdapter = _ReplyAdapter((options) async {
      if (options.path == 'developer/verify-pin') {
        return _jsonReply(200, {
          'authorized': true,
          'grant': 'temporary-grant',
          'expires_at': now.add(const Duration(minutes: 15)).toIso8601String(),
        });
      }
      return _jsonReply(200, {'authorized': true});
    });
    GetIt.I.registerSingleton<DeveloperAccessService>(access);
    addTearDown(() => GetIt.I.unregister<DeveloperAccessService>());

    await tester.pumpWidget(
      MaterialApp(
        home: Builder(
          builder: (context) =>
              Scaffold(body: DeveloperModeSheet(pageContext: context)),
        ),
      ),
    );
    expect(find.byIcon(Icons.dns_outlined), findsNothing);
    expect(find.byIcon(Icons.upload_file_rounded), findsNothing);
    expect(find.byIcon(Icons.chat_rounded), findsNothing);
    expect(find.byIcon(Icons.health_and_safety_outlined), findsNothing);
    expect(find.byIcon(Icons.receipt_long_rounded), findsNothing);
    await tester.enterText(find.byType(TextField), '77288');
    await tester.tap(find.byIcon(Icons.lock_open_rounded));
    await tester.pumpAndSettle();
    expect(find.byIcon(Icons.dns_outlined), findsOneWidget);
    expect(find.byIcon(Icons.upload_file_rounded), findsOneWidget);
    expect(find.byIcon(Icons.chat_rounded), findsNothing);
    expect(find.byIcon(Icons.health_and_safety_outlined), findsOneWidget);
    expect(find.byIcon(Icons.receipt_long_rounded), findsNothing);
    await tester.ensureVisible(find.byType(SwitchListTile).first);
    await tester.tap(find.byType(SwitchListTile).first);
    await tester.pumpAndSettle();
    expect(access.isFeatureEnabled('whatsapp_settings'), isTrue);
    expect(find.byIcon(Icons.chat_rounded), findsOneWidget);
    access.lock();
    await tester.pumpAndSettle();
    expect(find.byIcon(Icons.dns_outlined), findsNothing);
    expect(find.byIcon(Icons.upload_file_rounded), findsNothing);
    expect(find.byIcon(Icons.chat_rounded), findsNothing);
    expect(find.byIcon(Icons.health_and_safety_outlined), findsNothing);
  });
}

ResponseBody _jsonReply(int status, Map<String, dynamic> data) =>
    ResponseBody.fromString(
      jsonEncode(data),
      status,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );

class _ReplyAdapter implements HttpClientAdapter {
  _ReplyAdapter(this.handler);
  final Future<ResponseBody> Function(RequestOptions) handler;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<List<int>>? requestStream,
    Future<void>? cancelFuture,
  ) => handler(options);

  @override
  void close({bool force = false}) {}
}
