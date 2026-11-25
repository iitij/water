import 'dart:io';

import 'package:dio/dio.dart';

import '../../../../core/network/api_endpoints.dart';
import '../../../../core/network/paginated_collection.dart';
import '../models/reading.dart';

class ReadingsRemoteDataSource {
  const ReadingsRemoteDataSource(this._dio);

  final Dio _dio;

  Future<Map<String, dynamic>> fetchMeterPeriod(int meterId) async {
    try {
      final response = await _dio.get<dynamic>(
        ApiEndpoints.meterPeriod(meterId),
      );
      return _asMap(response.data);
    } on DioException catch (error) {
      if (error.response?.statusCode != 404) rethrow;
      final response = await _dio.get<dynamic>(
        ApiEndpoints.readingCalculateDates,
        queryParameters: {'meter_id': meterId},
      );
      return _asMap(response.data);
    }
  }

  Future<List<Map<String, dynamic>>> fetchReadings({
    bool? isInvoiced,
    int? squareId,
    int? meterId,
    DateTime? fromDate,
    DateTime? toDate,
    String? query,
  }) async {
    final collection = await collectPaginatedCollection((page) async {
      final response = await _dio.get<dynamic>(
        ApiEndpoints.readings,
        queryParameters: {
          if (isInvoiced != null) 'is_invoiced': isInvoiced ? 1 : 0,
          if (squareId != null) 'square_id': squareId,
          if (meterId != null) 'meter_id': meterId,
          if (fromDate != null) 'from_date': _dateOnly(fromDate),
          if (toDate != null) 'to_date': _dateOnly(toDate),
          if (query != null && query.trim().isNotEmpty) 'q': query.trim(),
          'per_page': 100,
          'page': page,
        },
      );
      return response.data;
    }, collectionKeys: const ['readings']);
    return _asList(collection, keys: const ['data']);
  }

  Future<Map<String, dynamic>> createReading(
    ReadingSubmission submission, {
    required String clientActionId,
    Map<String, dynamic>? sessionExtra,
  }) async {
    final payload = submission.toJson(localId: clientActionId)
      ..['local_id'] = clientActionId;
    final imagePath = submission.imagePath;
    final Object body;
    if (imagePath != null &&
        imagePath.isNotEmpty &&
        await File(imagePath).exists()) {
      body = FormData.fromMap({
        ...payload,
        'image': await MultipartFile.fromFile(
          imagePath,
          filename: imagePath.split(Platform.pathSeparator).last,
        ),
      });
    } else {
      body = payload;
    }
    final response = await _dio.post<dynamic>(
      ApiEndpoints.readings,
      data: body,
      options: Options(headers: {'Idempotency-Key': clientActionId}, extra: sessionExtra),
    );
    return _asMap(response.data);
  }

  Future<Map<String, dynamic>> updateReading(
    int readingId,
    Map<String, dynamic> payload,
  ) async {
    final response = await _dio.put<dynamic>(
      ApiEndpoints.reading(readingId),
      data: payload,
    );
    return _asMap(response.data);
  }

  Future<void> deleteReading(int readingId) async {
    await _dio.delete<dynamic>(ApiEndpoints.reading(readingId));
  }

  Future<Map<String, dynamic>> bulkIssueInvoices(List<int> readingIds) async {
    try {
      final response = await _dio.post<dynamic>(
        ApiEndpoints.readingBulkInvoice,
        data: {'ids': readingIds},
      );
      return _asMap(response.data);
    } on DioException catch (error) {
      if (error.response?.statusCode != 404) rethrow;
      final response = await _dio.post<dynamic>(
        ApiEndpoints.readingBulkIssueInvoices,
        data: {'ids': readingIds},
      );
      return _asMap(response.data);
    }
  }

  Future<Map<String, dynamic>> bulkDelete(List<int> readingIds) async {
    final response = await _dio.post<dynamic>(
      ApiEndpoints.readingBulkDelete,
      data: {'ids': readingIds},
    );
    return _asMap(response.data);
  }

  Future<Map<String, dynamic>> syncBatch(
    List<Map<String, dynamic>> readings, {
    required String batchId,
  }) async {
    final response = await _dio.post<dynamic>(
      ApiEndpoints.readingSyncBatch,
      data: {'readings': readings},
      options: Options(
        headers: {'Idempotency-Key': batchId},
        sendTimeout: const Duration(seconds: 45),
        receiveTimeout: const Duration(seconds: 45),
      ),
    );
    return _asMap(response.data);
  }

  static List<Map<String, dynamic>> _asList(
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

  static Map<String, dynamic> _asMap(Object? value) {
    if (value is Map<String, dynamic>) return value;
    if (value is Map) return Map<String, dynamic>.from(value);
    return const {};
  }

  static String _dateOnly(DateTime value) =>
      '${value.year.toString().padLeft(4, '0')}-'
      '${value.month.toString().padLeft(2, '0')}-'
      '${value.day.toString().padLeft(2, '0')}';
}
