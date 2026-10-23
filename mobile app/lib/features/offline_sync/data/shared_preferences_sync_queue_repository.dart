import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import '../domain/sync_queue_item.dart';
import '../domain/sync_queue_repository.dart';

/// Replaceable persistence adapter until the field-operations feature supplies
/// its shared Drift AppDatabase implementation.
class SharedPreferencesSyncQueueRepository implements SyncQueueRepository {
  static const _storageKey = 'water.pending_sync_actions.v1';

  @override
  Future<List<SyncQueueItem>> loadAll() async {
    final preferences = await SharedPreferences.getInstance();
    final raw = preferences.getString(_storageKey);
    if (raw == null || raw.isEmpty) return <SyncQueueItem>[];
    try {
      final decoded = jsonDecode(raw);
      if (decoded is! List) return <SyncQueueItem>[];
      return decoded
          .whereType<Map>()
          .map((item) => SyncQueueItem.fromJson(Map<String, dynamic>.from(item)))
          .toList(growable: true);
    } on FormatException {
      await preferences.remove(_storageKey);
      return <SyncQueueItem>[];
    }
  }

  @override
  Future<void> saveAll(List<SyncQueueItem> items) async {
    final preferences = await SharedPreferences.getInstance();
    if (items.isEmpty) {
      await preferences.remove(_storageKey);
      return;
    }
    await preferences.setString(_storageKey, SyncQueueItem.encodeList(items));
  }
}
