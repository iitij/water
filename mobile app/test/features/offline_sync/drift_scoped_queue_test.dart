import 'dart:async';
import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/auth/session_service.dart';
import 'package:water_project/core/database/app_database.dart';
import 'package:water_project/core/field_operations/field_session_scope.dart';
import 'package:water_project/core/network/interceptors/auth_interceptor.dart';
import 'package:water_project/core/network/network_info.dart';
import 'package:water_project/features/offline_sync/data/drift_sync_queue_repository.dart';
import 'package:water_project/features/offline_sync/domain/sync_queue_item.dart';
import 'package:water_project/features/offline_sync/services/sync_queue_manager.dart';

class _Session extends Mock implements SessionService {}
class _Network extends Mock implements NetworkInfo {}

const origin = 'https://water.test/api/';
const otherOrigin = 'https://other.test/api/';
const firstId = '00000000-0000-4000-8000-000000000001';
const secondId = '00000000-0000-4000-8000-000000000002';
const thirdId = '00000000-0000-4000-8000-000000000003';
const fourthId = '00000000-0000-4000-8000-000000000004';

SyncQueueItem action(String id, {String? user = '10', String? server = origin,
    int subscriber = 10, SyncQueueStatus status = SyncQueueStatus.queued}) =>
    SyncQueueItem(id: id, type: SyncQueueType.payment,
      payload: {'sync_uuid': id, 'subscriber_id': subscriber, 'amount': '10.25',
        'payment_method': 'cash'},
      status: status, createdAt: DateTime.utc(2026, 11, 25),
      ownerUserId: user, ownerServer: server);

Map<String, dynamic> acknowledgement(String uuid, {String amount = '10.25'}) => {
  'payment': {'id': 123, 'sync_uuid': uuid, 'subscriber_id': 10, 'user_id': 10,
    'invoice_id': null, 'receipt_number': 'REC-202611-0001',
    'amount': amount, 'payment_method': 'cash'},
};

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  late AppDatabase db;
  late DriftSyncQueueRepository repo;
  late _Session session;
  late _Network network;
  late Dio dio;
  late String user;
  late int generation;

  FieldSessionScope capture() {
    final u = user;
    final g = generation;
    final s = dio.options.baseUrl;
    return FieldSessionScope(server: s, userId: u, generation: g,
        isCurrent: () => user == u && generation == g && dio.options.baseUrl == s);
  }

  setUp(() {
    db = AppDatabase.inMemory();
    repo = DriftSyncQueueRepository(database: db);
    session = _Session();
    network = _Network();
    dio = Dio(BaseOptions(baseUrl: origin));
    user = '10';
    generation = 1;
    when(() => session.isAuthenticated).thenReturn(true);
    when(() => session.isReady).thenReturn(true);
    when(() => session.user).thenAnswer((_) => {'id': user});
    when(() => network.isConnected).thenAnswer((_) async => true);
  });
  tearDown(() => db.close());

  SyncQueueManager manager() {
    final result = SyncQueueManager(dio: dio, repository: repo, networkInfo: network,
        session: session, captureScope: capture);
    addTearDown(result.dispose);
    return result;
  }

  test('durable action owner and payload cannot be overwritten or revived after acknowledgement', () async {
    final original = action(firstId);
    await repo.upsert(original);
    final before = await db.getSyncAction(firstId);
    expect(before?['owner_server'], origin);
    expect((await repo.loadAll()).single.ownerServer, origin);
    for (final conflicting in [
      action(firstId, user: '20'),
      action(firstId, server: otherOrigin),
      action(firstId, subscriber: 99),
    ]) {
      await expectLater(repo.upsert(conflicting), throwsStateError);
      expect(await db.getSyncAction(firstId), before);
    }
    await repo.markPaymentSynced(id: firstId, serverResponseJson: '{"payment":{"id":123}}');
    final synced = await db.getSyncAction(firstId);
    await expectLater(repo.upsert(original), throwsStateError);
    expect(await db.getSyncAction(firstId), synced);
  });

  test('legacy and other server rows remain untouched, only exact scope is uploaded', () async {
    await repo.upsert(action(firstId, user: null, server: null));
    await repo.upsert(action(secondId, server: null));
    await repo.upsert(action(thirdId, server: otherOrigin));
    await repo.upsert(action(fourthId));
    final before = await Future.wait([firstId, secondId, thirdId].map(db.getSyncAction));
    final sent = <String>[];
    dio.interceptors.add(InterceptorsWrapper(onRequest: (options, handler) {
      expect(options.extra[AuthInterceptor.sessionGenerationKey], 1);
      expect(options.extra[AuthInterceptor.sessionServerKey], origin);
      sent.add((options.data as Map)['sync_uuid'] as String);
      handler.resolve(Response(requestOptions: options, statusCode: 201,
          data: acknowledgement(sent.last)));
    }));
    final m = manager();
    await m.syncPending();
    expect(sent, [fourthId]);
    expect(m.items, isEmpty);
    expect(m.quarantinedCount, 2);
    expect(await Future.wait([firstId, secondId, thirdId].map(db.getSyncAction)), before);
    expect((await db.getSyncAction(fourthId))?['state'], 'synced');
  });

  test('session change during network response preserves original queued operation', () async {
    await repo.upsert(action(firstId));
    await repo.upsert(action(secondId));
    final secondBefore = await db.getSyncAction(secondId);
    final started = Completer<void>();
    final released = Completer<void>();
    var sends = 0;
    dio.interceptors.add(InterceptorsWrapper(onRequest: (options, handler) async {
      sends++;
      started.complete();
      await released.future;
      handler.resolve(Response(requestOptions: options, statusCode: 201,
          data: acknowledgement(firstId)));
    }));
    final m = manager();
    final pending = m.syncPending();
    await started.future;
    user = '20';
    generation++;
    released.complete();
    await pending;
    expect(sends, 1);
    final preserved = (await db.getSyncAction(firstId))!;
    expect(preserved['state'], isNot('synced'));
    expect(preserved['owner_user_id'], '10');
    expect(preserved['owner_server'], origin);
    expect(jsonDecode(preserved['payload_json'] as String)['subscriber_id'], 10);
    expect(await db.getSyncAction(secondId), secondBefore);
    expect(m.items, isEmpty);
  });

  test('2xx without exact receipt acknowledgement preserves payment for review', () async {
    await repo.upsert(action(firstId));
    var attempts = 0;
    dio.interceptors.add(InterceptorsWrapper(onRequest: (options, handler) {
      attempts++;
      handler.resolve(Response(requestOptions: options, statusCode: 201,
          data: attempts == 1 ? {'message': 'not evidence'} :
              acknowledgement(firstId, amount: '10.26')));
    }));
    final m = manager();
    await m.syncPending();
    expect((await db.getSyncAction(firstId))?['state'], isNot('synced'));
    expect(m.items.single.status, SyncQueueStatus.needsAttention);
    await m.retry(firstId);
    expect(attempts, 2);
    expect((await db.getSyncAction(firstId))?['state'], isNot('synced'));
    expect(m.items.single.status, SyncQueueStatus.needsAttention);
  });

  test('manual discard keeps evidence and cannot erase another server row', () async {
    when(() => network.isConnected).thenAnswer((_) async => false);
    await repo.upsert(action(firstId, server: otherOrigin));
    await repo.upsert(action(secondId));
    final m = manager();
    await m.syncPending();
    final foreign = await db.getSyncAction(firstId);
    await m.discard(firstId);
    expect(await db.getSyncAction(firstId), foreign);
    await m.discard(secondId);
    expect((await db.getSyncAction(secondId))?['state'], 'discarded');
    expect((await db.getSyncAction(secondId))?['server_response_json'], isNull);
  });

  test('older quarantined rows cannot starve a valid owned action beyond old scan limit', () async {
    for (var i = 0; i < 501; i++) {
      final id = '00000000-0000-4000-8000-${i.toString().padLeft(12, '0')}';
      await repo.upsert(action(id, user: null, server: null));
    }
    const validId = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
    await repo.upsert(action(validId));
    final sent = <String>[];
    dio.interceptors.add(InterceptorsWrapper(onRequest: (options, handler) {
      sent.add((options.data as Map)['sync_uuid'] as String);
      handler.resolve(Response(requestOptions: options, statusCode: 201,
          data: acknowledgement(validId)));
    }));
    final m = manager();
    await m.syncPending();
    expect(sent, [validId]);
    expect(m.quarantinedCount, 501);
    expect((await repo.loadAll()).length, 501);
  });
}
