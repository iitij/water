import 'package:dio/dio.dart';

import '../../../../core/network/api_endpoints.dart';

class MetersRemoteDataSource {
  const MetersRemoteDataSource(this._dio);

  final Dio _dio;

  Future<List<Map<String, dynamic>>> fetchMeters({
    String query = '',
    int? squareId,
    String? status,
  }) async {
    final parameters = <String, dynamic>{
      if (query.trim().isNotEmpty) 'q': query.trim(),
      if (squareId != null) 'square_id': squareId,
      if (status != null && status.isNotEmpty) 'status': status,
    };

    Response<dynamic> response;
    try {
      response = await _dio.get(ApiEndpoints.meters, queryParameters: parameters);
    } on DioException catch (error) {
      // The current Laravel branch exposes `/meters/search`; the master API
      // contract adds `/meters`. Keep the module usable against either version.
      if (error.response?.statusCode != 404) rethrow;
      response = await _dio.get(
        ApiEndpoints.meterSearch,
        queryParameters: {
          if (query.trim().isNotEmpty) 'query': query.trim(),
          if (squareId != null) 'square_id': squareId,
        },
      );
    }

    return _extractList(response.data, keys: const ['meters', 'data']);
  }

  Future<List<Map<String, dynamic>>> searchMeters({
    required String query,
    int? squareId,
  }) async {
    final response = await _dio.get(
      ApiEndpoints.meterSearch,
      queryParameters: {
        if (query.trim().isNotEmpty) 'query': query.trim(),
        if (squareId != null) 'square_id': squareId,
      },
    );
    return _extractList(response.data, keys: const ['meters', 'data']);
  }

  Future<List<Map<String, dynamic>>> fetchSquares() async {
    final response = await _dio.get(ApiEndpoints.squares);
    return _extractList(response.data, keys: const ['squares', 'data']);
  }

  Future<List<Map<String, dynamic>>> fetchSubscribers() async {
    final response = await _dio.get(ApiEndpoints.subscribers);
    return _extractList(response.data, keys: const ['subscribers', 'data']);
  }

  Future<Map<String, dynamic>> fetchSubscriber(int subscriberId) async {
    final response = await _dio.get('${ApiEndpoints.subscribers}/$subscriberId');
    return _extractObject(response.data, keys: const ['subscriber', 'data']);
  }

  Future<Map<String, dynamic>> saveMeter(
    Map<String, dynamic> payload, {
    int? meterId,
  }) async {
    final response = meterId == null
        ? await _dio.post(ApiEndpoints.meters, data: payload)
        : await _dio.put('${ApiEndpoints.meters}/$meterId', data: payload);
    return _extractObject(response.data, keys: const ['meter', 'data']);
  }

  Future<void> deleteMeter(int meterId) async {
    await _dio.delete('${ApiEndpoints.meters}/$meterId');
  }

  Future<Map<String, dynamic>> bulkDeleteMeters(List<int> meterIds) async {
    final response = await _dio.post(
      ApiEndpoints.bulkDeleteMeters,
      data: {'ids': meterIds},
    );
    return _extractObject(response.data, keys: const []);
  }

  static List<Map<String, dynamic>> _extractList(
    Object? body, {
    required List<String> keys,
  }) {
    Object? value = body;
    if (value is Map) {
      final map = value;
      for (final key in keys) {
        final candidate = map[key];
        if (candidate is List) {
          value = candidate;
          break;
        }
      }
      if (value is Map && (value as Map)['data'] is List) {
        value = (value as Map)['data'];
      }
    }
    if (value is! List) return const [];
    return value
        .whereType<Map>()
        .map((row) => Map<String, dynamic>.from(row))
        .toList(growable: false);
  }

  static Map<String, dynamic> _extractObject(
    Object? body, {
    required List<String> keys,
  }) {
    if (body is Map) {
      for (final key in keys) {
        final value = body[key];
        if (value is Map) return Map<String, dynamic>.from(value);
      }
      return Map<String, dynamic>.from(body);
    }
    return const {};
  }
}
