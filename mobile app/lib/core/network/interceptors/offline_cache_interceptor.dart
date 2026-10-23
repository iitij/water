import 'package:dio/dio.dart';

/// Small, opt-in stale-while-offline cache for safe GET responses.
/// Long-lived field data belongs in the shared Drift database; this interceptor
/// only prevents a transient connection loss from blanking cached read-only UI.
class OfflineCacheInterceptor extends Interceptor {
  final Map<String, _CacheEntry> _entries = <String, _CacheEntry>{};

  void clear() => _entries.clear();

  @override
  void onResponse(Response<dynamic> response, ResponseInterceptorHandler handler) {
    final key = _cacheKey(response.requestOptions);
    final status = response.statusCode ?? 0;
    if (key != null && status >= 200 && status < 300 && _isJsonValue(response.data)) {
      _entries[key] = _CacheEntry(
        data: response.data,
        statusCode: status,
        storedAt: DateTime.now(),
      );
      if (_entries.length > 120) {
        final oldestKey = _entries.entries
            .reduce((a, b) => a.value.storedAt.isBefore(b.value.storedAt) ? a : b)
            .key;
        _entries.remove(oldestKey);
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
    final networkFailure = <DioExceptionType>{
      DioExceptionType.connectionError,
      DioExceptionType.connectionTimeout,
      DioExceptionType.receiveTimeout,
      DioExceptionType.sendTimeout,
      DioExceptionType.unknown,
    }.contains(err.type);

    if (entry != null && networkFailure && DateTime.now().difference(entry.storedAt) <= maxStale) {
      handler.resolve(
        Response<dynamic>(
          requestOptions: err.requestOptions,
          statusCode: entry.statusCode,
          data: entry.data,
          extra: <String, dynamic>{'offlineCacheHit': true},
        ),
      );
      return;
    }
    handler.next(err);
  }

  String? _cacheKey(RequestOptions options) {
    if (options.method.toUpperCase() != 'GET' || options.extra['offlineCache'] != true) {
      return null;
    }
    final query = options.queryParameters.entries.toList()
      ..sort((a, b) => a.key.compareTo(b.key));
    final normalizedQuery = query.map((entry) => '${entry.key}=${entry.value}').join('&');
    return '${options.uri.origin}${options.uri.path}?$normalizedQuery';
  }

  bool _isJsonValue(dynamic value) => value is Map || value is List || value == null;
}

class _CacheEntry {
  const _CacheEntry({
    required this.data,
    required this.statusCode,
    required this.storedAt,
  });

  final dynamic data;
  final int statusCode;
  final DateTime storedAt;
}
