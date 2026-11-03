import 'dart:io';

import 'package:drift/drift.dart' hide isNull;
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/database/app_database.dart';

/// ترحيل Drift إلى v4 مع حفظ بيانات الأجهزة القديمة.
///
/// الاختبار يبني قاعدة **بإصدار v1** كما كانت على أجهزة الميدان فعلاً (بلا
/// `owner_user_id` ولا `next_attempt_at` ولا `last_status_code`)، ثم يفتحها
/// بالتطبيق الحالي ويتحقّق:
/// 1. الأعمدة الجديدة أُضيفت تلقائياً.
/// 2. صفوف الطابور القديمة **لم تُفقد**.
/// 3. البيانات المخزّنة مسبقاً (المشتركون) بقيت كما هي.
/// 4. إعادة الفتح لا تكرّر الأعمدة ولا تُفشل الترحيل.
class _V1User extends QueryExecutorUser {
  _V1User();

  @override
  int get schemaVersion => 1;

  @override
  Future<void> beforeOpen(
    QueryExecutor executor,
    OpeningDetails details,
  ) async {}
}

void main() {
  late Directory temp;

  setUp(() async {
    temp = await Directory.systemTemp.createTemp('water_drift_migration_');
  });

  tearDown(() async {
    if (temp.existsSync()) await temp.delete(recursive: true);
  });

  Future<File> createLegacyDatabase({int version = 1}) async {
    final file = File('${temp.path}/water_offline.sqlite');
    final executor = NativeDatabase(file);

    await executor.ensureOpen(_V1User());

    await executor.runCustom('''
      CREATE TABLE cached_squares (
        server_id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        description TEXT,
        updated_at TEXT
      )
    ''');
    await executor.runCustom('''
      CREATE TABLE cached_subscribers (
        server_id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        phone TEXT,
        address TEXT,
        square_id INTEGER,
        status TEXT,
        balance REAL NOT NULL DEFAULT 0,
        updated_at TEXT
      )
    ''');
    await executor.runCustom('''
      CREATE TABLE pending_sync_actions (
        id TEXT PRIMARY KEY,
        action_type TEXT NOT NULL,
        payload_json TEXT NOT NULL,
        state TEXT NOT NULL DEFAULT 'pending',
        attempt_count INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        last_error TEXT,
        server_response_json TEXT
      )
    ''');

    await executor.runCustom(
      'INSERT INTO cached_squares (server_id, name, updated_at) VALUES (?, ?, ?)',
      <Object?>[3, 'المربع الثالث', '2026-10-20T08:00:00Z'],
    );
    await executor.runCustom(
      'INSERT INTO cached_subscribers (server_id, name, square_id, balance, updated_at) '
      'VALUES (?, ?, ?, ?, ?)',
      <Object?>[77, 'مشترك قديم', 3, 1250.5, '2026-10-20T08:00:00Z'],
    );
    await executor.runCustom(
      'INSERT INTO pending_sync_actions '
      '(id, action_type, payload_json, state, attempt_count, created_at, updated_at, last_error) '
      'VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
      <Object?>[
        'legacy-action-1',
        'reading.create',
        '{"local_id":"legacy-action-1"}',
        'pending',
        2,
        '2026-10-20T08:05:00Z',
        '2026-10-20T08:06:00Z',
        'انقطاع سابق',
      ],
    );

    if (version == 3) {
      await executor.runCustom(
        'ALTER TABLE pending_sync_actions ADD COLUMN owner_user_id TEXT',
      );
      await executor.runCustom(
        'ALTER TABLE pending_sync_actions ADD COLUMN next_attempt_at TEXT',
      );
      await executor.runCustom(
        'ALTER TABLE pending_sync_actions ADD COLUMN last_status_code INTEGER',
      );
    }
    await executor.runCustom('PRAGMA user_version = $version');
    await executor.close();
    return file;
  }

  test('v1 → v4 يضيف الأعمدة الجديدة ويحفظ الطابور والبيانات', () async {
    final file = await createLegacyDatabase();
    final db = AppDatabase(NativeDatabase(file));

    // القراءة الأولى تُشغّل الترحيل (drift يقارن user_version بالإصدار الحالي).
    final columns = await db
        .customSelect('PRAGMA table_info(pending_sync_actions)')
        .get();
    final names = columns.map((row) => row.data['name']).toSet();

    expect(
      names,
      containsAll(<String>[
        'owner_user_id',
        'next_attempt_at',
        'last_status_code',
      ]),
    );

    final queue = await db.getPendingActions();
    expect(queue, hasLength(1));
    final row = queue.single;
    expect(row['id'], 'legacy-action-1');
    expect(row['attempt_count'], 2);
    expect(row['state'], 'pending');
    expect(row['next_attempt_at'], isNull);
    expect(row['last_status_code'], isNull);
    expect(row['owner_user_id'], isNull);

    final subscribers = await db.getCachedSubscribers();
    expect(subscribers.single['name'], 'مشترك قديم');
    expect(subscribers.single['balance'], 1250.5);
    final squares = await db.getCachedSquares();
    expect(squares.single['name'], 'المربع الثالث');
    expect(squares.single['code'], isNull);

    await db.close();
  });

  test('إعادة الفتح لا تكرّر الأعمدة ولا تُفقد الصفوف', () async {
    final file = await createLegacyDatabase();

    final first = AppDatabase(NativeDatabase(file));
    await first.getPendingActions();
    await first.close();

    final second = AppDatabase(NativeDatabase(file));
    final version = await second
        .customSelect('PRAGMA user_version')
        .getSingle();
    expect(version.data['user_version'], 4);

    final columns = await second
        .customSelect('PRAGMA table_info(pending_sync_actions)')
        .get();
    expect(
      columns.where((row) => row.data['name'] == 'next_attempt_at').length,
      1,
    );

    final queue = await second.getPendingActions();
    expect(queue.single['id'], 'legacy-action-1');
    await second.close();
  });

  test('v3 → v4 يحفظ الصفوف ويضيف معرفاً فارغاً دون توليده', () async {
    final file = await createLegacyDatabase(version: 3);
    final db = AppDatabase(NativeDatabase(file));
    final squares = await db.getCachedSquares();
    expect(squares.single['server_id'], 3);
    expect(squares.single['name'], 'المربع الثالث');
    expect(squares.single['code'], isNull);
    expect((await db.getPendingActions()).single['attempt_count'], 2);
    await db.cacheSquares([
      {'id': 3, 'name': 'المربع الثالث', 'code': 'Ab / الحارة'},
    ]);
    await db.close();
    final reopened = AppDatabase(NativeDatabase(file));
    expect((await reopened.getCachedSquares()).single['code'], 'Ab / الحارة');
    final columns = await reopened
        .customSelect('PRAGMA table_info(cached_squares)')
        .get();
    expect(columns.where((row) => row.data['name'] == 'code'), hasLength(1));
    await reopened.close();
  });

  test(
    'المعرف يحتفظ بقيمته عبر التخزين والنسخ والاستعادة والنسخ القديمة',
    () async {
      final db = AppDatabase.inMemory();
      await db.cacheSquares([
        {'id': 1, 'name': 'اسم المالك', 'code': 'Ab / الحارة'},
        {'id': 2, 'name': 'اسم قديم'},
      ]);
      final backup = await db.exportFieldData();
      await db.close();
      final restored = AppDatabase.inMemory();
      addTearDown(restored.close);
      await restored.importFieldData(backup);
      final rows = await restored.getCachedSquares();
      expect(
        rows.singleWhere((row) => row['server_id'] == 1)['code'],
        'Ab / الحارة',
      );
      expect(rows.singleWhere((row) => row['server_id'] == 2)['code'], isNull);
      await restored.importFieldData({
        'squares': [
          {'server_id': 3, 'name': 'نسخة قديمة'},
        ],
      });
      expect((await restored.getCachedSquares()).single['code'], isNull);
      expect((await restored.getCachedSquares()).single['name'], 'نسخة قديمة');
    },
  );
}
