import 'package:dio/dio.dart';

import '../../../../core/network/api_endpoints.dart';
import '../../../../core/network/paginated_collection.dart';

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

    final collection = await collectPaginatedCollection((page) async {
      Response<dynamic> response;
      try {
        response = await _dio.get<dynamic>(
          ApiEndpoints.meters,
          queryParameters: {...parameters, 'per_page': 100, 'page': page},
        );
      } on DioException catch (error) {
        // The current Laravel branch exposes `/meters/search`; the master API
        // contract adds `/meters`. Keep the module usable against either version.
        if (error.response?.statusCode != 404) rethrow;
        response = await _dio.get<dynamic>(
          ApiEndpoints.meterSearch,
          queryParameters: {
            ...parameters,
            if (query.trim().isNotEmpty) 'query': query.trim(),
            'per_page': 100,
            'page': page,
          },
        );
      }
      return response.data;
    }, collectionKeys: const ['meters']);
    return _extractList(collection, keys: const ['data']);
  }

  Future<List<Map<String, dynamic>>> searchMeters({
    required String query,
    int? squareId,
  }) async {
    final collection = await collectPaginatedCollection((page) async {
      final response = await _dio.get<dynamic>(
        ApiEndpoints.meterSearch,
        queryParameters: {
          if (query.trim().isNotEmpty) 'query': query.trim(),
          if (squareId != null) 'square_id': squareId,
          'per_page': 100,
          'page': page,
        },
      );
      return response.data;
    }, collectionKeys: const ['meters']);
    return _extractList(collection, keys: const ['data']);
  }

  Future<List<Map<String, dynamic>>> fetchSquares() async {
    final collection = await collectPaginatedCollection(
      (page) async => (await _dio.get<dynamic>(
        ApiEndpoints.squares,
        queryParameters: {'per_page': 100, 'page': page},
      )).data,
      collectionKeys: const ['squares'],
    );
    return _extractList(collection, keys: const ['data']);
  }

  Future<List<Map<String, dynamic>>> fetchSubscribers() async {
    final collection = await collectPaginatedCollection(
      (page) async => (await _dio.get<dynamic>(
        ApiEndpoints.subscribers,
        queryParameters: {'per_page': 100, 'page': page},
      )).data,
      collectionKeys: const ['subscribers'],
    );
    return _extractList(collection, keys: const ['data']);
  }

  Future<Map<String, dynamic>> fetchSubscriber(int subscriberId) async {
    final response = await _dio.get<dynamic>(
      '${ApiEndpoints.subscribers}/$subscriberId',
    );
    return _extractObject(response.data, keys: const ['subscriber', 'data']);
  }

  Future<Map<String, dynamic>> saveMeter(
    Map<String, dynamic> payload, {
    int? meterId,
  }) async {
    final response = meterId == null
        ? await _dio.post<dynamic>(ApiEndpoints.meters, data: payload)
        : await _dio.put<dynamic>(
            '${ApiEndpoints.meters}/$meterId',
            data: payload,
          );
    return _extractObject(response.data, keys: const ['meter', 'data']);
  }

  Future<Map<String, dynamic>> replaceMeter(
    int meterId,
    Map<String, dynamic> payload,
  ) async {
    final response = await _dio.post<dynamic>(
      '${ApiEndpoints.meters}/$meterId/replace',
      data: payload,
    );
    return _extractObject(response.data, keys: const []);
  }

  Future<void> deleteMeter(int meterId) async {
    await _dio.delete<dynamic>('${ApiEndpoints.meters}/$meterId');
  }

  Future<Map<String, dynamic>> bulkDeleteMeters(List<int> meterIds) async {
    final response = await _dio.post<dynamic>(
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
      if (value is Map && (value)['data'] is List) {
        value = (value)['data'];
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
