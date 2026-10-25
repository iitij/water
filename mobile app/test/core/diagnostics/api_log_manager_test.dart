import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/diagnostics/api_log_manager.dart';
import 'package:water_project/core/diagnostics/models/api_log_entry.dart';
import 'package:water_project/core/network/interceptors/api_log_interceptor.dart';

void main() {
  group('ApiLogManager', () {
    late ApiLogManager manager;

    setUp(() {
      manager = ApiLogManager(maxEntries: 5);
    });

    test('records entries and maintains circular buffer limit', () {
      for (var i = 1; i <= 7; i++) {
        manager.addEntry(
          ApiLogEntry(
            id: 'id-$i',
            timestamp: DateTime.now(),
            method: 'GET',
            url: 'https://api.example.com/items/$i',
            endpoint: '/items/$i',
            statusCode: i == 3 ? 500 : 200,
            durationMs: i * 300,
            isSlow: (i * 300) >= 1200,
            isError: i == 3,
          ),
        );
      }

      // Max entries is 5, so only the last 5 (3, 4, 5, 6, 7) should exist
      expect(manager.totalCount, equals(5));
      expect(manager.logs.first.id, equals('id-7'));
      expect(manager.logs.last.id, equals('id-3'));
    });

    test('calculates correct metrics for errors, slow requests, and average duration', () {
      manager.addEntry(
        ApiLogEntry(
          id: '1',
          timestamp: DateTime.now(),
          method: 'GET',
          url: 'https://api.example.com/test1',
          endpoint: '/test1',
          statusCode: 200,
          durationMs: 200,
        ),
      );
      manager.addEntry(
        ApiLogEntry(
          id: '2',
          timestamp: DateTime.now(),
          method: 'POST',
          url: 'https://api.example.com/test2',
          endpoint: '/test2',
          statusCode: 500,
          durationMs: 400,
          isError: true,
          errorMessage: 'Server Error',
        ),
      );
      manager.addEntry(
        ApiLogEntry(
          id: '3',
          timestamp: DateTime.now(),
          method: 'GET',
          url: 'https://api.example.com/test3',
          endpoint: '/test3',
          statusCode: 200,
          durationMs: 1500,
          isSlow: true,
        ),
      );

      expect(manager.totalCount, equals(3));
      expect(manager.errorCount, equals(1));
      expect(manager.slowCount, equals(1));
      expect(manager.avgDurationMs, equals(700)); // (200 + 400 + 1500) / 3 = 700
    });

    test('filters logs by query and type', () {
      manager.addEntry(
        ApiLogEntry(
          id: '1',
          timestamp: DateTime.now(),
          method: 'GET',
          url: 'https://api.example.com/api/subscribers',
          endpoint: '/api/subscribers',
          statusCode: 200,
          durationMs: 150,
        ),
      );
      manager.addEntry(
        ApiLogEntry(
          id: '2',
          timestamp: DateTime.now(),
          method: 'POST',
          url: 'https://api.example.com/api/invoices/pay',
          endpoint: '/api/invoices/pay',
          statusCode: 422,
          durationMs: 300,
          isError: true,
          errorMessage: 'الرصيد غير كافٍ',
        ),
      );
      manager.addEntry(
        ApiLogEntry(
          id: '3',
          timestamp: DateTime.now(),
          method: 'GET',
          url: 'https://api.example.com/api/meters',
          endpoint: '/api/meters',
          statusCode: 200,
          durationMs: 2500,
          isSlow: true,
        ),
      );

      final errorFilter = manager.filter(type: 'errors');
      expect(errorFilter.length, equals(1));
      expect(errorFilter.first.endpoint, equals('/api/invoices/pay'));

      final slowFilter = manager.filter(type: 'slow');
      expect(slowFilter.length, equals(1));
      expect(slowFilter.first.endpoint, equals('/api/meters'));

      final searchFilter = manager.filter(query: 'subscribers');
      expect(searchFilter.length, equals(1));
      expect(searchFilter.first.endpoint, equals('/api/subscribers'));

      final searchByError = manager.filter(query: 'الرصيد');
      expect(searchByError.length, equals(1));
      expect(searchByError.first.id, equals('2'));
    });

    test('logs application-level errors via logAppError', () {
      manager.logAppError(
        'UI_Cubit',
        Exception('فشل في جلب قائمة المشتركين'),
        stackTrace: StackTrace.current,
        endpoint: '/cubit/subscribers',
      );

      expect(manager.totalCount, equals(1));
      final entry = manager.logs.first;
      expect(entry.method, equals('APP'));
      expect(entry.isError, isTrue);
      expect(entry.errorMessage, contains('فشل في جلب قائمة المشتركين'));
      expect(entry.context, equals('UI_Cubit'));
    });

    test('generates valid cURL and detailed report', () {
      final entry = ApiLogEntry(
        id: 'req-1',
        timestamp: DateTime(2026, 10, 24, 12, 0, 0),
        method: 'POST',
        url: 'https://api.example.com/api/readings',
        endpoint: '/api/readings',
        requestHeaders: const <String, dynamic>{'Authorization': 'Bearer test-token'},
        requestBody: const <String, dynamic>{'meter_id': 10, 'value': 250},
        statusCode: 201,
        durationMs: 180,
      );

      final curl = entry.toCurl();
      expect(curl, contains('curl -X POST "https://api.example.com/api/readings"'));
      expect(curl, contains('Authorization: Bearer test-token'));
      expect(curl, contains('meter_id'));

      final report = entry.toDetailedReport();
      expect(report, contains('=== تقرير طلب API ==='));
      expect(report, contains('POST /api/readings'));
      expect(report, contains('201 (180ms)'));
    });
  });

  group('ApiLogInterceptor', () {
    test('intercepts requests and records successful responses', () async {
      final manager = ApiLogManager();
      final interceptor = ApiLogInterceptor(logManager: manager);

      final dio = Dio(BaseOptions(baseUrl: 'https://api.example.com'));
      dio.interceptors.add(interceptor);
      dio.httpClientAdapter = _MockAdapter((options) async {
        return ResponseBody.fromString(
          '{"status":"ok"}',
          200,
          headers: <String, List<String>>{
            'x-request-id': <String>['srv-123'],
            'x-response-time-ms': <String>['45'],
            Headers.contentTypeHeader: <String>[Headers.jsonContentType],
          },
        );
      });

      final response = await dio.get<Map<String, dynamic>>('/api/test');
      expect(response.statusCode, equals(200));

      expect(manager.totalCount, equals(1));
      final logged = manager.logs.first;
      expect(logged.statusCode, equals(200));
      expect(logged.isError, isFalse);
      expect(logged.requestId, equals('srv-123'));
      expect(logged.serverDurationMs, equals(45));
    });

    test('intercepts and records errors with full details', () async {
      final manager = ApiLogManager();
      final interceptor = ApiLogInterceptor(logManager: manager);

      final dio = Dio(BaseOptions(baseUrl: 'https://api.example.com'));
      dio.interceptors.add(interceptor);
      dio.httpClientAdapter = _MockAdapter((options) async {
        return ResponseBody.fromString(
          '{"message":"البيانات المدخلة غير صالحة"}',
          422,
          headers: <String, List<String>>{
            Headers.contentTypeHeader: <String>[Headers.jsonContentType],
          },
        );
      });

      try {
        await dio.post<dynamic>('/api/invoices/pay', data: <String, dynamic>{'amount': 100});
      } catch (_) {}

      expect(manager.totalCount, equals(1));
      final logged = manager.logs.first;
      expect(logged.statusCode, equals(422));
      expect(logged.isError, isTrue);
      expect(logged.errorMessage, equals('البيانات المدخلة غير صالحة'));
    });
  });
}

class _MockAdapter implements HttpClientAdapter {
  _MockAdapter(this._handler);
  final Future<ResponseBody> Function(RequestOptions options) _handler;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<List<int>>? requestStream,
    Future<void>? cancelFuture,
  ) => _handler(options);

  @override
  void close({bool force = false}) {}
}
