import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get_it/get_it.dart';
import 'package:water_project/core/diagnostics/api_log_manager.dart';
import 'package:water_project/core/network/interceptors/api_log_interceptor.dart';
import 'package:water_project/core/security/developer_access_service.dart';
import 'package:water_project/features/navigation/presentation/pages/developer_mode_sheet.dart';

void main() {
  late Dio dio;
  late DateTime now;
  late DeveloperAccessService access;

  setUp(() {
    now = DateTime.utc(2026, 10, 27, 12);
    dio = Dio(BaseOptions(baseUrl: 'https://example.test/api/'));
    access = DeveloperAccessService(dio: dio, now: () => now);
  });

  test(
    'successful unlock exposes scoped features and expires locally',
    () async {
      dio.httpClientAdapter = _ReplyAdapter((options) async {
        expect(options.path, 'developer/verify-pin');
        expect(options.data, {'pin': '77288'});
        return _jsonReply(200, {
          'authorized': true,
          'grant': 'grant-value',
          'expires_at': now.add(const Duration(minutes: 15)).toIso8601String(),
          'features': {'mobile_logs': true, 'whatsapp_settings': false},
        });
      });

      expect(await access.unlock('77288'), DeveloperUnlockResult.success);
      expect(access.isUnlocked, isTrue);
      expect(access.isFeatureEnabled('mobile_logs'), isTrue);
      expect(access.isFeatureEnabled('whatsapp_settings'), isFalse);
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

  testWidgets('developer sheet hides tools until PIN and honors server flags', (
    tester,
  ) async {
    dio.httpClientAdapter = _ReplyAdapter((options) async {
      if (options.path == 'developer/verify-pin') {
        return _jsonReply(200, {
          'authorized': true,
          'grant': 'temporary-grant',
          'expires_at': now.add(const Duration(minutes: 15)).toIso8601String(),
          'features': {
            'server_settings': true,
            'mobile_logs': true,
            'whatsapp_settings': false,
          },
        });
      }
      return _jsonReply(200, {
        'features': {
          'server_settings': true,
          'mobile_logs': true,
          'whatsapp_settings': false,
        },
      });
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

    await tester.enterText(find.byType(TextField), '77288');
    await tester.tap(find.byIcon(Icons.lock_open_rounded));
    await tester.pumpAndSettle();
    expect(find.byIcon(Icons.dns_outlined), findsOneWidget);
    expect(find.byIcon(Icons.upload_file_rounded), findsOneWidget);
    expect(find.byIcon(Icons.chat_rounded), findsNothing);
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
