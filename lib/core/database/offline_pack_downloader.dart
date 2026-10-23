import 'package:dio/dio.dart';

import '../../features/meters/data/models/meter.dart';
import '../../features/meters/domain/meters_repository.dart';
import '../../features/readings/domain/readings_repository.dart';
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
/// All remote calls still use the feature repositories, so the same Drift cache
/// is updated whether the user refreshes a single screen or requests a full pack.
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

    // Cache the current period/fee information as well. Limit concurrency to
    // avoid flooding the API when a square contains a large number of meters.
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
      squareCount: squares.length,
      subscriberCount: subscribers.length,
      meterCount: meters.length,
      readingCount: readings.length,
      cachedPeriodCount: cachedPeriods,
      completedAt: DateTime.now(),
    );
  }

  Future<void> _cacheReadingSettings() async {
    try {
      Response<dynamic> response;
      try {
        response = await _dio.get('/settings/reading');
      } on DioException catch (error) {
        if (error.response?.statusCode != 404) rethrow;
        response = await _dio.get('/settings');
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
