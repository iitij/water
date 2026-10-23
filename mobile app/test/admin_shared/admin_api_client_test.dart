import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/features/admin_shared/data/admin_api_client.dart';

void main() {
  test('cache fallback never masks auth or validation failures', () {
    expect(
      adminAllowsCacheFallback(const AdminApiException('denied', statusCode: 403)),
      isFalse,
    );
    expect(
      adminAllowsCacheFallback(const AdminApiException('invalid', statusCode: 422)),
      isFalse,
    );
    expect(
      adminAllowsCacheFallback(const AdminApiException('offline')),
      isTrue,
    );
    expect(
      adminAllowsCacheFallback(const AdminApiException('missing', statusCode: 404)),
      isTrue,
    );
  });

  test('404 invokes fallback exactly once', () async {
    final adapter = _SequenceAdapter([
      _AdapterResponse.json({'message': 'Not found'}, 404),
      _AdapterResponse.json({'reading_period': 'monthly'}, 200),
    ]);
    final client = DioAdminApiClient(Dio()..httpClientAdapter = adapter);

    final result = await client.get('/settings/reading', fallbackPath: '/settings');

    expect(result, {'reading_period': 'monthly'});
    expect(adapter.paths, ['/settings/reading', '/settings']);
  });

  test('validation errors never execute the fallback request', () async {
    final adapter = _SequenceAdapter([
      _AdapterResponse.json({
        'message': 'Invalid data',
        'errors': {
          'reading_day': ['اختر يوماً صالحاً'],
        },
      }, 422),
    ]);
    final client = DioAdminApiClient(Dio()..httpClientAdapter = adapter);

    await expectLater(
      client.get('/settings/reading', fallbackPath: '/settings'),
      throwsA(
        isA<AdminApiException>()
            .having((error) => error.statusCode, 'statusCode', 422)
            .having((error) => error.message, 'message', 'اختر يوماً صالحاً'),
      ),
    );
    expect(adapter.paths, ['/settings/reading']);
  });

  test('download reads bytes and Content-Disposition filename', () async {
    final adapter = _SequenceAdapter([
      _AdapterResponse.bytes(
        const [0x50, 0x4B, 0x03, 0x04],
        200,
        headers: {
          'content-disposition': ['attachment; filename="subscribers.xlsx"'],
        },
      ),
    ]);
    final client = DioAdminApiClient(Dio()..httpClientAdapter = adapter);

    final result = await client.download('/exports/subscribers');

    expect(result.fileName, 'subscribers.xlsx');
    expect(result.bytes, [0x50, 0x4B, 0x03, 0x04]);
  });
}

class _AdapterResponse {
  const _AdapterResponse._(this.body, this.statusCode, this.isJson, this.headers);

  factory _AdapterResponse.json(Object body, int statusCode) {
    return _AdapterResponse._(jsonEncode(body), statusCode, true, const {});
  }

  factory _AdapterResponse.bytes(
    List<int> body,
    int statusCode, {
    Map<String, List<String>> headers = const {},
  }) {
    return _AdapterResponse._(body, statusCode, false, headers);
  }

  final Object body;
  final int statusCode;
  final bool isJson;
  final Map<String, List<String>> headers;
}

class _SequenceAdapter implements HttpClientAdapter {
  _SequenceAdapter(this.responses);

  final List<_AdapterResponse> responses;
  final paths = <String>[];
  var _index = 0;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    paths.add(options.path);
    final response = responses[_index++];
    if (response.body is List<int>) {
      return ResponseBody.fromBytes(
        response.body as List<int>,
        response.statusCode,
        headers: response.headers,
      );
    }
    return ResponseBody.fromString(
      response.body as String,
      response.statusCode,
      headers: {
        ...response.headers,
        if (response.isJson) Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}
