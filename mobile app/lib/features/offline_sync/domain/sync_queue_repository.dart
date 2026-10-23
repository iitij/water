import 'sync_queue_item.dart';

abstract interface class SyncQueueRepository {
  Future<List<SyncQueueItem>> loadAll();
  Future<void> saveAll(List<SyncQueueItem> items);
}
