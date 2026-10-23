import 'dart:typed_data';

import 'package:water_project/features/admin_shared/data/admin_api_client.dart';

class RecordedRequest {
  const RecordedRequest(this.method, this.path, this.data, this.query);

  final String method;
  final String path;
  final dynamic data;
  final Map<String, dynamic>? query;
}

class FakeAdminApiClient implements AdminApiClient {
  final handlers = <String, dynamic>{};
  final downloads = <String, AdminDownload>{};
  final requests = <RecordedRequest>[];

  @override
  Future<dynamic> get(
    String path, {
    Map<String, dynamic>? query,
    String? fallbackPath,
  }) {
    return _handle('GET', path, null, query, fallbackPath);
  }

  @override
  Future<dynamic> post(
    String path, {
    dynamic data,
    Map<String, dynamic>? query,
    String? fallbackPath,
  }) {
    return _handle('POST', path, data, query, fallbackPath);
  }

  @override
  Future<dynamic> put(
    String path, {
    dynamic data,
    Map<String, dynamic>? query,
  }) {
    return _handle('PUT', path, data, query, null);
  }

  @override
  Future<dynamic> delete(String path, {dynamic data}) {
    return _handle('DELETE', path, data, null, null);
  }

  @override
  Future<dynamic> upload(
    String path, {
    required String field,
    required Uint8List bytes,
    required String fileName,
    Map<String, dynamic> fields = const {},
    String? fallbackPath,
  }) {
    return _handle('UPLOAD', path, {
      'field': field,
      'bytes': bytes,
      'fileName': fileName,
      'fields': fields,
    }, null, fallbackPath);
  }

  @override
  Future<AdminDownload> download(
    String path, {
    Map<String, dynamic>? query,
    String? fallbackPath,
  }) async {
    requests.add(RecordedRequest('DOWNLOAD', path, null, query));
    final download = downloads[path];
    if (download != null) return download;
    final error = handlers['DOWNLOAD:$path'];
    if (error is Exception) throw error;
    if (fallbackPath != null) return download(fallbackPath, query: query);
    throw AdminApiException('No fake download for $path');
  }

  Future<dynamic> _handle(
    String method,
    String path,
    dynamic data,
    Map<String, dynamic>? query,
    String? fallbackPath,
  ) async {
    requests.add(RecordedRequest(method, path, data, query));
    final result = handlers['$method:$path'];
    if (result is Exception) {
      if (result is AdminApiException && result.isMissingEndpoint && fallbackPath != null) {
        return _handle(method, fallbackPath, data, query, null);
      }
      throw result;
    }
    if (result != null) return result;
    if (fallbackPath != null && handlers.containsKey('$method:$fallbackPath')) {
      return _handle(method, fallbackPath, data, query, null);
    }
    return <String, dynamic>{};
  }
}
