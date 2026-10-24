import 'package:shared_preferences/shared_preferences.dart';

import '../domain/sync_queue_item.dart';
import '../domain/sync_queue_repository.dart';

/// Lightweight SharedPreferences implementation kept for unit/widget testing
/// or environments without SQLite. Production uses `DriftSyncQueueRepository`.
class SharedPreferencesSyncQueueRepository implements SyncQueueRepository {
  static const String storageKey = 'water.pending_sync_actions.v1';

  @override
  Stream<void>? get onExternalChange => null;

  @override
  Future<List<SyncQueueItem>> loadAll() async {
    final preferences = await SharedPreferences.getInstance();
    final raw = preferences.getString(storageKey);
    if (raw == null || raw.isEmpty) return <SyncQueueItem>[];
    return SyncQueueItem.decodeList(raw);
  }

  @override
  Future<void> saveAll(List<SyncQueueItem> items) async {
    final preferences = await SharedPreferences.getInstance();
    if (items.isEmpty) {
      await preferences.remove(storageKey);
      return;
    }
    await preferences.setString(storageKey, SyncQueueItem.encodeList(items));
  }

  @override
  Future<void> upsert(SyncQueueItem item) async {
    final current = await loadAll();
    final index = current.indexWhere((existing) => existing.id == item.id);
    if (index >= 0) {
      current[index] = item;
    } else {
      current.add(item);
    }
    await saveAll(current);
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
    final current = await loadAll();
    current.removeWhere((item) => item.id == id);
    await saveAll(current);
  }

  @override
  Future<void> markPaymentSynced({
    required String id,
    String? serverResponseJson,
  }) async {
    final current = await loadAll();
    current.removeWhere((item) => item.id == id);
    await saveAll(current);
  }

  @override
  Future<void> recordSyncFailure({
    required SyncQueueItem item,
    required String error,
    DateTime? retryAt,
  }) async {
    await upsert(
      item.copyWith(
        status: retryAt == null ? SyncQueueStatus.failed : SyncQueueStatus.queued,
        lastError: error,
        nextAttemptAt: retryAt,
      ),
    );
  }
}
