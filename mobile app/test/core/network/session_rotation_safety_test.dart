import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/network/interceptors/auth_interceptor.dart';
import 'package:water_project/core/network/secure_storage_service.dart';
import 'package:water_project/core/network/token_refresh_service.dart';

class _Adapter implements HttpClientAdapter {
  _Adapter(this.respond);
  final Future<ResponseBody> Function(RequestOptions) respond;

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? stream,
          Future<void>? cancelFuture) =>
      respond(options);

  @override
  void close({bool force = false}) {}
}

ResponseBody _json(Map<String, dynamic> data, {int status = 200}) =>
    ResponseBody.fromString(jsonEncode(data), status, headers: {
      Headers.contentTypeHeader: [Headers.jsonContentType],
    });

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  setUp(() => FlutterSecureStorage.setMockInitialValues({}));

  test('remember=false remains volatile after successful rotation', () async {
    final storage = SecureStorageService();
    await storage.writeAccessToken('temporary', persist: false);
    final dio = Dio(BaseOptions(baseUrl: 'https://water.test/api/'))..httpClientAdapter = _Adapter((_) async =>
        _json({'access_token': 'rotated'}));
    final refresh = TokenRefreshService(dio: dio, secureStorage: storage);

    expect(await refresh.refresh(), 'rotated');
    expect(await storage.readAccessToken(), 'rotated');
    expect(await const FlutterSecureStorage().read(key: 'water.access_token'), isNull);
    expect(await SecureStorageService().readAccessToken(), isNull);
  });

  test('late refresh cannot recreate a cleared session', () async {
    final storage = SecureStorageService();
    await storage.writeAccessToken('a');
    final started = Completer<void>();
    final response = Completer<ResponseBody>();
    final dio = Dio(BaseOptions(baseUrl: 'https://water.test/api/'))..httpClientAdapter = _Adapter((_) {
      started.complete();
      return response.future;
    });
    final refresh = TokenRefreshService(dio: dio, secureStorage: storage);

    final pending = refresh.refresh();
    await started.future;
    await storage.clearSession();
    refresh.reset();
    response.complete(_json({'access_token': 'late-a'}));

    expect(await pending, isNull);
    expect(await storage.readAccessToken(), isNull);
  });

  test('late old-account flight cannot overwrite or clear a new flight', () async {
    final storage = SecureStorageService();
    await storage.writeAccessToken('a');
    final oldStarted = Completer<void>();
    final newStarted = Completer<void>();
    final oldResponse = Completer<ResponseBody>();
    final newResponse = Completer<ResponseBody>();
    var calls = 0;
    final dio = Dio(BaseOptions(baseUrl: 'https://water.test/api/'))..httpClientAdapter = _Adapter((options) {
      calls++;
      if (options.headers['Authorization'] == 'Bearer a') {
        oldStarted.complete();
        return oldResponse.future;
      }
      newStarted.complete();
      return newResponse.future;
    });
    final refresh = TokenRefreshService(dio: dio, secureStorage: storage);

    final old = refresh.refresh();
    await oldStarted.future;
    await storage.clearSession();
    await storage.writeAccessToken('b');
    final current = refresh.refresh();
    await newStarted.future;
    oldResponse.complete(_json({'access_token': 'late-a'}));
    expect(await old, isNull);
    expect(await storage.readAccessToken(), 'b');
    expect(identical(refresh.refresh(), current), isTrue);
    newResponse.complete(_json({'access_token': 'fresh-b'}));
    expect(await current, 'fresh-b');
    expect(await storage.readAccessToken(), 'fresh-b');
    expect(calls, 2);
  });

  for (final status in [200, 401]) {
    test('old account response $status is cancelled without a cross-account retry', () async {
      final storage = SecureStorageService();
      await storage.writeAccessToken('a');
      final started = Completer<void>();
      final response = Completer<ResponseBody>();
      var calls = 0;
      final dio = Dio(BaseOptions(baseUrl: 'https://water.test/api/'))..httpClientAdapter = _Adapter((_) {
        calls++;
        started.complete();
        return response.future;
      });
      final refresh = TokenRefreshService(dio: dio, secureStorage: storage);
      dio.interceptors.add(AuthInterceptor(storage, refreshService: refresh, retryClient: dio));

      final pending = dio.get<dynamic>('https://water.test/payments');
      final assertion = expectLater(pending, throwsA(isA<DioException>().having(
        (error) => error.type, 'type', DioExceptionType.cancel)));
      await started.future;
      await storage.clearSession();
      await storage.writeAccessToken('b');
      response.complete(_json({'private': 'old-account-data'}, status: status));
      await assertion;
      expect(calls, 1);
      expect(await storage.readAccessToken(), 'b');
    });
  }
}
