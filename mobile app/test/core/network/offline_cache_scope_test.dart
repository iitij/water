import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/network/interceptors/auth_interceptor.dart';
import 'package:water_project/core/network/interceptors/offline_cache_interceptor.dart';

class _Adapter implements HttpClientAdapter {
  _Adapter(this.respond);
  final Future<ResponseBody> Function(RequestOptions) respond;
  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? stream,
      Future<void>? cancelFuture) => respond(options);
  @override
  void close({bool force = false}) {}
}

ResponseBody reply(String owner) => ResponseBody.fromString(
    jsonEncode({'owner': owner}), 200,
    headers: {Headers.contentTypeHeader: [Headers.jsonContentType]});

void main() {
  late Dio dio;
  late OfflineCacheInterceptor cache;
  late int generation;
  late bool online;
  late bool authenticated;
  late String owner;

  Options opts({bool skip = false}) =>
      Options(extra: {'offlineCache': true, 'skipOfflineCache': skip});

  setUp(() {
    generation = 1;
    online = true;
    authenticated = true;
    owner = 'A';
    cache = OfflineCacheInterceptor(currentGeneration: () => generation);
    dio = Dio(BaseOptions(baseUrl: 'https://water.test/api/'));
    dio.interceptors.add(InterceptorsWrapper(onRequest: (options, handler) {
      options.extra[AuthInterceptor.sessionGenerationKey] = generation;
      if (authenticated) options.headers['Authorization'] = 'Bearer synthetic';
      handler.next(options);
    }));
    dio.interceptors.add(cache);
    dio.httpClientAdapter = _Adapter((options) async {
      if (!online) {
        throw DioException(requestOptions: options,
            type: DioExceptionType.connectionError);
      }
      return reply(owner);
    });
  });

  test('same generation fallback is an independent snapshot; new account cannot read it', () async {
    final first = await dio.get<dynamic>('subscribers', options: opts());
    (first.data as Map)['owner'] = 'mutated';
    online = false;
    final fallback = await dio.get<dynamic>('subscribers', options: opts());
    expect(fallback.extra['offlineCacheHit'], isTrue);
    expect((fallback.data as Map)['owner'], 'A');
    (fallback.data as Map)['owner'] = 'again';
    expect(((await dio.get<dynamic>('subscribers', options: opts())).data as Map)['owner'], 'A');
    generation++;
    await expectLater(dio.get<dynamic>('subscribers', options: opts()), throwsA(isA<DioException>()));
    online = true;
    owner = 'B';
    await dio.get<dynamic>('subscribers', options: opts());
    online = false;
    expect(((await dio.get<dynamic>('subscribers', options: opts())).data as Map)['owner'], 'B');
  });

  test('late response after clear cannot repopulate the cache', () async {
    final started = Completer<void>();
    final response = Completer<ResponseBody>();
    dio.httpClientAdapter = _Adapter((options) async {
      if (!online) throw DioException(requestOptions: options,
          type: DioExceptionType.connectionError);
      started.complete();
      return response.future;
    });
    final pending = dio.get<dynamic>('subscribers', options: opts());
    await started.future;
    cache.clear();
    response.complete(reply('A'));
    await pending;
    online = false;
    await expectLater(dio.get<dynamic>('subscribers', options: opts()), throwsA(isA<DioException>()));
  });

  test('old in-flight generation cannot repopulate new-generation fallback', () async {
    final started = Completer<void>();
    final response = Completer<ResponseBody>();
    dio.httpClientAdapter = _Adapter((options) async {
      if (!online) throw DioException(requestOptions: options,
          type: DioExceptionType.connectionError);
      started.complete();
      return response.future;
    });
    final pending = dio.get<dynamic>('subscribers', options: opts());
    await started.future;
    generation++;
    response.complete(reply('A'));
    await pending;
    online = false;
    await expectLater(dio.get<dynamic>('subscribers', options: opts()), throwsA(isA<DioException>()));
  });

  test('no fallback for unauthorized, different server, or explicit bypass', () async {
    await dio.get<dynamic>('subscribers', options: opts());
    online = false;
    await expectLater(dio.get<dynamic>('subscribers', options: opts(skip: true)), throwsA(isA<DioException>()));
    authenticated = false;
    await expectLater(dio.get<dynamic>('subscribers', options: opts()), throwsA(isA<DioException>()));
    authenticated = true;
    dio.options.baseUrl = 'https://other.test/api/';
    await expectLater(dio.get<dynamic>('subscribers', options: opts()), throwsA(isA<DioException>()));
    dio.options.baseUrl = 'https://water.test/api/';
    dio.httpClientAdapter = _Adapter((_) async => ResponseBody.fromString('{}', 403,
        headers: {Headers.contentTypeHeader: [Headers.jsonContentType]}));
    await expectLater(dio.get<dynamic>('subscribers', options: opts()), throwsA(isA<DioException>()));
  });
}
