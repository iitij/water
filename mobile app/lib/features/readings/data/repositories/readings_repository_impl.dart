import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:uuid/uuid.dart';

import '../../../../core/database/app_database.dart';
import '../../../../core/utils/date_period_calculator.dart';
import '../../../meters/data/models/meter.dart';
import '../../domain/readings_repository.dart';
import '../datasources/readings_remote_data_source.dart';
import '../models/reading.dart';

class ReadingsRepositoryImpl implements ReadingsRepository {
  ReadingsRepositoryImpl({
    required Dio dio,
    required AppDatabase database,
    Uuid? uuid,
    String? Function()? ownerUserIdProvider,
  })  : _database = database,
        _remote = ReadingsRemoteDataSource(dio),
        _uuid = uuid ?? const Uuid(),
        _ownerUserIdProvider = ownerUserIdProvider;

  final AppDatabase _database;
  final ReadingsRemoteDataSource _remote;
  final Uuid _uuid;
  final String? Function()? _ownerUserIdProvider;

  @override
  Future<MeterPeriodResponse> getMeterPeriod(Meter meter) async {
    if (!meter.isCurrent) {
      throw StateError('هذا العداد منزوع أو مستبدل؛ لا يمكن تسجيل قراءة جديدة عليه.');
    }
    try {
      final json = await _remote.fetchMeterPeriod(meter.id);
      final period = MeterPeriodResponse.fromJson(json);
      await _database.cacheMeterPeriod(meter.id, json);
      return period;
    } on DioException catch (error) {
      if (!_isTransportFailure(error) && error.response?.statusCode != 404) rethrow;
      return _calculateOfflinePeriod(meter);
    }
  }

  Future<MeterPeriodResponse> _calculateOfflinePeriod(Meter meter) async {
    final cachedPeriod = await _database.getCachedMeterPeriod(meter.id);
    final latest = await _database.getLatestCachedReading(meter.id);
    final autoSetting = await _database.getCachedSetting('auto_issue_invoice_on_reading');
    final cachedMeter = await _database.getCachedMeter(meter.id);

    final latestDate = _parseDate(
      latest?['actual_reading_date'] ?? latest?['reading_date'],
    );
    final cachedPeriodStart = _parseDate(cachedPeriod?['period_start']);
    final lastReading = _asDouble(latest?['current_reading']) ??
        _asDouble(cachedPeriod?['last_reading']) ??
        _asDouble(cachedMeter?['last_reading']) ??
        meter.lastReading;

    final result = DatePeriodCalculator.calculate(
      lastReadingDate: latestDate ??
          (latest == null ? cachedPeriodStart : null),
      installedAt: meter.installedAt ??
          _parseDate(cachedMeter?['installed_at']),
      subscriptionStartDate: meter.subscriptionStartDate ??
          _parseDate(cachedMeter?['subscription_start_date']),
      now: DateTime.now(),
    );

    final cachedAuto = cachedPeriod?['auto_issue_invoice'];
    final autoIssue = cachedAuto != null
        ? _truthy(cachedAuto)
        : autoSetting == '1' || autoSetting?.toLowerCase() == 'true';

    return MeterPeriodResponse(
      lastReading: lastReading,
      periodStart: result.periodStart,
      periodEnd: result.periodEnd,
      autoIssueInvoice: autoIssue,
      isOfflineEstimate: true,
    );
  }

  @override
  Future<ReadingSaveResult> submitReading(ReadingSubmission submission) async {
    // A stale or removed meter must never be queued for new readings.
    final cachedMeter = await _database.getCachedMeter(submission.meterId);
    if (cachedMeter != null && cachedMeter['removed_at'] != null) {
      throw StateError('هذا العداد منزوع أو مستبدل؛ لا يمكن تسجيل قراءة جديدة عليه.');
    }
    if (submission.currentReading < submission.previousReading) {
      throw StateError(
        'القراءة الحالية يجب أن تكون أكبر من أو تساوي القراءة السابقة '
        '(${submission.previousReading}).',
      );
    }
    if (await _database.hasCachedReadingPeriod(
      meterId: submission.meterId,
      periodStart: submission.periodStart,
      periodEnd: submission.periodEnd,
    )) {
      throw StateError('تم تسجيل قراءة لهذا العداد خلال الفترة نفسها مسبقاً.');
    }

    final localId = _uuid.v4();
    try {
      final response = await _remote.createReading(
        submission,
        clientActionId: localId,
      );
      final readingJson = _asMap(response['reading']) ?? <String, dynamic>{};
      final id = _asInt(readingJson['id'] ?? response['reading_id']);
      if (id == null) {
        return await _queueOfflineSubmission(
          localId,
          submission,
          message: 'لم يُرجع الخادم رقم القراءة؛ حُفظ الطلب للمزامنة الآمنة.',
        );
      }
      final enrichedReading = <String, dynamic>{
        ...readingJson,
        'id': id,
        'local_id': readingJson['local_id'] ?? readingJson['client_action_id'] ?? localId,
        'meter_id': submission.meterId,
        'reading_date': readingJson['reading_date'] ?? _dateOnly(submission.readingDate),
        'actual_reading_date': readingJson['actual_reading_date'] ?? _dateOnly(submission.readingDate),
        'period_start': readingJson['period_start'] ?? _dateOnly(submission.periodStart),
        'period_end': readingJson['period_end'] ?? _dateOnly(submission.periodEnd),
        'previous_reading': readingJson['previous_reading'] ?? submission.previousReading,
        'current_reading': readingJson['current_reading'] ?? submission.currentReading,
        'consumption_units': readingJson['consumption_units'] ?? submission.consumption,
        'is_invoiced': readingJson['is_invoiced'] ?? response['invoice_issued'] == true,
        'image_path': submission.imagePath,
      };
      await _database.cacheReadings([enrichedReading]);

      final invoiceData = _asMap(response['invoice_data']);
      return ReadingSaveResult(
        reading: Reading.fromJson(enrichedReading),
        isPendingSync: false,
        invoiceIssued: _truthy(response['invoice_issued']),
        invoice: invoiceData == null ? null : InvoiceSummary.fromJson(invoiceData),
        message: _asString(response['message']),
      );
    } on DioException catch (error) {
      if (!_isRetryableDelivery(error)) rethrow;
      return _queueOfflineSubmission(localId, submission);
    }
  }

  Future<ReadingSaveResult> _queueOfflineSubmission(
    String localId,
    ReadingSubmission submission, {
    String message = 'حُفظت القراءة على الجهاز وستتم مزامنتها عند عودة الاتصال.',
  }) async {
    // A removed meter is evicted from SQLite. Never create an offline action
    // for a meter ID that is no longer present in the locally trusted list:
    // the server could have physically replaced it on another device.
    final cachedMeter = await _database.getCachedMeter(submission.meterId);
    if (cachedMeter == null || cachedMeter['removed_at'] != null) {
      throw StateError(
        'العداد غير متاح على هذا الجهاز؛ حدّث قائمة العدادات قبل تسجيل قراءة دون اتصال.',
      );
    }
    final readingPayload = submission.toJson(localId: localId)
      ..['local_id'] = localId
      ..['image_path'] = submission.imagePath;
    await _database.saveOfflineReading(
      localId: localId,
      actionId: localId,
      reading: readingPayload,
      ownerUserId: _ownerUserIdProvider?.call(),
    );
    return ReadingSaveResult(
      reading: Reading(
        localId: localId,
        meterId: submission.meterId,
        readingDate: submission.readingDate,
        actualReadingDate: submission.readingDate,
        periodStart: submission.periodStart,
        periodEnd: submission.periodEnd,
        previousReading: submission.previousReading,
        currentReading: submission.currentReading,
        consumptionUnits: submission.consumption,
        imagePath: submission.imagePath,
        isSynced: false,
      ),
      isPendingSync: true,
      message: message,
    );
  }

  @override
  Future<List<Reading>> getReadings({
    bool? isInvoiced,
    int? squareId,
    int? meterId,
    DateTime? fromDate,
    DateTime? toDate,
    String? query,
  }) async {
    try {
      final rows = await _remote.fetchReadings(
        isInvoiced: isInvoiced,
        squareId: squareId,
        meterId: meterId,
        fromDate: fromDate,
        toDate: toDate,
        query: query,
      );
      await _database.cacheReadings(rows);
      return _filterReadings(
        rows.map(Reading.fromJson).toList(growable: false),
        isInvoiced: isInvoiced,
        squareId: squareId,
        meterId: meterId,
        fromDate: fromDate,
        toDate: toDate,
        query: query,
      );
    } on DioException catch (error) {
      if (!_isTransportFailure(error) && error.response?.statusCode != 404) rethrow;
      final rows = await _database.getCachedReadings(
        isInvoiced: isInvoiced,
        squareId: squareId,
        meterId: meterId,
        fromDate: fromDate,
        toDate: toDate,
      );
      return _filterReadings(
        rows
            .map((row) => Reading.fromLocalRow(Map<String, Object?>.from(row)))
            .toList(growable: false),
        isInvoiced: isInvoiced,
        squareId: squareId,
        meterId: meterId,
        fromDate: fromDate,
        toDate: toDate,
        query: query,
      );
    }
  }

  List<Reading> _filterReadings(
    List<Reading> readings, {
    bool? isInvoiced,
    int? squareId,
    int? meterId,
    DateTime? fromDate,
    DateTime? toDate,
    String? query,
  }) {
    final q = query?.trim().toLowerCase() ?? '';
    final filtered = readings.where((reading) {
      if (isInvoiced != null && reading.isInvoiced != isInvoiced) return false;
      if (squareId != null && reading.squareId != squareId) return false;
      if (meterId != null && reading.meterId != meterId) return false;
      if (fromDate != null && reading.readingDate.isBefore(_dateOnlyValue(fromDate))) return false;
      if (toDate != null && reading.readingDate.isAfter(_dateOnlyValue(toDate))) return false;
      if (q.isEmpty) return true;
      return reading.meterCode?.toLowerCase().contains(q) == true ||
          reading.subscriberName?.toLowerCase().contains(q) == true ||
          reading.pathNumber?.toString().contains(q) == true;
    }).toList();
    filtered.sort((a, b) => b.readingDate.compareTo(a.readingDate));
    return filtered;
  }

  // A pending reading can be the starting value of another queued reading.
  // Refuse silent edits/deletes rather than corrupt the local chain.
  Future<void> _assertNoCachedSuccessor(Reading reading) async {
    final latest = await _database.getLatestCachedReading(reading.meterId);
    if (latest == null) return;
    final date = _parseDate(latest['actual_reading_date'] ?? latest['reading_date']);
    if (date == null) return;
    final original = reading.actualReadingDate ?? reading.readingDate;
    if (_dateOnlyValue(date).isAfter(_dateOnlyValue(original))) {
      throw StateError('لا يمكن تعديل أو حذف قراءة أصبحت أساساً لقراءة لاحقة. زامن القراءات أولاً.');
    }
  }

  @override
  Future<Reading> updateReading(
    Reading reading,
    double newCurrentReading, {
    DateTime? newReadingDate,
  }) async {
    if (reading.isInvoiced) {
      throw StateError('لا يمكن تعديل قراءة مفوترة.');
    }
    if (newCurrentReading < reading.previousReading) {
      throw StateError('القراءة الحالية لا يمكن أن تكون أقل من القراءة السابقة.');
    }
    await _assertNoCachedSuccessor(reading);
    final effectiveDate = newReadingDate ?? reading.readingDate;
    final effectiveActualDate = newReadingDate ?? reading.actualReadingDate ?? reading.readingDate;
    // Newly queued readings use observation boundaries. Server-backed
    // readings retain their original basis; the server validates/recomputes.
    final effectiveEnd = reading.id == null
        ? _dateOnlyValue(effectiveActualDate)
        : reading.periodEnd;
    if (reading.id == null &&
        (!effectiveEnd.isAfter(_dateOnlyValue(reading.periodStart)) ||
         effectiveEnd.isAfter(_dateOnlyValue(DateTime.now())))) {
      throw StateError('تاريخ القراءة يجب أن يلي بداية الفترة وألا يقع في المستقبل.');
    }
    final payload = {
      'reading_date': _dateOnly(effectiveDate),
      'actual_reading_date': _dateOnly(effectiveActualDate),
      'period_start': _dateOnly(reading.periodStart),
      'period_end': _dateOnly(effectiveEnd),
      'previous_reading': reading.previousReading,
      'current_reading': newCurrentReading,
      'consumption_units': newCurrentReading - reading.previousReading,
    };
    if (reading.id == null) {
      final localId = reading.localId;
      if (localId == null) throw StateError('معرّف القراءة المحلية غير موجود.');
      await _database.updateCachedReading(localId: localId, values: payload);
      final queuedAction = await _database.getSyncAction(localId);
      final queuedPayloadRaw = queuedAction?['payload_json'];
      final queuedPayload = queuedPayloadRaw is String
          ? _asMap(jsonDecode(queuedPayloadRaw)) ?? <String, dynamic>{}
          : <String, dynamic>{};
      final queuePayload = <String, dynamic>{
        ...queuedPayload,
        ...payload,
        'local_id': localId,
        'client_action_id': localId,
        'meter_id': reading.meterId,
        'image_path': reading.imagePath,
      };
      if (queuedAction == null) {
        await _database.enqueueAction(
          id: localId,
          type: 'reading.create',
          payloadJson: jsonEncode(queuePayload),
        );
      } else {
        await _database.updateSyncActionPayload(
          id: localId,
          payloadJson: jsonEncode(queuePayload),
        );
      }
      return Reading(
        id: reading.id,
        localId: localId,
        meterId: reading.meterId,
        readingDate: effectiveDate,
        actualReadingDate: effectiveActualDate,
        periodStart: reading.periodStart,
        periodEnd: effectiveEnd,
        previousReading: reading.previousReading,
        currentReading: newCurrentReading,
        consumptionUnits: newCurrentReading - reading.previousReading,
        isInvoiced: false,
        meterCode: reading.meterCode,
        pathNumber: reading.pathNumber,
        squareId: reading.squareId,
        squareName: reading.squareName,
        subscriberName: reading.subscriberName,
        imagePath: reading.imagePath,
        imageUrl: reading.imageUrl,
        isSynced: false,
      );
    }

    final response = await _remote.updateReading(reading.id!, payload);
    final updated = _asMap(response['reading']) ?? <String, dynamic>{};
    final enriched = <String, dynamic>{
      ...reading.toSyncJson(),
      ...payload,
      ...updated,
      'id': reading.id,
      'meter_id': reading.meterId,
      'meter_code': reading.meterCode,
      'subscriber_name': reading.subscriberName,
      'square_id': reading.squareId,
      'square_name': reading.squareName,
      'image_url': updated['image_url'] ?? reading.imageUrl,
    };
    await _database.cacheReadings([enriched]);
    return Reading.fromJson(enriched);
  }

  @override
  Future<void> deleteReading(Reading reading) async {
    if (reading.isInvoiced) throw StateError('لا يمكن حذف قراءة مفوترة.');
    if (reading.id == null) {
      await _assertNoCachedSuccessor(reading);
      final localId = reading.localId;
      if (localId != null) await _database.deleteCachedReadingByLocalId(localId);
      return;
    }
    await _remote.deleteReading(reading.id!);
    await _database.deleteCachedReadingsByServerIds([reading.id!]);
  }

  @override
  Future<BulkOperationResult> bulkDeleteReadings(List<Reading> readings) async {
    if (readings.isEmpty) return const BulkOperationResult(succeededCount: 0);
    final localReadings = readings
        .where((reading) => reading.id == null && !reading.isInvoiced)
        .toList(growable: false);
    final serverReadings = readings.where((reading) => reading.id != null).toList(growable: false);
    final locallySkipped = readings.where((reading) => reading.id == null && reading.isInvoiced).length;
    var deleted = 0;
    var skipped = locallySkipped;
    var failed = 0;

    // Preflight the entire local selection before deleting any pending row.
    for (final reading in localReadings) {
      await _assertNoCachedSuccessor(reading);
    }
    for (final reading in localReadings) {
      if (reading.localId != null) {
        await _database.deleteCachedReadingByLocalId(reading.localId!);
        deleted++;
      }
    }
    if (serverReadings.isNotEmpty) {
      final response = await _remote.bulkDelete(
        serverReadings.map((reading) => reading.id!).toList(growable: false),
      );
      final explicitlyDeleted = response['deleted_ids'];
      final responseDeletedIds = explicitlyDeleted is List
          ? explicitlyDeleted.map(_asInt).whereType<int>().toList(growable: false)
          : null;
      final serverDeletedCount = _asInt(response['deleted_count']) ??
          _asInt(response['succeeded_count']) ??
          responseDeletedIds?.length;
      deleted += serverDeletedCount ?? 0;
      skipped += _asInt(response['skipped_count']) ?? 0;
      failed += _asInt(response['failed_count']) ?? 0;
      final expectedServerDeletes = serverReadings.where((reading) => !reading.isInvoiced).length;
      final deletedIds = responseDeletedIds ??
          (skipped == locallySkipped &&
                  failed == 0 &&
                  serverDeletedCount == expectedServerDeletes
              ? serverReadings
                  .where((reading) => !reading.isInvoiced)
                  .map((reading) => reading.id!)
                  .toList(growable: false)
              : const <int>[]);
      await _database.deleteCachedReadingsByServerIds(deletedIds);
    }
    return BulkOperationResult(
      succeededCount: deleted,
      skippedCount: skipped,
      failedCount: failed,
      message: 'تم حذف $deleted قراءة وتخطي $skipped قراءة مفوترة.',
    );
  }

  @override
  Future<BulkOperationResult> bulkIssueInvoices(List<Reading> readings) async {
    if (readings.isEmpty) return const BulkOperationResult(succeededCount: 0);
    if (readings.any((reading) => reading.isInvoiced)) {
      throw StateError('يجب أن تكون جميع القراءات المحددة غير مفوترة.');
    }
    if (readings.any((reading) => reading.id == null)) {
      throw StateError('تجب مزامنة القراءات المحفوظة أوفلاين قبل إصدار فواتيرها.');
    }
    final response = await _remote.bulkIssueInvoices(
      readings.map((reading) => reading.id!).toList(growable: false),
    );
    final created = _asInt(response['invoices_created']) ??
        _asInt(response['created_count']) ??
        _asInt(response['invoice_count']) ??
        0;
    return BulkOperationResult(
      succeededCount: created,
      skippedCount: _asInt(response['skipped_count']) ?? 0,
      failedCount: _asInt(response['failed_count']) ?? 0,
      message: response['message']?.toString(),
    );
  }

  bool _isRetryableDelivery(DioException error) {
    final statusCode = error.response?.statusCode;
    return _isTransportFailure(error) ||
        statusCode == 408 ||
        statusCode == 429 ||
        (statusCode != null && statusCode >= 500);
  }

  bool _isTransportFailure(DioException error) =>
      error.response == null &&
      (error.type == DioExceptionType.connectionTimeout ||
          error.type == DioExceptionType.sendTimeout ||
          error.type == DioExceptionType.receiveTimeout ||
          error.type == DioExceptionType.connectionError);

  static Map<String, dynamic>? _asMap(Object? value) {
    if (value is Map<String, dynamic>) return value;
    if (value is Map) return Map<String, dynamic>.from(value);
    return null;
  }

  static int? _asInt(Object? value) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse(value?.toString() ?? '');
  }

  static double? _asDouble(Object? value) {
    if (value is num) return value.toDouble();
    return double.tryParse(value?.toString() ?? '');
  }

  static String? _asString(Object? value) => value?.toString();

  static DateTime? _parseDate(Object? value) =>
      value == null ? null : DateTime.tryParse(value.toString());

  static bool _truthy(Object? value) =>
      value == true || value == 1 || value?.toString().toLowerCase() == 'true';

  static DateTime _dateOnlyValue(DateTime date) => DateTime(date.year, date.month, date.day);

  static String _dateOnly(DateTime value) =>
      '${value.year.toString().padLeft(4, '0')}-'
      '${value.month.toString().padLeft(2, '0')}-'
      '${value.day.toString().padLeft(2, '0')}';
}
