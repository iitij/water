import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/auth/session_service.dart';
import 'package:water_project/core/network/network_info.dart';
import 'package:water_project/features/offline_sync/domain/sync_batch.dart';
import 'package:water_project/features/offline_sync/domain/sync_queue_item.dart';
import 'package:water_project/features/offline_sync/domain/sync_queue_repository.dart';
import 'package:water_project/features/offline_sync/services/sync_queue_manager.dart';

class _Session extends Mock implements SessionService {}
class _Network extends Mock implements NetworkInfo {}

class _QueueRepo implements SyncQueueRepository {
  _QueueRepo(List<SyncQueueItem> items)
      : pending = <String, SyncQueueItem>{for (final item in items) item.id: item};

  final Map<String, SyncQueueItem> pending;
  final List<String> uploaded = <String>[];
  final List<String> failed = <String>[];

  @override
  Stream<void>? get onExternalChange => null;

  @override
  Future<List<SyncQueueItem>> loadAll() async => pending.values.toList();

  @override
  Future<void> saveAll(List<SyncQueueItem> items) async {
    pending
      ..clear()
      ..addEntries(items.map((item) => MapEntry(item.id, item)));
  }

  @override
  Future<void> upsert(SyncQueueItem item) async {
    pending[item.id] = item;
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
    uploaded.add(id);
    pending.remove(id);
  }

  @override
  Future<void> markPaymentSynced({
    required String id,
    String? serverResponseJson,
  }) async {
    uploaded.add(id);
    pending.remove(id);
  }

  @override
  Future<void> recordSyncFailure({
    required SyncQueueItem item,
    required String error,
    DateTime? retryAt,
  }) async {
    failed.add(item.id);
    pending[item.id] = item;
  }
}

SyncQueueItem _reading(String id, String? owner, DateTime createdAt) =>
    SyncQueueItem(
      id: id,
      type: SyncQueueType.reading,
      payload: <String, dynamic>{
        'meter_id': 42,
        'current_reading': 10,
        'reading_date': '2026-11-14',
        'sync_uuid': id,
      },
      status: SyncQueueStatus.queued,
      createdAt: createdAt,
      ownerUserId: owner,
    );

void main() {
  const oldId = '9ca12035-39c0-4772-b3f1-a6a664981001';
  const newId = '9ca12035-39c0-4772-b3f1-a6a664981002';
  final baseTime = DateTime.utc(2026, 11, 14, 8);

  test('owned operations are eligible only for the authenticated owner', () {
    final old = _reading(oldId, '10', baseTime);
    final legacy = _reading(newId, null, baseTime);

    expect(canSyncForUser(old, '11'), isFalse);
    expect(canSyncForUser(old, '10'), isTrue);
    expect(canSyncForUser(old, null), isFalse);
    expect(canSyncForUser(old, ''), isFalse);
    expect(canSyncForUser(legacy, '11'), isTrue);
    expect(canSyncForUser(legacy, null), isFalse);
    expect(eligibleForSync([old, legacy], baseTime).length, 2,
        reason: 'ownership must be enforced by the manager');
  });

  test('sync skips foreign rows and resumes them after account switch', () async {
    final session = _Session();
    final network = _Network();
    when(() => session.isReady).thenReturn(true);
    when(() => session.isAuthenticated).thenReturn(true);
    when(() => session.user).thenReturn(<String, dynamic>{'id': 11});
    when(() => network.isConnected).thenAnswer((_) async => true);

    final repo = _QueueRepo([
      _reading(oldId, '10', baseTime),
      _reading(newId, '11', baseTime.add(const Duration(seconds: 1))),
    ]);
    final dio = Dio(BaseOptions(baseUrl: 'https://example.invalid'));
    final sentUuids = <String>[];
    dio.interceptors.add(InterceptorsWrapper(
      onRequest: (options, handler) {
        final body = Map<String, dynamic>.from(options.data as Map);
        final rows = body['readings'] as List;
        final row = Map<String, dynamic>.from(rows.single as Map);
        sentUuids.add(row['sync_uuid'] as String);
        handler.resolve(Response<Map<String, dynamic>>(
          requestOptions: options,
          statusCode: 200,
          data: <String, dynamic>{
            'results': [
              {
                'success': true,
                'reading': {'id': sentUuids.length + 200, 'meter_id': 42},
              }
            ],
          },
        ));
      },
    ));

    final manager = SyncQueueManager(
      dio: dio, repository: repo, networkInfo: network, session: session,
    );
    addTearDown(manager.dispose);

    await manager.syncPending();

    expect(sentUuids, [newId],
        reason: 'a foreign offline reading must not be sent with user 11');
    expect(repo.uploaded, [newId]);
    expect(repo.pending.keys, [oldId]);
    expect(repo.pending[oldId]!.status, SyncQueueStatus.queued);
    expect(manager.items, isEmpty,
        reason: 'the other account must not appear in the current user UI');
    expect(manager.pendingCount, 0);
    expect(manager.blockedCount, 0);
    expect(repo.pending[oldId]!.attempts, 0,
        reason: 'no artificial retries or terminal failure for another user');
    expect(repo.failed, isEmpty);

    when(() => session.user).thenReturn(<String, dynamic>{'id': 10});
    expect(manager.items.map((item) => item.id), [oldId]);
    expect(manager.pendingCount, 1);
    await manager.syncPending();

    expect(sentUuids, [newId, oldId]);
    expect(repo.uploaded, [newId, oldId]);
    expect(repo.pending, isEmpty);
    expect(repo.failed, isEmpty);
  });

  test('manual retry never resets a different account\'s rejected action', () async {
    final session = _Session();
    final network = _Network();
    when(() => session.isReady).thenReturn(true);
    when(() => session.isAuthenticated).thenReturn(true);
    when(() => session.user).thenReturn(<String, dynamic>{'id': 11});
    when(() => network.isConnected).thenAnswer((_) async => false);

    final repo = _QueueRepo([
      _reading(oldId, '10', baseTime)
          .copyWith(status: SyncQueueStatus.needsAttention, lastError: 'رفض قديم'),
      _reading(newId, '11', baseTime)
          .copyWith(status: SyncQueueStatus.failed, lastError: 'اتصال سابق'),
    ]);
    final manager = SyncQueueManager(
      dio: Dio(BaseOptions(baseUrl: 'https://example.invalid')),
      repository: repo,
      networkInfo: network,
      session: session,
    );
    addTearDown(manager.dispose);
    await manager.syncPending();

    expect(manager.items.map((item) => item.id), [newId]);
    expect(manager.pendingCount, 1);
    expect(manager.failedCount, 1);
    expect(manager.attentionCount, 0);
    expect(manager.blockedCount, 1);

    await manager.retry(oldId);
    expect(repo.pending[oldId]!.status, SyncQueueStatus.needsAttention);
    expect(repo.pending[oldId]!.lastError, 'رفض قديم');

    await manager.retryFailed();
    expect(repo.pending[oldId]!.status, SyncQueueStatus.needsAttention);
    expect(repo.pending[oldId]!.lastError, 'رفض قديم');
    expect(repo.pending[newId]!.status, SyncQueueStatus.queued);
    expect(repo.pending[newId]!.lastError, isNull);
    expect(repo.uploaded, isEmpty);
  });
}
