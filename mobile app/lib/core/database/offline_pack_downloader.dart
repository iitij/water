import 'package:dio/dio.dart';

import '../../features/meters/data/models/meter.dart';
import '../../features/meters/domain/meters_repository.dart';
import '../../features/readings/domain/readings_repository.dart';
import '../network/api_endpoints.dart';
import 'app_database.dart';

class OfflinePackResult {
  const OfflinePackResult({
    required this.squareCount,
    required this.subscriberCount,
    required this.meterCount,
    required this.readingCount,
    required this.cachedPeriodCount,
    required this.completedAt,
  });

  final int squareCount;
  final int subscriberCount;
  final int meterCount;
  final int readingCount;
  final int cachedPeriodCount;
  final DateTime completedAt;
}

typedef OfflinePackProgress = void Function(String stage, double progress);

/// Downloads the field team's working set before entering a low-connectivity area.
/// Uses `GET squares/{id}/offline-pack` when a specific square is requested, with
/// automatic fallback to repository-level downloads across all squares.
class OfflinePackDownloader {
  const OfflinePackDownloader({
    required MetersRepository metersRepository,
    required ReadingsRepository readingsRepository,
    required AppDatabase database,
    required Dio dio,
  })  : _metersRepository = metersRepository,
        _readingsRepository = readingsRepository,
        _database = database,
        _dio = dio;

  final MetersRepository _metersRepository;
  final ReadingsRepository _readingsRepository;
  final AppDatabase _database;
  final Dio _dio;

  Future<OfflinePackResult> download({
    int? squareId,
    OfflinePackProgress? onProgress,
  }) async {
    if (squareId != null) {
      final fastPack = await _trySingleSquarePack(squareId, onProgress: onProgress);
      if (fastPack != null) return fastPack;
    }

    onProgress?.call('جلب المربعات والمشتركين', .05);
    final results = await Future.wait<Object>([
      _metersRepository.getSquares(),
      _metersRepository.getSubscribers(),
      _metersRepository.getMeters(squareId: squareId),
    ]);
    final squares = results[0] as List<SquareOption>;
    final subscribers = results[1] as List<SubscriberOption>;
    final meters = results[2] as List<Meter>;

    onProgress?.call('جلب سجل القراءات', .35);
    final readings = await _readingsRepository.getReadings(squareId: squareId);

    onProgress?.call('حفظ إعدادات دورة القراءة', .55);
    await _cacheReadingSettings();

    final eligibleMeters = meters.where((meter) => meter.isActive).toList(growable: false);
    var cachedPeriods = 0;
    const chunkSize = 6;
    for (var offset = 0; offset < eligibleMeters.length; offset += chunkSize) {
      final end = (offset + chunkSize < eligibleMeters.length)
          ? offset + chunkSize
          : eligibleMeters.length;
      final chunk = eligibleMeters.sublist(offset, end);
      final periodResults = await Future.wait<bool>(
        chunk.map((meter) async {
          try {
            await _readingsRepository.getMeterPeriod(meter);
            return true;
          } on DioException {
            return false;
          } catch (_) {
            return false;
          }
        }),
      );
      cachedPeriods += periodResults.where((result) => result).length;
      final progress = eligibleMeters.isEmpty
          ? .9
          : .6 + .3 * (end / eligibleMeters.length);
      onProgress?.call('تخزين فترات العدادات', progress);
    }

    onProgress?.call('اكتمل تنزيل حزمة العمل الأوفلاين', 1);
    return OfflinePackResult(
      squareCount: squareId != null ? 1 : squares.length,
      subscriberCount: squareId != null
          ? subscribers.where((s) => s.squareId == squareId).length
          : subscribers.length,
      meterCount: meters.length,
      readingCount: readings.length,
      cachedPeriodCount: cachedPeriods,
      completedAt: DateTime.now(),
    );
  }

  Future<OfflinePackResult?> _trySingleSquarePack(
    int squareId, {
    OfflinePackProgress? onProgress,
  }) async {
    try {
      onProgress?.call('جلب حزمة المربع الأوفلاين من الخادم', .15);
      final response = await _dio.get<dynamic>(ApiEndpoints.squareOfflinePack(squareId));
      final body = response.data;
      if (body is! Map) return null;

      onProgress?.call('تخزين بيانات المربع والمشتركين محلياً', .45);
      final squareMap = body['square'] is Map ? Map<String, dynamic>.from(body['square'] as Map) : null;
      if (squareMap != null) {
        await _database.cacheSquares([squareMap]);
      }

      final subscribers = _mapList(body['subscribers']);
      if (subscribers.isNotEmpty) {
        await _database.cacheSubscribers(subscribers);
      }

      onProgress?.call('تخزين العدادات وفترات القراءة', .70);
      final meters = _mapList(body['meters']);
      if (meters.isNotEmpty) {
        await _database.cacheMeters(meters);
      }

      var cachedPeriods = 0;
      for (final meterRow in meters) {
        final meterId = int.tryParse('${meterRow['id'] ?? ''}');
        final rawPeriod = meterRow['period'];
        if (meterId != null && rawPeriod is Map) {
          final periodMap = Map<String, dynamic>.from(rawPeriod);
          periodMap['last_reading'] ??= meterRow['last_reading'] ?? meterRow['initial_reading'] ?? 0;
          await _database.cacheMeterPeriod(meterId, periodMap);
          cachedPeriods++;
        }
      }

      onProgress?.call('تخزين سجل القراءات وإعدادات الدورة', .88);
      final readings = _mapList(body['readings']);
      if (readings.isNotEmpty) {
        await _database.cacheReadings(readings);
      }

      final settings = body['reading_settings'];
      if (settings is Map) {
        for (final entry in settings.entries) {
          if (entry.value != null) {
            await _database.setCachedSetting(entry.key.toString(), entry.value);
          }
        }
      }

      onProgress?.call('اكتمل تنزيل حزمة العمل الأوفلاين', 1);
      return OfflinePackResult(
        squareCount: 1,
        subscriberCount: subscribers.length,
        meterCount: meters.length,
        readingCount: readings.length,
        cachedPeriodCount: cachedPeriods,
        completedAt: DateTime.now(),
      );
    } on DioException catch (error) {
      if (error.response?.statusCode == 404) return null;
      rethrow;
    }
  }

  List<Map<String, dynamic>> _mapList(Object? raw) {
    if (raw is! List) return const [];
    return raw.whereType<Map>().map((e) => Map<String, dynamic>.from(e)).toList(growable: false);
  }

  Future<void> _cacheReadingSettings() async {
    try {
      Response<dynamic> response;
      try {
        response = await _dio.get<dynamic>(ApiEndpoints.readingSettings);
      } on DioException catch (error) {
        if (error.response?.statusCode != 404) rethrow;
        response = await _dio.get<dynamic>(ApiEndpoints.settings);
      }
      final body = response.data;
      if (body is Map) {
        for (final key in const [
          'reading_period',
          'reading_day',
          'auto_issue_invoice_on_reading',
          'grace_period',
          'strict_overdue_on_new_invoice',
        ]) {
          final value = body[key];
          if (value != null) await _database.setCachedSetting(key, value);
        }
        final settings = body['settings'];
        if (settings is List) await _cacheSettingRows(settings);
        final data = body['data'];
        if (data is Map) {
          for (final entry in data.entries) {
            if (entry.value != null) {
              await _database.setCachedSetting(entry.key.toString(), entry.value);
            }
          }
        }
      } else if (body is List) {
        await _cacheSettingRows(body);
      }
    } on DioException {
      // Previously cached values remain available for a partially offline pack.
    }
  }

  Future<void> _cacheSettingRows(List<dynamic> rows) async {
    for (final item in rows) {
      if (item is! Map) continue;
      final key = item['key']?.toString();
      final value = item['value'];
      if (key != null && value != null) await _database.setCachedSetting(key, value);
    }
  }
}
