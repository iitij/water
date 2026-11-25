import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';
import 'package:uuid/uuid.dart';

import '../../../core/database/app_database.dart';
import '../domain/sync_queue_item.dart';
import '../domain/sync_queue_repository.dart';
import 'shared_preferences_sync_queue_repository.dart';

/// Unified SQLite/Drift-backed queue repository shared across Field Operations
/// (readings), Finance (payments), HomeScreen, Dashboard, and SCR-28.
class DriftSyncQueueRepository implements SyncQueueRepository {
  DriftSyncQueueRepository({
    required AppDatabase database,
    Uuid? uuid,
  })  : _database = database,
        _uuid = uuid ?? const Uuid();

  static const String legacyFinanceQueueKey = 'sync.queue.pending';
  static final RegExp _uuidPattern = RegExp(
    r'^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$',
  );

  final AppDatabase _database;
  final Uuid _uuid;

  @override
  Stream<void> get onExternalChange => _database.onSyncQueueChanged;

  /// One-time migration from legacy SharedPreferences queues (`Dev 1` and `Dev 3`)
  /// into the durable `pending_sync_actions` SQLite table.
  Future<void> migrateLegacyQueuesIfNeeded({
    SharedPreferences? preferences,
    String? fallbackOwnerUserId,
  }) async {
    final prefs = preferences ?? await SharedPreferences.getInstance();

    final legacyCoreRaw = prefs.getString(SharedPreferencesSyncQueueRepository.storageKey);
    if (legacyCoreRaw != null && legacyCoreRaw.isNotEmpty) {
      try {
        final decoded = jsonDecode(legacyCoreRaw);
        if (decoded is List) {
          for (final entry in decoded.whereType<Map<Object?, Object?>>()) {
            final item = SyncQueueItem.fromJson(Map<String, dynamic>.from(entry));
            final id = _ensureUuid(item.id);
            final normalizedPayload = _normalizePayload(item.type, item.payload, id);
            final existing = await _database.getSyncAction(id);
            if (existing == null) {
              await _database.enqueueAction(
                id: id,
                type: _wireActionType(item.type, normalizedPayload),
                payloadJson: jsonEncode(normalizedPayload),
                state: _wireState(item.status),
                attemptCount: item.attempts,
                createdAt: item.createdAt.toUtc().toIso8601String(),
                lastError: item.lastError,
                ownerUserId: item.ownerUserId,
              );
            }
          }
        }
        await prefs.remove(SharedPreferencesSyncQueueRepository.storageKey);
      } catch (_) {
        // Leave unreadable data untouched rather than crashing startup.
      }
    }

    final legacyFinanceList = prefs.getStringList(legacyFinanceQueueKey);
    if (legacyFinanceList != null && legacyFinanceList.isNotEmpty) {
      try {
        for (final rawItem in legacyFinanceList) {
          final decoded = jsonDecode(rawItem);
          if (decoded is! Map) continue;
          final map = Map<String, dynamic>.from(decoded);
          final rawPayload = map['payload'];
          final payload = rawPayload is Map
              ? Map<String, dynamic>.from(rawPayload)
              : <String, dynamic>{};
          final rawKey = map['idempotency_key']?.toString();
          final syncUuid = _ensureUuid(payload['sync_uuid']?.toString() ?? rawKey);
          payload['sync_uuid'] = syncUuid;
          if (rawKey != null && rawKey.isNotEmpty) {
            payload['local_receipt_number'] ??= rawKey;
          }
          final createdAt = DateTime.tryParse(map['created_at']?.toString() ?? '') ??
              DateTime.now();
          final existing = await _database.getSyncAction(syncUuid);
          if (existing == null) {
            await _database.enqueueAction(
              id: syncUuid,
              type: payload['invoice_id'] != null ? 'invoice.pay' : 'payment.create',
              payloadJson: jsonEncode(payload),
              state: 'pending',
              attemptCount: 0,
              createdAt: createdAt.toUtc().toIso8601String(),
              ownerUserId: null,
            );
          }
        }
        await prefs.remove(legacyFinanceQueueKey);
      } catch (_) {
        // Keep startup resilient if a malformed legacy row is encountered.
      }
    }
  }

  @override
  Future<List<SyncQueueItem>> loadAll() async {
    final rows = await _database.getActiveSyncActions(limit: null);
    return rows.map(_fromRow).toList(growable: true);
  }

  @override
  Future<void> saveAll(List<SyncQueueItem> items) async {
    // A partial snapshot is not an acknowledgement. Only explicit verified
    // mark*Synced calls may mark a durable row as synchronized.
    await _database.transaction(() async {
      for (final item in items) {
        await upsert(item);
      }
    });
  }

  @override
  Future<void> upsert(SyncQueueItem item) async {
    await _database.transaction(() async {
      final payload = _normalizePayload(item.type, item.payload, item.id);
      final existing = await _database.getSyncAction(item.id);
      if (existing == null) {
        await _database.enqueueAction(
          id: item.id,
          type: _wireActionType(item.type, payload),
          payloadJson: jsonEncode(payload),
          state: _wireState(item.status),
          attemptCount: item.attempts,
          createdAt: item.createdAt.toUtc().toIso8601String(),
          lastError: item.lastError,
          ownerUserId: item.ownerUserId,
          ownerServer: item.ownerServer,
          nextAttemptAt: item.nextAttemptAt?.toUtc().toIso8601String(),
        );
        return;
      }
      if (existing['owner_user_id'] != item.ownerUserId ||
          existing['owner_server'] != item.ownerServer ||
          existing['action_type'] != _wireActionType(item.type, payload) ||
          existing['state'] == 'synced' || existing['state'] == 'discarded') {
        throw StateError('Offline action identity or state conflict.');
      }
      final stored = jsonDecode(existing['payload_json'] as String);
      if (jsonEncode(stored) != jsonEncode(payload)) {
        throw StateError('Offline action payload conflict.');
      }
      await _database.updateSyncAction(
        id: item.id,
        state: _wireState(item.status),
        attemptCount: item.attempts,
        lastError: item.lastError,
        nextAttemptAt: item.nextAttemptAt?.toUtc().toIso8601String(),
        clearNextAttempt: item.nextAttemptAt == null,
        lastStatusCode: item.lastStatusCode,
        clearLastStatusCode: item.lastStatusCode == null,
      );
    });
  }

  Future<void> discardOwned(SyncQueueItem item) async {
    await _database.transaction(() async {
      final row = await _database.getSyncAction(item.id);
      if (row == null || item.ownerUserId == null || item.ownerServer == null ||
          row['owner_user_id'] != item.ownerUserId ||
          row['owner_server'] != item.ownerServer ||
          row['state'] == 'syncing' || row['state'] == 'synced') {
        throw StateError('Cannot discard this offline action.');
      }
      // Retain evidence; discarding is not a server acknowledgement.
      await _database.updateSyncAction(id: item.id, state: 'discarded');
    });
  }

  @override
  Future<void> markReadingSynced({
    required String id,
    required int serverId,
    bool? isInvoiced,
    int? invoiceId,
    Map<String, dynamic>? serverReading,
    String? serverResponseJson,
  }) async {
    await _database.markReadingSynced(
      localId: id,
      serverId: serverId,
      isInvoiced: isInvoiced,
      invoiceId: invoiceId,
      serverReading: serverReading,
      serverResponseJson: serverResponseJson,
    );
  }

  @override
  Future<void> markPaymentSynced({
    required String id,
    String? serverResponseJson,
  }) async {
    await _database.updateSyncAction(
      id: id,
      state: 'synced',
      lastError: null,
      serverResponseJson: serverResponseJson,
    );
  }

  @override
  Future<void> recordSyncFailure({
    required SyncQueueItem item,
    required String error,
    DateTime? retryAt,
  }) async {
    await _database.transaction(() async {
      final existing = await _database.getSyncAction(item.id);
      if (existing == null || existing['state'] == 'synced' ||
          existing['state'] == 'discarded' ||
          existing['owner_user_id'] != item.ownerUserId ||
          existing['owner_server'] != item.ownerServer) {
        throw StateError('Cannot change failure state of this action.');
      }
      await _database.updateSyncAction(
        id: item.id,
        state: retryAt != null && item.status == SyncQueueStatus.queued
            ? 'retrying' : _wireState(item.status),
        attemptCount: item.attempts,
        lastError: error,
        nextAttemptAt: retryAt?.toUtc().toIso8601String(),
        clearNextAttempt: retryAt == null,
        lastStatusCode: item.lastStatusCode,
        clearLastStatusCode: item.lastStatusCode == null,
      );
      if (item.type == SyncQueueType.reading) {
        await _database.setReadingSyncError(item.id, error);
      }
    });
  }

  SyncQueueItem _fromRow(Map<String, Object?> row) {
    final id = row['id']?.toString() ?? '';
    final actionType = row['action_type']?.toString() ?? 'reading.create';
    final rawPayload = row['payload_json']?.toString() ?? '{}';
    Map<String, dynamic> payload = <String, dynamic>{};
    try {
      final decoded = jsonDecode(rawPayload);
      if (decoded is Map) {
        payload = Map<String, dynamic>.from(decoded);
      }
    } catch (_) {
      payload = <String, dynamic>{};
    }

    final type = _parseActionType(actionType);
    final status = _parseStatus(row['state']?.toString());
    final createdAt = DateTime.tryParse(row['created_at']?.toString() ?? '') ??
        DateTime.now();
    final ownerUserId = row['owner_user_id']?.toString();
    final attempts = int.tryParse(row['attempt_count']?.toString() ?? '') ?? 0;
    final lastError = row['last_error']?.toString();

    return SyncQueueItem(
      id: id,
      type: type,
      payload: payload,
      status: status,
      createdAt: createdAt,
      ownerUserId: (ownerUserId != null && ownerUserId.isEmpty) ? null : ownerUserId,
      ownerServer: row['owner_server']?.toString(),
      attempts: attempts,
      lastError: (lastError != null && lastError.isEmpty) ? null : lastError,
      nextAttemptAt: DateTime.tryParse(row['next_attempt_at']?.toString() ?? ''),
      lastStatusCode: int.tryParse(row['last_status_code']?.toString() ?? ''),
    );
  }

  /// تحويل نوع الإجراء في قاعدة البيانات إلى نوع الطابور.
  ///
  /// الترتيب مهم: أنواع الإلغاء والإغلاق تُفحص قبل مطابقة `payment` العامة.
  static SyncQueueType parseActionType(String actionType) {
    const closeTypes = <String>{
      'cash_session.close',
      'cash-session.close',
      'cashsession.close',
    };
    const voidTypes = <String>{
      'payment.void',
      'payment.void.create',
      'payments.void',
    };
    if (closeTypes.contains(actionType)) return SyncQueueType.cashSessionClose;
    if (voidTypes.contains(actionType)) return SyncQueueType.paymentVoid;
    if (actionType.startsWith('payment') || actionType == 'invoice.pay') {
      return SyncQueueType.payment;
    }
    return SyncQueueType.reading;
  }

  SyncQueueType _parseActionType(String actionType) => parseActionType(actionType);

  SyncQueueStatus _parseStatus(String? state) {
    switch (state) {
      case 'syncing':
        return SyncQueueStatus.syncing;
      case 'failed':
        return SyncQueueStatus.failed;
      case 'needs_attention':
        return SyncQueueStatus.needsAttention;
      // 'retrying' يعني أن العملية ما زالت في الطابور وتنتظر مهلة التهدئة.
      case 'retrying':
      default:
        return SyncQueueStatus.queued;
    }
  }

  /// النوع المكتوب في `pending_sync_actions.action_type`.
  static String wireActionType(SyncQueueType type, Map<String, dynamic> payload) {
    switch (type) {
      case SyncQueueType.reading:
        return 'reading.create';
      case SyncQueueType.payment:
        return payload['invoice_id'] != null ? 'invoice.pay' : 'payment.create';
      case SyncQueueType.paymentVoid:
        return 'payment.void';
      case SyncQueueType.cashSessionClose:
        return 'cash_session.close';
    }
  }

  String _wireActionType(SyncQueueType type, Map<String, dynamic> payload) =>
      wireActionType(type, payload);

  /// الحالة المكتوبة في `pending_sync_actions.state`. الحالة `retrying` محفوظة
  /// للعمليات التي تنتظر مهلة إعادة المحاولة.
  static String wireState(SyncQueueStatus status) {
    switch (status) {
      case SyncQueueStatus.queued:
        return 'pending';
      case SyncQueueStatus.syncing:
        return 'syncing';
      case SyncQueueStatus.needsAttention:
        return 'needs_attention';
      case SyncQueueStatus.failed:
        return 'failed';
    }
  }

  String _wireState(SyncQueueStatus status) => wireState(status);


  Map<String, dynamic> _normalizePayload(
    SyncQueueType type,
    Map<String, dynamic> source,
    String id,
  ) {
    final copy = Map<String, dynamic>.from(source);
    final validUuid = _ensureUuid(copy['sync_uuid']?.toString() ?? id);
    copy['sync_uuid'] = validUuid;
    if (type == SyncQueueType.reading) {
      copy['local_id'] ??= id;
      copy['client_action_id'] ??= id;
    }
    return copy;
  }

  String _ensureUuid(String? candidate) {
    if (candidate != null && _uuidPattern.hasMatch(candidate.trim())) {
      return candidate.trim().toLowerCase();
    }
    return _uuid.v4();
  }
}
