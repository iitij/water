import 'package:dio/dio.dart';

import '../../../../core/database/app_database.dart';
import '../../../../core/utils/compound_search_parser.dart';
import '../../domain/meters_repository.dart';
import '../datasources/meters_remote_data_source.dart';
import '../models/meter.dart';

class MetersRepositoryImpl implements MetersRepository {
  MetersRepositoryImpl({
    required Dio dio,
    required AppDatabase database,
  })  : _database = database,
        _remote = MetersRemoteDataSource(dio);

  final AppDatabase _database;
  final MetersRemoteDataSource _remote;

  @override
  Future<List<Meter>> getMeters({
    String query = '',
    int? squareId,
    String? status,
  }) async {
    final searchQuery = CompoundSearchQuery.normalizeDigits(query.trim());
    try {
      final remoteRows = await _remote.fetchMeters(
        query: searchQuery,
        squareId: squareId,
        status: status,
      );
      final cachedRows = await _database.getCachedMeters();
      final cachedById = <int, Map<String, Object?>>{};
      for (final row in cachedRows) {
        final id = int.tryParse(row['server_id']?.toString() ?? '');
        if (id != null) cachedById[id] = row;
      }
      final rows = remoteRows.map((remote) {
        final id = _asInt(remote['id'] ?? remote['server_id']);
        final cached = id == null ? null : cachedById[id];
        final remoteLastReading = remote['last_reading'];
        final remoteReading = _asDouble(
          remote['last_reading_value'] ??
              (remoteLastReading is Map
                  ? remoteLastReading['current_reading']
                  : remoteLastReading),
        );
        final cachedReading = _asDouble(cached?['last_reading']);
        if (cachedReading != null &&
            (remoteReading == null || cachedReading > remoteReading)) {
          return <String, dynamic>{...remote, 'last_reading_value': cachedReading};
        }
        return remote;
      }).toList(growable: false);
      // Only an unfiltered full collection is authoritative. A search,
      // square or status filter must not evict unrelated cached meters.
      await _database.cacheMeters(
        rows,
        fullSnapshot: searchQuery.isEmpty && squareId == null &&
            (status == null || status.isEmpty),
      );
      return _normalizeAndFilter(
        rows.map(Meter.fromJson).toList(growable: false),
        query: searchQuery,
        squareId: squareId,
        status: status,
      );
    } on DioException catch (error) {
      if (!_isTransportFailure(error) && error.response?.statusCode != 404) rethrow;
      final parsedQuery = CompoundSearchQuery.parse(searchQuery);
      final cached = await _database.getCachedMeters(
        squareId: parsedQuery.isCompoundPathSearch ? parsedQuery.squareId : squareId,
        status: status,
        query: parsedQuery.isCompoundPathSearch ? null : searchQuery,
      );
      return _normalizeAndFilter(
        cached
            .map((row) => Meter.fromJson(Map<String, dynamic>.from(row)))
            .toList(growable: false),
        query: searchQuery,
        squareId: squareId,
        status: status,
      );
    }
  }

  @override
  Future<List<SquareOption>> getSquares() async {
    try {
      final rows = await _remote.fetchSquares();
      await _database.cacheSquares(rows);
      return rows.map(SquareOption.fromJson).toList(growable: false);
    } on DioException catch (error) {
      if (!_isTransportFailure(error) && error.response?.statusCode != 404) rethrow;
      final rows = await _database.getCachedSquares();
      return rows
          .map((row) => SquareOption.fromJson(Map<String, dynamic>.from(row)))
          .toList(growable: false);
    }
  }

  @override
  Future<List<SubscriberOption>> getSubscribers() async {
    List<Map<String, dynamic>> rows;
    try {
      rows = await _remote.fetchSubscribers();
      await _database.cacheSubscribers(rows);
    } on DioException catch (error) {
      if (!_isTransportFailure(error) && error.response?.statusCode != 404) rethrow;
      rows = (await _database.getCachedSubscribers())
          .map((row) => Map<String, dynamic>.from(row))
          .toList(growable: false);
    }

    // The legacy `/subscribers` API omits square_id from its summary rows.
    // Meters already carry subscriber + square relations, so use that pack to
    // enrich the selector and carry a current-meter identifier across reloads.
    List<Meter> meters;
    try {
      meters = (await _remote.fetchMeters())
          .map(Meter.fromJson)
          .toList(growable: false);
      await _database.cacheMeters(
        meters.map((meter) => meter.toJson()).toList(growable: false),
        fullSnapshot: true,
      );
    } on DioException catch (error) {
      if (!_isTransportFailure(error) && error.response?.statusCode != 404) rethrow;
      meters = (await _database.getCachedMeters())
          .map((row) => Meter.fromJson(Map<String, dynamic>.from(row)))
          .toList(growable: false);
    }
    final bySubscriber = <int, List<Meter>>{};
    for (final meter in meters) {
      final subscriberId = meter.subscriberId;
      if (subscriberId != null) {
        bySubscriber.putIfAbsent(subscriberId, () => <Meter>[]).add(meter);
      }
    }

    return rows.map((row) {
      final option = SubscriberOption.fromJson(row);
      final relatedMeters = bySubscriber[option.id] ?? const <Meter>[];
      final firstMeter = relatedMeters.isEmpty ? null : relatedMeters.first;
      return SubscriberOption(
        id: option.id,
        name: option.name,
        squareId: option.squareId ?? firstMeter?.squareId,
        squareName: option.squareName ?? firstMeter?.squareName,
        phone: option.phone,
        status: option.status,
        subscriptionStartDate: option.subscriptionStartDate ??
            firstMeter?.subscriptionStartDate,
        meterCount: option.currentMeterId != null
            ? 1
            : (relatedMeters.where((m) => m.removedAt == null).isNotEmpty ? 1 : 0),
        currentMeterId: option.currentMeterId ??
            relatedMeters.where((m) => m.removedAt == null).firstOrNull?.id,
      );
    }).where((subscriber) => subscriber.id > 0).toList(growable: false);
  }

  @override
  Future<SubscriberOption> getSubscriber(int subscriberId) async {
    try {
      final row = await _remote.fetchSubscriber(subscriberId);
      await _database.cacheSubscribers([row]);
      final option = SubscriberOption.fromJson(row);
      if (option.squareId != null) return option;
      final relatedMeters = row['meters'];
      if (relatedMeters is List && relatedMeters.isNotEmpty) {
        final meter = Meter.fromJson(Map<String, dynamic>.from(relatedMeters.first as Map));
        return SubscriberOption(
          id: option.id,
          name: option.name,
          squareId: meter.squareId,
          squareName: meter.squareName,
          phone: option.phone,
          status: option.status,
          subscriptionStartDate: option.subscriptionStartDate,
          meterCount: option.currentMeterId != null ||
                  _currentMeterIdFromJson(relatedMeters) != null ? 1 : 0,
          currentMeterId: option.currentMeterId ??
              _currentMeterIdFromJson(relatedMeters),
        );
      }
      return option;
    } on DioException catch (error) {
      if (!_isTransportFailure(error) && error.response?.statusCode != 404) rethrow;
      final cached = await _database.getCachedSubscribers();
      for (final row in cached) {
        if (int.tryParse(row['server_id']?.toString() ?? '') == subscriberId) {
          return SubscriberOption.fromJson(Map<String, dynamic>.from(row));
        }
      }
      rethrow;
    }
  }

  @override
  Future<Meter> saveMeter(MeterDraft draft, {int? meterId}) async {
    final response = await _remote.saveMeter(draft.toJson(), meterId: meterId);
    final meter = Meter.fromJson(response);
    if (meter.id > 0) await _database.cacheMeters([response]);
    return meter;
  }

  @override
  Future<Meter> replaceMeter({
    required int meterId,
    required String uniqueCode,
    required String reason,
    required String removedAt,
    required int initialReading,
    int? pathNumber,
  }) async {
    final result = await _remote.replaceMeter(meterId, {
      'removed_at': removedAt,
      'reason': reason,
      'new_meter': {
        'unique_code': uniqueCode.trim(),
        'path_number': pathNumber,
        'initial_reading': initialReading,
      },
    });
    final current = result['current_meter'];
    if (current is! Map) {
      throw StateError('Server did not confirm replacement meter.');
    }
    final currentJson = Map<String, dynamic>.from(current);
    // The old meter no longer exists physically; do not keep its cached
    // record, readings or billing period as a hidden historical meter.
    await _database.evictMeterCache(meterId);
    await _database.cacheMeters([currentJson]);
    return Meter.fromJson(currentJson);
  }

  @override
  Future<void> deleteMeter(int meterId) async {
    await _remote.deleteMeter(meterId);
    await _database.evictMeterCache(meterId);
  }

  @override
  Future<({int deletedCount, int skippedCount})> bulkDeleteMeters(List<int> meterIds) async {
    if (meterIds.isEmpty) return (deletedCount: 0, skippedCount: 0);
    final res = await _remote.bulkDeleteMeters(meterIds);
    final skippedCount = _asInt(res['skipped_count']) ?? 0;
    final deletedCount = _asInt(res['deleted_count']) ?? 0;
    final skippedIdsRaw = res['skipped_ids'];
    final skippedIds = skippedIdsRaw is List
        ? skippedIdsRaw.map(_asInt).whereType<int>().toSet()
        : null;
    // Never evict a record if the server may have rejected its removal.
    if (skippedCount == 0 && deletedCount == meterIds.length) {
      for (final id in meterIds) {
        await _database.evictMeterCache(id);
      }
    } else if (skippedIds != null &&
        deletedCount + skippedCount == meterIds.length) {
      for (final id in meterIds) {
        if (!skippedIds.contains(id)) {
          await _database.evictMeterCache(id);
        }
      }
    }
    return (deletedCount: deletedCount, skippedCount: skippedCount);
  }

  @override
  Future<bool> isPathNumberAvailable({
    required int pathNumber,
    required int squareId,
    int? ignoreMeterId,
  }) async {
    final meters = await getMeters(squareId: squareId);
    return !meters.any((meter) =>
        meter.pathNumber == pathNumber && meter.id != ignoreMeterId);
  }

  @override
  Future<int> meterCountForSubscriber(int subscriberId) async {
    final meters = await getMeters();
    return meters.where((meter) => meter.subscriberId == subscriberId).length;
  }

  List<Meter> _normalizeAndFilter(
    List<Meter> source, {
    required String query,
    int? squareId,
    String? status,
  }) {
    final parsed = CompoundSearchQuery.parse(query);
    final filtered = source.where((meter) {
      // An offline cache can contain the retired record after replacement;
      // only current meters belong to operational reader/billing lists.
      if (!meter.isCurrent) return false;
      if (squareId != null && meter.squareId != squareId) return false;
      if (status != null && status.isNotEmpty && meter.status != status) return false;
      if (parsed.isCompoundPathSearch) {
        return meter.squareId == parsed.squareId &&
            meter.pathNumber == parsed.pathNumber;
      }
      if (parsed.rawQuery.isEmpty) return true;
      final needle = parsed.rawQuery.toLowerCase();
      return meter.uniqueCode.toLowerCase().contains(needle) ||
          meter.pathNumber?.toString().contains(needle) == true ||
          meter.subscriberName?.toLowerCase().contains(needle) == true ||
          meter.subscriberId?.toString().contains(needle) == true;
    }).map((meter) {
      final pathMatch = parsed.isCompoundPathSearch
          ? meter.squareId == parsed.squareId && meter.pathNumber == parsed.pathNumber
          : parsed.rawQuery.isNotEmpty &&
              meter.pathNumber?.toString() == parsed.rawQuery;
      return meter.copyWith(isPathMatch: pathMatch);
    }).toList();

    filtered.sort((left, right) {
      if (left.isPathMatch != right.isPathMatch) {
        return left.isPathMatch ? -1 : 1;
      }
      final leftPath = left.pathNumber ?? 1 << 30;
      final rightPath = right.pathNumber ?? 1 << 30;
      final byPath = leftPath.compareTo(rightPath);
      if (byPath != 0) return byPath;
      return left.uniqueCode.toLowerCase().compareTo(right.uniqueCode.toLowerCase());
    });
    return filtered;
  }

  // Only the assigned physical meter is valid; ignore stale cached removals.
  int? _currentMeterIdFromJson(List<dynamic> rows) {
    for (final value in rows) {
      if (value is! Map) continue;
      if (value['removed_at'] == null && value['deleted_at'] == null) {
        return _asInt(value['id'] ?? value['server_id']);
      }
    }
    return null;
  }

  int? _asInt(Object? value) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse(value?.toString() ?? '');
  }

  double? _asDouble(Object? value) {
    if (value is num) return value.toDouble();
    return double.tryParse(value?.toString() ?? '');
  }

  bool _isTransportFailure(DioException error) =>
      error.response == null &&
      (error.type == DioExceptionType.connectionTimeout ||
          error.type == DioExceptionType.sendTimeout ||
          error.type == DioExceptionType.receiveTimeout ||
          error.type == DioExceptionType.connectionError);
}
