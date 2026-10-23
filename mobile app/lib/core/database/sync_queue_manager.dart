import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:dio/dio.dart';
import 'package:uuid/uuid.dart';

import 'app_database.dart';

typedef SyncBatchSender = Future<Map<String, dynamic>> Function(
  List<Map<String, dynamic>> readings, {
  required String batchId,
});

class SyncManagerResult {
  const SyncManagerResult({
    required this.syncedCount,
    required this.failedCount,
    required this.pendingCount,
    this.message,
  });

  final int syncedCount;
  final int failedCount;
  final int pendingCount;
  final String? message;
}

/// Replays durable, idempotent field-reading actions when connectivity returns.
///
/// The manager is deliberately transport-agnostic: Dev 1 can connect it to the
/// shared Dio client by passing `ReadingsRemoteDataSource.syncBatch` as sender.
class SyncQueueManager {
  SyncQueueManager({
    required AppDatabase database,
    required SyncBatchSender sendBatch,
    Connectivity? connectivity,
    Uuid? uuid,
  })  : _database = database,
        _sendBatch = sendBatch,
        _connectivity = connectivity ?? Connectivity(),
        _uuid = uuid ?? const Uuid();

  final AppDatabase _database;
  final SyncBatchSender _sendBatch;
  final Connectivity _connectivity;
  final Uuid _uuid;
  StreamSubscription<List<ConnectivityResult>>? _connectivitySubscription;
  bool _isSyncing = false;
  bool _disposed = false;
  Completer<void>? _syncCompletion;

  bool get isSyncing => _isSyncing;

  void startListening() {
    if (_connectivitySubscription != null || _disposed) return;
    _connectivitySubscription = _connectivity.onConnectivityChanged.listen((results) {
      if (results.any((result) => result != ConnectivityResult.none)) {
        unawaited(syncPending());
      }
    });
    unawaited(_syncIfConnected());
  }

  Future<void> _syncIfConnected() async {
    try {
      final results = await _connectivity.checkConnectivity();
      if (!_disposed && results.any((result) => result != ConnectivityResult.none)) {
        await syncPending();
      }
    } catch (_) {
      // Connectivity can be unavailable during early app startup; the listener
      // still retries when the platform reports its first network change.
    }
  }

  Future<SyncManagerResult> syncPending() async {
    if (_disposed) {
      return const SyncManagerResult(
        syncedCount: 0,
        failedCount: 0,
        pendingCount: 0,
        message: 'محرك المزامنة متوقف.',
      );
    }
    if (_isSyncing) {
      return SyncManagerResult(
        syncedCount: 0,
        failedCount: 0,
        pendingCount: await _database.getPendingActionCount(),
        message: 'المزامنة قيد التنفيذ بالفعل.',
      );
    }

    _isSyncing = true;
    _syncCompletion = Completer<void>();
    var syncedCount = 0;
    var failedCount = 0;
    String? message;
    try {
      final actions = await _database.getPendingActions(limit: 50);
      final readingActions = actions
          .where((action) => action['action_type'] == 'reading.create')
          .toList(growable: false);
      if (readingActions.isEmpty) {
        return SyncManagerResult(
          syncedCount: 0,
          failedCount: 0,
          pendingCount: await _database.getPendingActionCount(),
        );
      }

      final attemptById = <String, int>{};
      final payloadById = <String, Map<String, dynamic>>{};
      for (final action in readingActions) {
        final id = action['id'].toString();
        final attempts = _toInt(action['attempt_count']) + 1;
        attemptById[id] = attempts;
        await _database.updateSyncAction(
          id: id,
          state: 'syncing',
          attemptCount: attempts,
          lastError: null,
        );
        final decoded = jsonDecode(action['payload_json'].toString());
        if (decoded is! Map) {
          await _markFailed(id, attempts, 'بيانات القراءة المحفوظة تالفة.');
          failedCount++;
          continue;
        }
        final payload = Map<String, dynamic>.from(decoded);
        payload['client_action_id'] = id;
        payload['local_id'] ??= id;
        await _attachImage(payload);
        payloadById[id] = payload;
      }

      if (payloadById.isEmpty) {
        return SyncManagerResult(
          syncedCount: 0,
          failedCount: failedCount,
          pendingCount: await _database.getPendingActionCount(),
        );
      }

      final response = await _sendBatch(
        payloadById.values.toList(growable: false),
        batchId: _uuid.v4(),
      );
      final results = _extractResults(response);
      if (results == null) {
        if (_isExplicitFailure(response)) {
          final error = _serverMessage(response) ?? 'رفض الخادم مزامنة القراءات.';
          for (final id in payloadById.keys) {
            await _markFailed(id, attemptById[id]!, error, response: response);
            await _database.setReadingSyncError(id, error);
            failedCount++;
          }
        } else {
          // Without a per-row acknowledgement and server ID, a 2xx batch result
          // cannot safely be mapped back to local readings or retried idempotently.
          const error = 'استجاب الخادم دون نتائج تفصيلية للقراءات؛ يلزم فحص عقد المزامنة.';
          for (final id in payloadById.keys) {
            await _markFailed(id, attemptById[id]!, error, response: response);
            await _database.setReadingSyncError(id, error);
            failedCount++;
          }
        }
      } else {
        for (final id in payloadById.keys) {
          final rowResult = _findResult(results, id);
          if (rowResult == null) {
            const error = 'لم يؤكد الخادم نتيجة هذه القراءة؛ ستبقى للمراجعة.';
            await _markFailed(id, attemptById[id]!, error, response: response);
            await _database.setReadingSyncError(id, error);
            failedCount++;
          } else if (_resultSucceeded(rowResult)) {
            if (await _markSynced(id, response, rowResult)) {
              syncedCount++;
            } else {
              failedCount++;
            }
          } else {
            final error = _serverMessage(rowResult) ?? 'تعذرت مزامنة القراءة.';
            await _markFailed(id, attemptById[id]!, error, response: rowResult);
            await _database.setReadingSyncError(id, error);
            failedCount++;
          }
        }
      }
    } on DioException catch (error) {
      message = _dioMessage(error);
      final inProgress = await _database.getPendingActions(states: const ['syncing']);
      final statusCode = error.response?.statusCode;
      final permanentFailure = statusCode != null &&
          statusCode >= 400 &&
          statusCode < 500 &&
          statusCode != 408 &&
          statusCode != 429;
      for (final action in inProgress) {
        final id = action['id'].toString();
        final attempts = _toInt(action['attempt_count']);
        if (permanentFailure) {
          await _markFailed(id, attempts, message!, response: _asMap(error.response?.data));
          await _database.setReadingSyncError(id, message!);
          failedCount++;
        } else {
          await _database.updateSyncAction(
            id: id,
            state: 'pending',
            lastError: message,
          );
        }
      }
    } catch (error) {
      message = error.toString();
      final inProgress = await _database.getPendingActions(states: const ['syncing']);
      for (final action in inProgress) {
        await _markFailed(
          action['id'].toString(),
          _toInt(action['attempt_count']),
          message,
        );
      }
    } finally {
      _isSyncing = false;
      _syncCompletion?.complete();
      _syncCompletion = null;
    }

    return SyncManagerResult(
      syncedCount: syncedCount,
      failedCount: failedCount,
      pendingCount: await _database.getPendingActionCount(),
      message: message,
    );
  }

  Future<void> retryFailed() async {
    final failed = await _database.getPendingActions(states: const ['failed'], limit: 500);
    for (final action in failed) {
      await _database.updateSyncAction(
        id: action['id'].toString(),
        state: 'pending',
        lastError: null,
      );
      await _database.setReadingSyncError(action['id'].toString(), '');
    }
    await syncPending();
  }

  Future<void> _attachImage(Map<String, dynamic> payload) async {
    final path = payload.remove('image_path')?.toString();
    if (path == null || path.isEmpty) return;
    final file = File(path);
    if (!await file.exists()) return;
    payload['image_base64'] = base64Encode(await file.readAsBytes());
    payload['image_filename'] = path.split(Platform.pathSeparator).last;
  }

  Future<bool> _markSynced(
    String id,
    Map<String, dynamic> batchResponse,
    Map<String, dynamic> rowResult,
  ) async {
    final reading = _asMap(rowResult['reading']) ??
        _asMap(rowResult['data']) ??
        (rowResult.containsKey('current_reading') ? rowResult : null) ??
        _asMap(batchResponse['reading']);
    final serverId = _toNullableInt(
      reading?['id'] ??
          rowResult['reading_id'] ??
          rowResult['server_id'] ??
          rowResult['id'],
    );
    if (serverId == null) {
      const error = 'أكد الخادم المزامنة دون إرجاع رقم القراءة؛ يلزم التحقق قبل المتابعة.';
      await _database.updateSyncAction(
        id: id,
        state: 'failed',
        lastError: error,
        serverResponseJson: jsonEncode(rowResult.isEmpty ? batchResponse : rowResult),
      );
      await _database.setReadingSyncError(id, error);
      return false;
    }
    await _database.markReadingSynced(
      localId: id,
      serverId: serverId,
      isInvoiced: _nullableBool(reading?['is_invoiced'] ?? rowResult['is_invoiced']),
      invoiceId: _toNullableInt(reading?['invoice_id'] ?? rowResult['invoice_id']),
      serverReading: reading,
      serverResponseJson: jsonEncode(rowResult.isEmpty ? batchResponse : rowResult),
    );
    return true;
  }

  Future<void> _markFailed(
    String id,
    int attempts,
    String error, {
    Map<String, dynamic>? response,
  }) async {
    await _database.updateSyncAction(
      id: id,
      state: 'failed',
      attemptCount: attempts,
      lastError: error,
      serverResponseJson: response == null ? null : jsonEncode(response),
    );
  }

  List<Map<String, dynamic>>? _extractResults(Map<String, dynamic> response) {
    Object? value =
        response['results'] ?? response['synced'] ?? response['readings'] ?? response['data'];
    if (value is Map) value = value['results'] ?? value['readings'];
    if (value is! List) return null;
    return value
        .whereType<Map>()
        .map((row) => Map<String, dynamic>.from(row))
        .toList(growable: false);
  }

  Map<String, dynamic>? _findResult(List<Map<String, dynamic>> results, String id) {
    for (final result in results) {
      final resultId = (result['client_action_id'] ??
              result['sync_uuid'] ??
              result['local_id'] ??
              result['client_id'] ??
              result['action_id'])
          ?.toString();
      if (resultId == id) return result;
    }
    return null;
  }

  bool _resultSucceeded(Map<String, dynamic> result) {
    final status = result['success'] ?? result['synced'] ?? result['ok'];
    final statusText = status?.toString().toLowerCase();
    if (status == false || status == 0 || statusText == 'false' || statusText == '0' || statusText == 'no') {
      return false;
    }
    final state = result['status']?.toString().toLowerCase();
    if (state == 'failed' || state == 'error' || state == 'rejected') return false;
    return !_hasErrors(result['errors']);
  }

  bool _isExplicitFailure(Map<String, dynamic> response) {
    final success = response['success'] ?? response['ok'];
    final successText = success?.toString().toLowerCase();
    final errors = response['errors'];
    return success == false ||
        success == 0 ||
        successText == 'false' ||
        successText == '0' ||
        successText == 'no' ||
        _hasErrors(errors);
  }

  bool _hasErrors(Object? errors) {
    if (errors == null || errors == false || errors == '') return false;
    if (errors is Map) return errors.isNotEmpty;
    if (errors is List) return errors.isNotEmpty;
    return true;
  }

  String? _serverMessage(Map<String, dynamic> response) =>
      (response['message'] ?? response['error'])?.toString();

  String _dioMessage(DioException error) {
    final data = error.response?.data;
    if (data is Map && data['message'] != null) return data['message'].toString();
    if (error.response == null) return 'لا يوجد اتصال بالخادم؛ ستبقى القراءات في طابور المزامنة.';
    return 'فشل الاتصال بالخادم (${error.response?.statusCode ?? 'غير معروف'}).';
  }

  static Map<String, dynamic>? _asMap(Object? value) {
    if (value is Map<String, dynamic>) return value;
    if (value is Map) return Map<String, dynamic>.from(value);
    return null;
  }

  static int _toInt(Object? value) => _toNullableInt(value) ?? 0;

  static int? _toNullableInt(Object? value) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse(value?.toString() ?? '');
  }

  static bool? _nullableBool(Object? value) {
    if (value == null) return null;
    return value == true || value == 1 || value.toString().toLowerCase() == 'true';
  }

  Future<void> dispose() async {
    _disposed = true;
    await _connectivitySubscription?.cancel();
    await _syncCompletion?.future;
  }
}
