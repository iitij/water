import 'dart:convert';

import 'package:dio/dio.dart';

import 'auth_interceptor.dart';

/// In-memory fallback for explicitly opted-in, authenticated GETs only.
/// Session generation and clear epoch fence both writes and fallback reads.
class OfflineCacheInterceptor extends Interceptor {
  OfflineCacheInterceptor({int Function()? currentGeneration})
      : _currentGeneration = currentGeneration;

  final int Function()? _currentGeneration;
  final Map<String, _CacheEntry> _entries = <String, _CacheEntry>{};
  static const _epochKey = 'waterOfflineCacheEpoch';
  int _epoch = 0;

  void clear() {
    _epoch++;
    _entries.clear();
  }

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    options.extra[_epochKey] = _epoch;
    handler.next(options);
  }

  @override
  void onResponse(Response<dynamic> response, ResponseInterceptorHandler handler) {
    final key = _cacheKey(response.requestOptions);
    final status = response.statusCode ?? 0;
    if (key != null && status >= 200 && status < 300 &&
        (response.data is Map || response.data is List || response.data == null)) {
      try {
        // Store independent JSON bytes, never a mutable UI-owned reference.
        _entries[key] = _CacheEntry(
          bytes: jsonEncode(response.data),
          statusCode: status,
          storedAt: DateTime.now(),
        );
        if (_entries.length > 120) {
          final oldestKey = _entries.entries
              .reduce((a, b) => a.value.storedAt.isBefore(b.value.storedAt) ? a : b)
              .key;
          _entries.remove(oldestKey);
        }
      } on Object {
        // Non-JSON data is not cacheable; preserve the original response.
      }
    }
    handler.next(response);
  }

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    final key = _cacheKey(err.requestOptions);
    final entry = key == null ? null : _entries[key];
    final maxStale = err.requestOptions.extra['offlineMaxStale'] as Duration? ??
        const Duration(days: 7);
    final networkFailure = err.response == null && <DioExceptionType>{
      DioExceptionType.connectionError,
      DioExceptionType.connectionTimeout,
      DioExceptionType.receiveTimeout,
      DioExceptionType.sendTimeout,
      DioExceptionType.unknown,
    }.contains(err.type);

    if (entry != null && networkFailure &&
        DateTime.now().difference(entry.storedAt) <= maxStale) {
      handler.resolve(Response<dynamic>(
        requestOptions: err.requestOptions,
        statusCode: entry.statusCode,
        data: jsonDecode(entry.bytes),
        extra: <String, dynamic>{'offlineCacheHit': true},
      ));
      return;
    }
    handler.next(err);
  }

  String? _cacheKey(RequestOptions options) {
    if (options.method.toUpperCase() != 'GET' ||
        options.extra['offlineCache'] != true ||
        options.extra['skipOfflineCache'] == true ||
        options.extra['skipAuth'] == true ||
        options.extra[_epochKey] != _epoch) return null;
    final generation = options.extra[AuthInterceptor.sessionGenerationKey];
    final current = _currentGeneration?.call();
    final auth = options.headers.entries
        .where((entry) => entry.key.toLowerCase() == 'authorization')
        .map((entry) => entry.value).firstOrNull;
    if (current == null || generation != current ||
        auth is! String || !auth.startsWith('Bearer ') || auth.length <= 7) {
      return null;
    }
    // URI is already escaped; encoding a tuple avoids query concatenation collisions.
    // Never include tokens in cache keys.
    return jsonEncode([current, _epoch, options.uri.toString()]);
  }
}

class _CacheEntry {
  const _CacheEntry({
    required this.bytes,
    required this.statusCode,
    required this.storedAt,
  });

  final String bytes;
  final int statusCode;
  final DateTime storedAt;
}
