import 'sync_queue_item.dart';

abstract interface class SyncQueueRepository {
  /// Emits when the underlying store changes from outside this repository
  /// (e.g. a reading saved directly by `ReadingsRepositoryImpl`). Returns `null`
  /// for storage backends that cannot notify (SharedPreferences).
  Stream<void>? get onExternalChange;

  Future<List<SyncQueueItem>> loadAll();

  Future<void> saveAll(List<SyncQueueItem> items);

  Future<void> upsert(SyncQueueItem item);

  Future<void> markReadingSynced({
    required String id,
    required int serverId,
    bool? isInvoiced,
    int? invoiceId,
    Map<String, dynamic>? serverReading,
    String? serverResponseJson,
  });

  Future<void> markPaymentSynced({
    required String id,
    String? serverResponseJson,
  });

  Future<void> recordSyncFailure({
    required SyncQueueItem item,
    required String error,
  });
}
