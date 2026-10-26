import 'dart:async';
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:water_project/features/audit/data/audit_event_store.dart';
import 'package:water_project/features/audit/domain/local_audit_event.dart';
import 'package:water_project/features/audit/services/audit_log_service.dart';

/// سجل التدقيق المحلي (S4-05): لا يُفقد حدث، والرفع دفعات بلا تكرار.
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('AuditLogService', () {
    test('معرّفات UUID فريدة ولا تضيع أحداث التسجيل المتزامن الكثيف', () async {
      final store = InMemoryAuditStore();
      final sent = <Map<String, dynamic>>[];
      final service = AuditLogService(
        store: store,
        batchSize: 31,
        upload: (events) async => sent.addAll(events),
      );
      addTearDown(service.dispose);

      final events = await Future.wait(
        List.generate(500, (index) => service.record('reading.saved.$index')),
      );

      expect(events.whereType<LocalAuditEvent>().length, 500);
      expect(events.map((event) => event!.id).toSet().length, 500);
      expect(
        events.first!.id,
        matches(
          RegExp(
            r'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$',
          ),
        ),
      );
      expect(service.pendingCount, 500);
      expect(await service.flush(), 500);
      expect(sent.map((event) => event['event']).toSet().length, 500);
      expect(await store.pending(), isEmpty);
    });

    test(
      'record أثناء upload يبقى محفوظاً ويُرفع دون أن يحذفه snapshot قديم',
      () async {
        final store = InMemoryAuditStore();
        final started = Completer<void>(), release = Completer<void>();
        final sent = <String>[];
        final service = AuditLogService(
          store: store,
          upload: (events) async {
            sent.addAll(events.map((event) => event['event'] as String));
            if (!started.isCompleted) {
              started.complete();
              await release.future;
            }
          },
        );
        addTearDown(service.dispose);
        await service.record('payment.first');
        final flushing = service.flush();
        await started.future;
        await service.record('payment.during_upload');
        expect((await store.pending()).length, 2);
        release.complete();

        expect(await flushing, 2);
        expect(sent, ['payment.first', 'payment.during_upload']);
        expect(await store.pending(), isEmpty);
      },
    );

    test(
      'تبديل الحساب أثناء الرفع يوقف الدفعات التالية ويحفظ سجل كل حساب',
      () async {
        String? scope = 'server-a/user-a';
        final stores = <String, InMemoryAuditStore>{};
        final started = Completer<void>(), release = Completer<void>();
        final sent = <String>[];
        final service = AuditLogService(
          store: InMemoryAuditStore(),
          batchSize: 1,
          scopeProvider: () => scope,
          scopedStoreFactory: (key) =>
              stores.putIfAbsent(key, InMemoryAuditStore.new),
          upload: (events) async {
            sent.add(events.single['event'] as String);
            if (!started.isCompleted) {
              started.complete();
              await release.future;
            }
          },
        );
        addTearDown(service.dispose);
        await service.record('a.first');
        await service.record('a.second');
        final flushing = service.flush();
        await started.future;
        scope = 'server-a/user-b';
        service.sessionChanged();
        expect(service.pendingCount, 0);
        await service.record('b.first');
        release.complete();

        expect(await flushing, 1);
        expect(sent, ['a.first']);
        expect(service.pendingCount, 1);
        expect(
          (await stores['server-a/user-a']!.pending()).single.event,
          'a.second',
        );
        expect(await service.flush(), 1);
        expect(sent, ['a.first', 'b.first']);
        scope = 'server-a/user-a';
        service.sessionChanged();
        expect(await service.refreshPendingCount(), 1);
        expect(await service.flush(), 1);
        expect(sent.last, 'a.second');
      },
    );

    test('الخادم جزء من النطاق حتى لو تطابق رقم المستخدم', () async {
      var scope = jsonEncode(['https://a.example/api/', '7']);
      final stores = <String, InMemoryAuditStore>{};
      final sent = <String>[];
      final service = AuditLogService(
        store: InMemoryAuditStore(),
        scopeProvider: () => scope,
        scopedStoreFactory: (key) =>
            stores.putIfAbsent(key, InMemoryAuditStore.new),
        upload: (events) async =>
            sent.addAll(events.map((event) => event['event'] as String)),
      );
      addTearDown(service.dispose);
      await service.record('server_a.event');
      scope = jsonEncode(['https://b.example/api/', '7']);
      service.sessionChanged();
      expect(await service.refreshPendingCount(), 0);
      await service.record('server_b.event');
      expect(await service.flush(), 1);
      expect(sent, ['server_b.event']);
      expect(
        (await stores[jsonEncode(['https://a.example/api/', '7'])]!.pending())
            .single
            .event,
        'server_a.event',
      );
    });

    test(
      'سجل SharedPreferences القديم محجور ولا يُنسب إلى أول حساب يسجل الدخول',
      () async {
        SharedPreferences.setMockInitialValues({});
        final legacy = SharedPreferencesAuditStore();
        await legacy.save([_event('legacy-without-owner')]);
        String? scope;
        final sent = <Map<String, dynamic>>[];
        final service = AuditLogService(
          store: legacy,
          scopeProvider: () => scope,
          scopedStoreFactory: (key) =>
              SharedPreferencesAuditStore(scopeKey: key),
          upload: (events) async => sent.addAll(events),
        );
        addTearDown(service.dispose);
        expect(await service.record('anonymous.event'), isNull);
        expect(await service.flush(), 0);
        scope = jsonEncode(['https://a.example/api/', '7']);
        service.sessionChanged();
        expect(await service.refreshPendingCount(), 0);
        await service.record('owned.event');
        expect(await service.flush(), 1);
        expect(sent.single['event'], 'owned.event');
        expect(sent.single.containsKey('actor_user_id'), isFalse);
        expect(sent.single.containsKey('owner_user_id'), isFalse);
        expect(sent.single.containsKey('scope'), isFalse);
        expect((await legacy.pending()).single.id, 'legacy-without-owner');
        scope = null;
        service.sessionChanged();
        expect(await service.refreshPendingCount(), 0);
        expect(await service.record('signed_out.event'), isNull);
      },
    );

    test(
      'record يضيف لنسخة مستقلة ويحفظ الأحداث الموجودة في snapshot غير قابل للتعديل',
      () async {
        final existing = _event('existing');
        final store = InMemoryAuditStore([existing]);
        final snapshot = await store.pending();
        final service = AuditLogService(store: store, upload: (_) async {});
        addTearDown(service.dispose);

        final recorded = await service.record('cash_session.closed');

        expect(recorded, isNotNull);
        expect(snapshot, [existing]);
        expect(await store.pending(), [existing, recorded]);
        expect(service.pendingCount, 2);
        expect(service.lastError, isNull);
      },
    );

    test('record يحفظ الحدث محلياً قبل أي اتصال', () async {
      final store = InMemoryAuditStore();
      final sent = <List<Map<String, dynamic>>>[];
      final service = AuditLogService(
        store: store,
        upload: (events) async => sent.add(events),
      );

      await service.record(
        'cash_session.opened',
        entityType: 'CashSession',
        entityId: 7,
      );
      await service.record(
        'cash_session.closed',
        entityType: 'CashSession',
        entityId: 7,
      );

      expect(service.pendingCount, 2);
      expect(sent, isEmpty, reason: 'التسجيل لا يرفع شيئاً من تلقاء نفسه');
      final pending = await store.pending();
      expect(pending.map((e) => e.event), <String>[
        'cash_session.opened',
        'cash_session.closed',
      ]);
      expect(pending.first.entityType, 'CashSession');
      expect(pending.first.entityId, '7');
    });

    test('flush يرسل دفعات بحجم batchSize ويحذف ما رُفع فقط', () async {
      final store = InMemoryAuditStore();
      final sent = <List<Map<String, dynamic>>>[];
      final service = AuditLogService(
        store: store,
        batchSize: 2,
        upload: (events) async => sent.add(events),
      );

      for (var i = 0; i < 3; i++) {
        await service.record('payment.queued_offline');
      }

      final uploaded = await service.flush();

      expect(uploaded, 3);
      expect(sent.map((batch) => batch.length), <int>[2, 1]);
      expect(await store.pending(), isEmpty);
      expect(service.pendingCount, 0);
      expect(service.lastError, isNull);
      expect(service.lastFlushedAt, isNotNull);
    });

    test('فشل الرفع يُبقي الأحداث كما هي ويُسجّل السبب', () async {
      final store = InMemoryAuditStore();
      var failing = true;
      final service = AuditLogService(
        store: store,
        upload: (_) async {
          if (failing) throw StateError('الشبكة مقطوعة');
        },
      );

      await service.record('cash_session.opened');
      await service.record('cash_session.closed');

      expect(await service.flush(), 0);
      expect(service.pendingCount, 2);
      expect(service.lastError, contains('الشبكة مقطوعة'));
      expect((await store.pending()).length, 2);

      failing = false;
      expect(await service.flush(), 2);
      expect(await store.pending(), isEmpty);
      expect(service.lastError, isNull);
    });

    test('الحمولة المرسلة للخادم لا تحمل المعرّف المحلي ولا الفاعل', () async {
      final store = InMemoryAuditStore();
      List<Map<String, dynamic>>? captured;
      final service = AuditLogService(
        store: store,
        deviceId: () => 'device-abc',
        upload: (events) async => captured = events,
      );

      await service.record(
        'payment.void_requested',
        entityType: 'Payment',
        entityId: 12,
        after: <String, dynamic>{'reason': 'سند مكرر'},
      );
      await service.flush();

      final wire = captured!.single;
      expect(wire.containsKey('id'), isFalse);
      expect(wire.containsKey('actor_user_id'), isFalse);
      expect(wire['event'], 'payment.void_requested');
      expect(wire['entity_id'], '12');
      expect(wire['device_id'], 'device-abc');
      expect(wire['after'], <String, dynamic>{'reason': 'سند مكرر'});
    });

    test('record لا يرمي استثناءً لو تعطّل التخزين', () async {
      final service = AuditLogService(
        store: _FailingStore(),
        upload: (_) async {},
      );

      final event = await service.record('cash_session.opened');

      expect(event, isNull);
      expect(service.lastError, contains('تعذّر حفظ حدث التدقيق'));
    });

    test('record يتجاهل الأسماء الفارغة', () async {
      final store = InMemoryAuditStore();
      final service = AuditLogService(store: store, upload: (_) async {});

      expect(await service.record('   '), isNull);
      expect(await store.pending(), isEmpty);
    });
  });

  group('SharedPreferencesAuditStore', () {
    setUp(() => SharedPreferences.setMockInitialValues(<String, Object>{}));

    test('يحتفظ بآخر maxEvents فقط', () async {
      final store = SharedPreferencesAuditStore(maxEvents: 2);
      await store.save(<LocalAuditEvent>[
        _event('a'),
        _event('b'),
        _event('c'),
      ]);

      final pending = await store.pending();
      expect(pending.map((e) => e.id), <String>['b', 'c']);
    });

    test('صف تالف لا يُسقط بقية السجل', () async {
      SharedPreferences.setMockInitialValues(<String, Object>{
        SharedPreferencesAuditStore.storageKey:
            '[{"id":"x","event":"ok"},"صف تالف"]',
      });
      final store = SharedPreferencesAuditStore();

      final pending = await store.pending();

      expect(pending.single.id, 'x');
      expect(pending.single.event, 'ok');
    });

    test('JSON تالف كلياً يعود قائمة فارغة', () async {
      SharedPreferences.setMockInitialValues(<String, Object>{
        SharedPreferencesAuditStore.storageKey: '{ليس مصفوفة',
      });
      final store = SharedPreferencesAuditStore();

      expect(await store.pending(), isEmpty);
    });

    test('السجل الفارغ يعود قائمة فارغة لا استثناء', () async {
      final store = SharedPreferencesAuditStore();
      expect(await store.pending(), isEmpty);
    });
  });
}

LocalAuditEvent _event(String id) => LocalAuditEvent(
  id: id,
  event: 'cash_session.opened',
  occurredAt: DateTime(2026, 10, 24, 9),
);

class _FailingStore implements AuditEventStore {
  @override
  Future<List<LocalAuditEvent>> pending() async => <LocalAuditEvent>[];

  @override
  Future<void> save(List<LocalAuditEvent> events) async =>
      throw StateError('التخزين غير متاح');
}
