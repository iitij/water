import 'dart:async';
import 'dart:convert';
import 'dart:math';

import 'package:crypto/crypto.dart';
import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart' show visibleForTesting;

import '../../../core/field_operations/field_session_scope.dart';
import '../../../core/network/interceptors/auth_interceptor.dart';
import '../../field_route/data/route_visit_store.dart';
import '../../readings/data/reading_fault_log.dart';
import '../../readings/domain/reading_fault.dart';

/// نتيجة مزامنة دفعة بيانات ميدانية.
class FieldSyncResult {
  const FieldSyncResult({
    required this.synced,
    required this.skipped,
    this.error,
  });

  final int synced;
  final int skipped;
  final Object? error;

  bool get ok => error == null;

  @override
  String toString() => 'FieldSyncResult(synced: $synced, skipped: $skipped, error: $error)';
}

/// رفع بيانات الميدان المحلية إلى الخادم:
///
/// 1) أعطال القراءة (`POST /readings/faults/sync-batch`) — كانت محلية فقط وتموت
///    بفقدان الجهاز؛ الآن تُرفع فور تسجيلها وتُعاد محاولة رفعها عند عودة الاتصال.
/// 2) زيارات جولة المسار (`POST /route-visits/sync-batch`) — تقدّم الجولة يصبح
///    مرئياً للمشرف من أي جهاز.
///
/// كل سجل يُرسل بـ `sync_uuid` ثابت مشتق من محتواه (متر+يوم+سبب) حتى يكون
/// الرفع المتكرر آمناً تماماً (Idempotent) بلا تكرار على الخادم.
class FieldDataSyncService {
  FieldDataSyncService({
    required Dio dio,
    required ReadingFaultLog faultLog,
    required RouteVisitStore visitStore,
  }) : _dio = dio, _faultLog = faultLog, _visitStore = visitStore,
       _captureScope = null;

  /// Production always captures a fixed owner before reading or sending data.
  FieldDataSyncService.scoped({
    required Dio dio,
    required FieldSessionScope Function() captureScope,
  }) : _dio = dio, _captureScope = captureScope,
       _faultLog = null, _visitStore = null;

  final Dio _dio;
  final ReadingFaultLog? _faultLog;
  final RouteVisitStore? _visitStore;
  final FieldSessionScope Function()? _captureScope;

  static const int lookbackDays = 14;
  bool _faultsInFlight = false;
  bool _visitsInFlight = false;

  Options? _options(FieldSessionScope? scope) {
    if (scope == null) return null;
    scope.ensureCurrent();
    return Options(extra: {
      AuthInterceptor.sessionGenerationKey: scope.generation,
      AuthInterceptor.sessionServerKey: scope.server,
      'skipOfflineCache': true,
    });
  }

  Future<FieldSyncResult> syncReadingFaults({DateTime? now}) async {
    if (_faultsInFlight) return const FieldSyncResult(synced: 0, skipped: 0);
    _faultsInFlight = true;
    var synced = 0;
    var skipped = 0;
    try {
      final scope = _captureScope?.call();
      final log = scope == null ? _faultLog! : SharedPreferencesReadingFaultLog(scope: scope);
      final reference = now ?? DateTime.now();
      final days = log is SharedPreferencesReadingFaultLog && scope != null
          ? await log.days()
          : List.generate(lookbackDays, (ago) => reference.subtract(Duration(days: ago)));
      final faults = <ReadingFault>[];
      for (final day in days) {
        faults.addAll(await log.forDay(day));
      }
      for (var start = 0; start < faults.length; start += 200) {
        final slice = faults.sublist(start, min(start + 200, faults.length));
        final response = await _dio.post<dynamic>(
          'readings/faults/sync-batch',
          data: {'faults': slice.map((fault) {
            final payload = _faultPayload(fault);
            if (scope != null) {
              payload['sync_uuid'] = _deterministicUuid(
                jsonEncode([scope.namespace, 'reading-fault', fault.meterId,
                  _dateString(fault.recordedAt), fault.reason.code,
                  fault.recordedAt.microsecondsSinceEpoch, fault.note]),
              );
            }
            return payload;
          }).toList(growable: false)},
          options: _options(scope),
        );
        scope?.ensureCurrent();
        final counts = _parseCounts(response.data, slice.length);
        synced += counts.$1;
        skipped += counts.$2;
      }
      return FieldSyncResult(synced: synced, skipped: skipped);
    } catch (error) {
      return FieldSyncResult(synced: synced, skipped: skipped, error: error);
    } finally {
      _faultsInFlight = false;
    }
  }

  Future<FieldSyncResult> syncRouteVisits({DateTime? now}) async {
    if (_visitsInFlight) return const FieldSyncResult(synced: 0, skipped: 0);
    _visitsInFlight = true;
    var synced = 0;
    var skipped = 0;
    try {
      final scope = _captureScope?.call();
      final store = scope == null ? _visitStore! : SharedPreferencesRouteVisitStore(scope: scope);
      final reference = now ?? DateTime.now();
      final cutoff = reference.subtract(const Duration(days: lookbackDays));
      final keys = await store.keys();
      final eligible = scope != null ? keys : keys.where((key) =>
          !key.day.isBefore(DateTime(cutoff.year, cutoff.month, cutoff.day)));
      final visits = <Map<String, dynamic>>[];
      for (final key in eligible) {
        final meterIds = await store.visited(squareId: key.squareId, day: key.day);
        for (final meterId in meterIds) {
          final legacyUuid = visitSyncUuid(squareId: key.squareId, meterId: meterId, day: key.day);
          visits.add({
            'route_date': _dateString(key.day),
            'meter_id': meterId,
            'status': 'visited',
            'visit_at': key.day.toIso8601String(),
            'sync_uuid': scope == null ? legacyUuid :
                _deterministicUuid(jsonEncode([scope.namespace, legacyUuid])),
          });
        }
      }
      for (var start = 0; start < visits.length; start += 500) {
        final slice = visits.sublist(start, min(start + 500, visits.length));
        final response = await _dio.post<dynamic>(
          'route-visits/sync-batch',
          data: {'visits': slice},
          options: _options(scope),
        );
        scope?.ensureCurrent();
        final counts = _parseCounts(response.data, slice.length);
        synced += counts.$1;
        skipped += counts.$2;
      }
      return FieldSyncResult(synced: synced, skipped: skipped);
    } catch (error) {
      return FieldSyncResult(synced: synced, skipped: skipped, error: error);
    } finally {
      _visitsInFlight = false;
    }
  }

  Future<List<FieldSyncResult>> syncAll({DateTime? now}) async {
    FieldSessionScope? scope;
    try {
      scope = _captureScope?.call();
    } catch (error) {
      final failure = FieldSyncResult(synced: 0, skipped: 0, error: error);
      return [failure, failure];
    }
    final faults = await syncReadingFaults(now: now);
    try {
      scope?.ensureCurrent();
    } catch (error) {
      return [faults, FieldSyncResult(synced: 0, skipped: 0, error: error)];
    }
    return [faults, await syncRouteVisits(now: now)];
  }

  static Map<String, dynamic> _faultPayload(ReadingFault fault) => <String, dynamic>{
        'meter_id': fault.meterId,
        'reading_date': _dateString(fault.recordedAt),
        'fault_type': fault.reason.code,
        if (fault.note != null && fault.note!.trim().isNotEmpty) 'notes': fault.note!.trim(),
        'recorded_at': fault.recordedAt.toIso8601String(),
        'sync_uuid': faultSyncUuid(fault),
      };

  /// UUID ثابت للعطل: نفس العطل لنفس العداد/اليوم/السبب = نفس الـ UUID دائماً.
  @visibleForTesting
  static String faultSyncUuid(ReadingFault fault) => _deterministicUuid(
        'reading-fault|${fault.meterId}|${_dateString(fault.recordedAt)}|${fault.reason.code}|${fault.recordedAt.millisecondsSinceEpoch ~/ 1000}',
      );

  /// UUID ثابت للزيارة: نفس العداد في نفس الجولة = نفس الـ UUID.
  @visibleForTesting
  static String visitSyncUuid({
    required int squareId,
    required int meterId,
    required DateTime day,
  }) =>
      _deterministicUuid('route-visit|$squareId|$meterId|${_dateString(day)}');

  /// sha256(seed) → UUID v5 الشكل (8-4-4-4-12) مع ضبط bits الإصدار والصنف
  /// حتى يجتاز تحقق `uuid` في Laravel ويبقى ثابتاً عبر الإرسالات المتكررة.
  static String _deterministicUuid(String seed) {
    final digest = sha256.convert(utf8.encode(seed)).bytes;
    final bytes = List<int>.from(digest.sublist(0, 16));
    bytes[6] = (bytes[6] & 0x0f) | 0x50; // الإصدار 5
    bytes[8] = (bytes[8] & 0x3f) | 0x80; // صنف RFC 4122
    final hex = bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
    return '${hex.substring(0, 8)}-${hex.substring(8, 12)}-${hex.substring(12, 16)}-'
        '${hex.substring(16, 20)}-${hex.substring(20, 32)}';
  }

  static String _dateString(DateTime day) =>
      '${day.year.toString().padLeft(4, '0')}-${day.month.toString().padLeft(2, '0')}-${day.day.toString().padLeft(2, '0')}';

  static (int, int) _parseCounts(Object? data, int expected) {
    if (data is Map) {
      final synced = data['synced_count'];
      final duplicated = data['duplicate_count'];
      if (synced is int && duplicated is int &&
          synced >= 0 && duplicated >= 0 &&
          synced <= expected && duplicated == expected - synced) {
        return (synced, duplicated);
      }
    }
    throw const FormatException('Invalid field batch acknowledgement.');
  }
}
