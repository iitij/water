import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:water_project/core/field_operations/field_session_scope.dart';
import 'package:water_project/core/network/interceptors/auth_interceptor.dart';
import 'package:water_project/features/field_route/data/route_visit_store.dart';
import 'package:water_project/features/offline_sync/services/field_data_sync_service.dart';
import 'package:water_project/features/readings/data/reading_fault_log.dart';
import 'package:water_project/features/readings/domain/reading_fault.dart';

class _Adapter implements HttpClientAdapter {
  _Adapter(this.respond);
  final ResponseBody Function(RequestOptions) respond;
  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? stream,
      Future<void>? cancelFuture) async => respond(options);
  @override
  void close({bool force = false}) {}
}

ResponseBody _json(Map<String, Object> payload, {int status = 200}) =>
    ResponseBody.fromString(jsonEncode(payload), status, headers: {
      Headers.contentTypeHeader: [Headers.jsonContentType],
    });

const server = 'https://water.test/api/';
final oldDay = DateTime(2026, 1, 1);

ReadingFault fault(int id) => ReadingFault(
    meterId: id, reason: ReadingFaultReason.meterBroken, recordedAt: oldDay);

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  late int generation;
  late String user;
  late String currentServer;

  FieldSessionScope capture() {
    final g = generation;
    final u = user;
    final s = currentServer;
    return FieldSessionScope(server: s, userId: u, generation: g,
        isCurrent: () => generation == g && user == u && currentServer == s);
  }

  setUp(() {
    SharedPreferences.setMockInitialValues({});
    generation = 1;
    user = '10';
    currentServer = server;
  });

  test('same meter/day isolated by server and user; legacy keys unchanged', () async {
    final legacy = SharedPreferencesReadingFaultLog();
    await legacy.record(fault(99));
    await SharedPreferencesRouteVisitStore().save(
        squareId: 1, day: oldDay, meterIds: {99});
    final a = capture();
    final logA = SharedPreferencesReadingFaultLog(scope: a);
    final visitsA = SharedPreferencesRouteVisitStore(scope: a);
    expect(await logA.forDay(oldDay), isEmpty);
    expect(await visitsA.keys(), isEmpty);
    await logA.record(fault(1));
    await visitsA.save(squareId: 1, day: oldDay, meterIds: {1});

    user = '20';
    generation++;
    final b = capture();
    final logB = SharedPreferencesReadingFaultLog(scope: b);
    final visitsB = SharedPreferencesRouteVisitStore(scope: b);
    expect(await logB.days(), isEmpty);
    expect(await visitsB.keys(), isEmpty);
    await logB.record(fault(2));
    await visitsB.save(squareId: 1, day: oldDay, meterIds: {2});
    await expectLater(logA.record(fault(3)), throwsStateError);
    await expectLater(visitsA.visited(squareId: 1, day: oldDay), throwsStateError);

    currentServer = 'https://other.test/api/';
    generation++;
    expect(await SharedPreferencesReadingFaultLog(scope: capture()).days(), isEmpty);
    expect(await SharedPreferencesRouteVisitStore(scope: capture()).keys(), isEmpty);
    currentServer = server;
    user = '10';
    generation++;
    expect((await SharedPreferencesReadingFaultLog(scope: capture()).forDay(oldDay)).single.meterId, 1);
    expect(await SharedPreferencesRouteVisitStore(scope: capture()).visited(squareId: 1, day: oldDay), {1});
    expect((await legacy.forDay(oldDay)).single.meterId, 99);
    expect(await SharedPreferencesRouteVisitStore().visited(squareId: 1, day: oldDay), {99});
  });

  test('owned historic faults use max 200 per request and stable owner UUIDs', () async {
    final scope = capture();
    final log = SharedPreferencesReadingFaultLog(scope: scope);
    for (var id = 1; id <= 401; id++) {
      await log.record(fault(id));
    }
    await SharedPreferencesReadingFaultLog().record(fault(999));
    final sizes = <int>[];
    final uuids = <String>[];
    final dio = Dio(BaseOptions(baseUrl: server))..httpClientAdapter = _Adapter((options) {
      expect(options.extra[AuthInterceptor.sessionGenerationKey], 1);
      expect(options.extra[AuthInterceptor.sessionServerKey], server);
      final batch = (options.data as Map)['faults'] as List;
      sizes.add(batch.length);
      for (final row in batch.cast<Map>()) {
        expect(row['meter_id'], isNot(999));
        uuids.add(row['sync_uuid'] as String);
      }
      return _json({'synced_count': batch.length, 'duplicate_count': 0});
    });
    final service = FieldDataSyncService.scoped(dio: dio, captureScope: capture);
    final first = await service.syncReadingFaults(now: DateTime(2026, 11, 25));
    expect(first.ok, isTrue);
    expect(first.synced, 401);
    expect(sizes, [200, 200, 1]);
    final original = List<String>.of(uuids);
    expect(original.toSet(), hasLength(401));
    uuids.clear();
    sizes.clear();
    expect((await service.syncReadingFaults()).ok, isTrue);
    expect(uuids, original);
  });

  test('failed second batch retains acknowledged progress, never treats malformed count as success', () async {
    final store = InMemoryReadingFaultLog(
      List.generate(201, (i) => fault(i + 1)));
    var calls = 0;
    final dio = Dio(BaseOptions(baseUrl: server))..httpClientAdapter = _Adapter((_) {
      calls++;
      return calls == 1
          ? _json({'synced_count': 199, 'duplicate_count': 1})
          : _json({'synced_count': 0, 'duplicate_count': 0});
    });
    final service = FieldDataSyncService(dio: dio, faultLog: store,
        visitStore: InMemoryRouteVisitStore());
    final result = await service.syncReadingFaults(now: oldDay);
    expect(result.ok, isFalse);
    expect(result.error, isA<FormatException>());
    expect(result.synced, 199);
    expect(result.skipped, 1);
  });

  test('account change during first visit batch prevents further upload', () async {
    final scope = capture();
    await SharedPreferencesRouteVisitStore(scope: scope).save(
        squareId: 1, day: oldDay, meterIds: Set.from(List.generate(501, (i) => i + 1)));
    var calls = 0;
    final dio = Dio(BaseOptions(baseUrl: server))..httpClientAdapter = _Adapter((options) {
      calls++;
      expect((options.data as Map)['visits'], hasLength(500));
      expect(options.extra[AuthInterceptor.sessionGenerationKey], generation);
      user = '20';
      generation++;
      return _json({'synced_count': 500, 'duplicate_count': 0});
    });
    final result = await FieldDataSyncService.scoped(dio: dio, captureScope: capture)
        .syncRouteVisits(now: DateTime(2026, 11, 25));
    expect(result.ok, isFalse);
    expect(calls, 1);
    expect(await SharedPreferencesRouteVisitStore(scope: capture()).keys(), isEmpty);
  });

  test('missing authenticated owner returns errors without network request', () async {
    final dio = Dio(BaseOptions(baseUrl: server))..httpClientAdapter = _Adapter((_) {
      fail('Unauthenticated field data must not be uploaded');
    });
    final service = FieldDataSyncService.scoped(dio: dio,
        captureScope: () => throw StateError('No owner'));
    final results = await service.syncAll();
    expect(results, hasLength(2));
    expect(results.every((result) => !result.ok), isTrue);
  });
}
