import 'dart:async';

import 'app_database.dart';
import 'sync_queue_manager.dart';

class SyncQueueItem {
  const SyncQueueItem({
    required this.id,
    required this.actionType,
    required this.state,
    required this.attemptCount,
    required this.createdAt,
    this.lastError,
    this.payloadJson,
  });

  final String id;
  final String actionType;
  final String state;
  final int attemptCount;
  final DateTime? createdAt;
  final String? lastError;
  final String? payloadJson;

  bool get isPending => state == 'pending' || state == 'retrying' || state == 'syncing';
  bool get isFailed => state == 'failed';
  bool get isSynced => state == 'synced';

  factory SyncQueueItem.fromRow(Map<String, Object?> row) => SyncQueueItem(
        id: row['id']?.toString() ?? '',
        actionType: row['action_type']?.toString() ?? '',
        state: row['state']?.toString() ?? 'pending',
        attemptCount: int.tryParse(row['attempt_count']?.toString() ?? '') ?? 0,
        createdAt: DateTime.tryParse(row['created_at']?.toString() ?? ''),
        lastError: row['last_error']?.toString(),
        payloadJson: row['payload_json']?.toString(),
      );
}

/// Stable facade for SCR-28's sync monitor and other features that enqueue work.
class SyncQueueRepository {
  const SyncQueueRepository({
    required AppDatabase database,
    required SyncQueueManager manager,
  })  : _database = database,
        _manager = manager;

  final AppDatabase _database;
  final SyncQueueManager _manager;

  bool get isSyncing => _manager.isSyncing;

  Future<int> pendingCount() => _database.getPendingActionCount();

  Future<List<SyncQueueItem>> history({int limit = 100}) async {
    final rows = await _database.getSyncHistory(limit: limit);
    return rows.map(SyncQueueItem.fromRow).toList(growable: false);
  }

  Future<SyncManagerResult> syncNow() => _manager.syncPending();

  Future<void> retryFailed() => _manager.retryFailed();

  Stream<int> watchPendingCount({Duration interval = const Duration(seconds: 3)}) async* {
    yield await pendingCount();
    while (true) {
      await Future<void>.delayed(interval);
      yield await pendingCount();
    }
  }
}
