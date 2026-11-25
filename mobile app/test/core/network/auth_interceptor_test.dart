import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/network/device_identity.dart';
import 'package:water_project/core/network/interceptors/auth_interceptor.dart';
import 'package:water_project/core/network/interceptors/client_headers_interceptor.dart';
import 'package:water_project/core/network/secure_storage_service.dart';
import 'package:water_project/core/network/token_refresh_service.dart';

class _MockStorage extends Mock implements SecureStorageService {}

class _MemoryDeviceStore implements DeviceIdStore {
  _MemoryDeviceStore([this.value]);

  String? value;

  @override
  Future<String?> read() async => value;

  @override
  Future<void> write(String newValue) async => value = newValue;
}

/// محوّل وهمي يسجّل كل طلب ويعيد رداً مُعدّاً مسبقاً حسب المسار.
class _RecordingAdapter implements HttpClientAdapter {
  _RecordingAdapter(this.responder);

  final ResponseBody Function(RequestOptions options) responder;
  final List<RequestOptions> requests = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    requests.add(options);
    return responder(options);
  }

  @override
  void close({bool force = false}) {}
}

ResponseBody _json(Map<String, dynamic> body, {int status = 200}) {
  return ResponseBody.fromString(
    jsonEncode(body),
    status,
    headers: <String, List<String>>{
      Headers.contentTypeHeader: <String>[Headers.jsonContentType],
    },
  );
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late _MockStorage storage;
  late List<String?> tokenWrites;

  setUp(() {
    storage = _MockStorage();
    tokenWrites = <String?>[];
    // تخزين حالة حقيقي: التوكن المكتوب يُقرأ في الطلب التالي، وإلا اختبرنا
    // سلوكاً لا يشبه التطبيق.
    var stored = 'old-token';
    when(() => storage.sessionGeneration).thenReturn(1);
    when(() => storage.readAccessToken()).thenAnswer((_) async => stored);
    when(() => storage.replaceAccessTokenIfCurrent(
      generation: any(named: 'generation'),
      expected: any(named: 'expected'),
      replacement: any(named: 'replacement'),
    )).thenAnswer((invocation) async {
      if (invocation.namedArguments[#generation] != 1 || invocation.namedArguments[#expected] != stored) return false;
      stored = invocation.namedArguments[#replacement] as String;
      tokenWrites.add(stored);
      return true;
    });
    when(() => storage.writeAccessToken(any())).thenAnswer((invocation) async {
      stored = invocation.positionalArguments.first as String;
      tokenWrites.add(stored);
    });
  });

  group('TokenRefreshService', () {
    test('rotates the stored token and keeps a single in-flight call', () async {
      final dio = Dio(BaseOptions(baseUrl: 'https://water.test/api/'));
      final adapter = _RecordingAdapter((_) => _json(<String, dynamic>{
            'access_token': 'new-token',
          }));
      dio.httpClientAdapter = adapter;

      final service = TokenRefreshService(dio: dio, secureStorage: storage);

      final results = await Future.wait<String?>(
        <Future<String?>>[service.refresh(), service.refresh(), service.refresh()],
      );

      expect(results, everyElement('new-token'));
      expect(adapter.requests, hasLength(1),
          reason: 'ثلاثة 401 متزامنة يجب أن تُنتج طلب تحديث واحداً');
      expect(adapter.requests.single.path, 'refresh');
      expect(
        adapter.requests.single.headers['Authorization'],
        'Bearer old-token',
      );
      expect(tokenWrites, <String>['new-token']);

      // طلب آخر بالتوكن القديم يصل متأخراً: يُعطى التوكن الجديد بلا تدوير ثانٍ.
      expect(await service.refresh(), 'new-token');
      expect(adapter.requests, hasLength(1));
    });

    test('returns null and cools down after a failed refresh', () async {
      final dio = Dio(BaseOptions(baseUrl: 'https://water.test/api/'));
      final adapter = _RecordingAdapter((_) => _json(
            <String, dynamic>{'message': 'Unauthenticated.'},
            status: 401,
          ));
      dio.httpClientAdapter = adapter;

      final service = TokenRefreshService(dio: dio, secureStorage: storage);

      expect(await service.refresh(), isNull);
      expect(await service.refresh(), isNull);
      expect(adapter.requests, hasLength(1), reason: 'فترة التهدئة تمنع كرّ الرشقات');
      expect(tokenWrites, isEmpty);
    });

    test('skips the request when no token is stored', () async {
      when(() => storage.readAccessToken()).thenAnswer((_) async => null);
      final dio = Dio(BaseOptions(baseUrl: 'https://water.test/api/'));
      final adapter = _RecordingAdapter((_) => _json(<String, dynamic>{}));
      dio.httpClientAdapter = adapter;

      final service = TokenRefreshService(dio: dio, secureStorage: storage);

      expect(await service.refresh(), isNull);
      expect(adapter.requests, isEmpty);
    });
  });

  group('AuthInterceptor', () {
    Dio buildDio(_RecordingAdapter adapter) {
      final dio = Dio(BaseOptions(baseUrl: 'https://water.test/api/'));
      dio.httpClientAdapter = adapter;
      final refresh = TokenRefreshService(dio: dio, secureStorage: storage);
      dio.interceptors.add(AuthInterceptor(
        storage,
        refreshService: refresh,
        retryClient: dio,
      ));
      return dio;
    }

    test('attaches the bearer token to every request', () async {
      final adapter = _RecordingAdapter((_) => _json(<String, dynamic>{'ok': true}));
      final dio = buildDio(adapter);

      await dio.get<dynamic>('payments');

      expect(adapter.requests.single.headers['Authorization'], 'Bearer old-token');
      expect(adapter.requests.single.headers['Accept'], 'application/json');
    });

    test('refreshes on 401 and replays the request once', () async {
      // الخادم لا يقبل إلا التوكن الجديد، فأول محاولة ترجع 401.
      var honouredToken = 'fresh-token';
      final adapter = _RecordingAdapter((options) {
        final auth = options.headers['Authorization'];
        if (options.path == 'refresh') {
          return _json(<String, dynamic>{'access_token': 'fresh-token'});
        }
        if (auth == 'Bearer $honouredToken') {
          return _json(<String, dynamic>{'data': <dynamic>[]});
        }
        return _json(<String, dynamic>{'message': 'Unauthenticated.'}, status: 401);
      });
      final dio = buildDio(adapter);

      final response = await dio.get<dynamic>('payments');

      expect(response.statusCode, 200);
      expect(adapter.requests.map((r) => r.path), <String>['payments', 'refresh', 'payments']);

      // الطلب المُعاد يجب أن يستخدم التوكن الجديد فعلاً.
      honouredToken = 'fresh-token';
      await dio.get<dynamic>('payments');
      expect(adapter.requests.last.headers['Authorization'], 'Bearer fresh-token');
    });

    test('does not loop when the server keeps answering 401', () async {
      final adapter = _RecordingAdapter(
        (_) => _json(<String, dynamic>{'message': 'Unauthenticated.'}, status: 401),
      );
      final dio = buildDio(adapter);

      await expectLater(
        dio.get<dynamic>('payments'),
        throwsA(isA<DioException>()
            .having((e) => e.response?.statusCode, 'statusCode', 401)),
      );
      // طلب أصلي + محاولة تحديث فاشلة، ثم يتوقف دون حلقة لا نهائية.
      expect(adapter.requests.map((r) => r.path), <String>['payments', 'refresh']);
    });

    test('skips auth entirely for public and refresh calls', () async {
      final adapter = _RecordingAdapter((_) => _json(<String, dynamic>{'ok': true}));
      final dio = buildDio(adapter);

      await dio.post<dynamic>(
        'login',
        options: Options(extra: const <String, dynamic>{'skipAuth': true}),
      );

      expect(adapter.requests.single.headers.containsKey('Authorization'), isFalse);
    });
  });

  group('ClientHeadersInterceptor', () {
    test('sends a stable device id with every request', () async {
      final adapter = _RecordingAdapter((_) => _json(<String, dynamic>{'ok': true}));
      final dio = Dio(BaseOptions(baseUrl: 'https://water.test/api/'));
      dio.httpClientAdapter = adapter;
      dio.interceptors.add(ClientHeadersInterceptor.withIdentity(
        DeviceIdentity(store: _MemoryDeviceStore()),
        appVersion: '1.0.0+1',
      ));

      await dio.get<dynamic>('me');
      await dio.get<dynamic>('me');

      final ids = adapter.requests
          .map((r) => r.headers['X-Device-Id']?.toString())
          .toList(growable: false);
      expect(ids, hasLength(2));
      expect(ids.first, isNotNull);
      expect(ids.first, matches(RegExp(r'^[0-9a-f-]{36}$')));
      expect(ids.first, ids.last, reason: 'المعرّف يجب أن يبقى ثابتاً بين الطلبات');
      expect(adapter.requests.first.headers['X-Client-Source'], 'mobile');
      expect(adapter.requests.first.headers['X-Client-Version'], '1.0.0+1');
    });

    test('reuses the stored device id instead of generating a new one', () async {
      const stored = '11111111-2222-4333-8444-555555555555';
      final store = _MemoryDeviceStore(stored);
      final adapter = _RecordingAdapter((_) => _json(<String, dynamic>{'ok': true}));
      final dio = Dio(BaseOptions(baseUrl: 'https://water.test/api/'));
      dio.httpClientAdapter = adapter;
      dio.interceptors.add(ClientHeadersInterceptor.withIdentity(
        DeviceIdentity(store: store),
      ));

      await dio.get<dynamic>('me');

      expect(adapter.requests.single.headers['X-Device-Id'], stored);
      expect(store.value, stored, reason: 'المعرّف المخزَّن لا يُعاد توليده');
    });

    test('generates a UUID that survives an empty store', () async {
      final store = _MemoryDeviceStore();
      final identity = DeviceIdentity(store: store);

      final id = await identity.id();

      expect(store.value, id);
      expect(id, matches(RegExp(r'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')));
    });
  });
}
