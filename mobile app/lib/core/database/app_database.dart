import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:isolate';

import 'package:drift/drift.dart';
import 'package:drift/native.dart';
import 'package:path_provider/path_provider.dart';
import 'package:sqlite3/sqlite3.dart' as sqlite3;

import '../models/square_label.dart';
import 'database_encryption.dart';
import 'retired_cache_cleanup.dart';

/// Drift-backed SQLite store for the field/offline features.
///
/// The schema is deliberately managed with SQL migrations rather than generated
/// table classes. This keeps the shared offline contract stable while the other
/// mobile feature branches are being integrated, and still uses Drift's query
/// executor, isolate-backed SQLite engine, transactions, and bound variables.
class AppDatabase extends GeneratedDatabase {
  AppDatabase(super.executor);

  /// اسم ملف قاعدة الميدان في مجلد دعم التطبيق.
  static const fileName = 'water_offline.sqlite';

  /// يفتح قاعدة الميدان، ويشفّرها إن أعاد مزوّد المفتاح مفتاحاً (S5-02).
  ///
  /// الافتراضي بلا مفتاح حتى لا تتغيّر سلوكيات الاختبارات ودورات التطوير؛
  /// التشفير الفعلي يُطلب بـ`--dart-define=ENCRYPT_LOCAL_DB=true` مع
  /// `SecureStorageDatabaseKeyProvider`.
  static Future<AppDatabase> open({
    DatabaseKeyProvider keyProvider = const NoopDatabaseKeyProvider(),
  }) async {
    final key = await keyProvider.key();
    return AppDatabase(_openConnection(key));
  }

  factory AppDatabase.inMemory() => AppDatabase(NativeDatabase.memory());

  /// فتح ملف محدد بمفتاح صريح — يُستخدم في اختبارات التشفير.
  factory AppDatabase.openFile(File file, {String? key}) =>
      AppDatabase(LazyDatabase(() => _executorForFile(file, key)));

  final StreamController<void> _syncQueueChanges = StreamController<void>.broadcast();

  /// Emits whenever `pending_sync_actions` is mutated so `SyncQueueManager`
  /// and UI badges (`HomeScreen`, `SCR-28`) stay in sync immediately.
  Stream<void> get onSyncQueueChanged => _syncQueueChanges.stream;

  void _notifySyncQueueChanged() {
    if (!_syncQueueChanges.isClosed) {
      _syncQueueChanges.add(null);
    }
  }

  @override
  int get schemaVersion => 5;

  @override
  Iterable<TableInfo> get allTables => const <TableInfo>[];

  @override
  MigrationStrategy get migration => MigrationStrategy(
        onCreate: (_) async {
          for (final statement in _schemaStatements) {
            await customStatement(statement);
          }
        },
        onUpgrade: (_, from, to) async {
          // Keep migrations explicit here. Bump schemaVersion and add
          // version-specific `ALTER TABLE` statements when the contract grows.
          if (from < 1 && to >= 1) {
            for (final statement in _schemaStatements) {
              await customStatement(statement);
            }
          }
          if (from < 2 && to >= 2) {
            final columns = await _select('PRAGMA table_info(pending_sync_actions)');
            final hasOwner = columns.any((col) => col['name'] == 'owner_user_id');
            if (!hasOwner) {
              await customStatement(
                'ALTER TABLE pending_sync_actions ADD COLUMN owner_user_id TEXT',
              );
            }
          }
          if (from < 3 && to >= 3) {
            // v3: مهلة إعادة المحاولة وآخر رمز HTTP — يستخدمهما مدير المزامنة
            // لتفادي محاولات متلاحقة على خطأ مؤقت (خطة 2.0 — الفصل 8.2).
            final columns = await _select('PRAGMA table_info(pending_sync_actions)');
            final names = columns.map((col) => col['name']).toSet();
            if (!names.contains('next_attempt_at')) {
              await customStatement(
                'ALTER TABLE pending_sync_actions ADD COLUMN next_attempt_at TEXT',
              );
            }
            if (!names.contains('last_status_code')) {
              await customStatement(
                'ALTER TABLE pending_sync_actions ADD COLUMN last_status_code INTEGER',
              );
            }
          }
          if (from < 4 && to >= 4) {
            final columns = await _select('PRAGMA table_info(cached_squares)');
            if (!columns.any((column) => column['name'] == 'code')) {
              await customStatement('ALTER TABLE cached_squares ADD COLUMN code TEXT');
            }
          }

          if (from < 5 && to >= 5) {
            // Rebuild rather than hiding removed columns: preserve all valid
            // offline reading periods while eliminating obsolete feature data.
            await customStatement('''
              CREATE TABLE cached_meter_periods_clean (
                meter_id INTEGER PRIMARY KEY,
                last_reading REAL NOT NULL DEFAULT 0,
                period_start TEXT NOT NULL,
                period_end TEXT NOT NULL,
                auto_issue_invoice INTEGER NOT NULL DEFAULT 0,
                has_subscription_fee INTEGER NOT NULL DEFAULT 0,
                subscription_installment REAL NOT NULL DEFAULT 0,
                cached_at TEXT NOT NULL
              )
            ''');
            await customStatement('''
              INSERT INTO cached_meter_periods_clean (
                meter_id, last_reading, period_start, period_end,
                auto_issue_invoice, has_subscription_fee,
                subscription_installment, cached_at
              )
              SELECT meter_id, last_reading, period_start, period_end,
                     auto_issue_invoice, has_subscription_fee,
                     subscription_installment, cached_at
              FROM cached_meter_periods
            ''');
            await customStatement('DROP TABLE cached_meter_periods');
            await customStatement(
              'ALTER TABLE cached_meter_periods_clean RENAME TO cached_meter_periods',
            );
            await purgeRetiredOfflineFields(this);
          }
        },
        beforeOpen: (_) async {
          await customStatement('PRAGMA foreign_keys = ON');
        },
      );

  static LazyDatabase _openConnection(String? key) {
    return LazyDatabase(() async {
      final directory = await getApplicationSupportDirectory();
      final file = File('${directory.path}${Platform.pathSeparator}$fileName');
      return _executorForFile(file, key);
    });
  }

  /// ينشئ المنفّذ، ويشفّر ملفاً قديماً غير مشفّر قبل فتحه بمفتاح.
  static Future<QueryExecutor> _executorForFile(File file, String? key) async {
    if (key == null || key.trim().isEmpty) {
      return NativeDatabase.createInBackground(file);
    }
    if (await isPlaintextSqliteFile(file)) {
      await _encryptLegacyFile(file, key);
    }
    return NativeDatabase.createInBackground(
      file,
      setup: (database) {
        // `PRAGMA key` يجب أن يسبق أي استعلام على الملف المشفّر.
        database.execute(pragmaKeyStatement(key));
      },
    );
  }

  /// تشفير ملف SQLite قائم بلا فقدان صفوف (SQLCipher: `PRAGMA rekey`).
  static Future<void> _encryptLegacyFile(File file, String key) async {
    final path = file.path;
    await Isolate.run(() {
      final database = sqlite3.sqlite3.open(path);
      try {
        database.execute(pragmaRekeyStatement(key));
      } finally {
        database.dispose();
      }
    });
  }

  static const List<String> _schemaStatements = [
    '''CREATE TABLE IF NOT EXISTS cached_squares (
      server_id INTEGER PRIMARY KEY,
      name TEXT NOT NULL,
      code TEXT,
      description TEXT,
      updated_at TEXT
    )''',
    '''CREATE TABLE IF NOT EXISTS cached_subscribers (
      server_id INTEGER PRIMARY KEY,
      name TEXT NOT NULL,
      phone TEXT,
      address TEXT,
      square_id INTEGER,
      square_name TEXT,
      status TEXT NOT NULL DEFAULT 'active',
      subscription_start_date TEXT,
      balance REAL NOT NULL DEFAULT 0,
      meters_count INTEGER NOT NULL DEFAULT 0
    )''',
    '''CREATE TABLE IF NOT EXISTS cached_meters (
      server_id INTEGER PRIMARY KEY,
      unique_code TEXT NOT NULL UNIQUE,
      path_number INTEGER,
      subscriber_id INTEGER,
      subscriber_name TEXT,
      square_id INTEGER,
      square_name TEXT,
      subscription_start_date TEXT,
      status TEXT NOT NULL DEFAULT 'active',
      balance REAL NOT NULL DEFAULT 0,
      initial_reading REAL NOT NULL DEFAULT 0,
      last_reading REAL NOT NULL DEFAULT 0,
      installed_at TEXT,
      removed_at TEXT
    )''',
    '''CREATE INDEX IF NOT EXISTS cached_meters_square_path_idx
      ON cached_meters(square_id, path_number)''',
    '''CREATE INDEX IF NOT EXISTS cached_meters_subscriber_idx
      ON cached_meters(subscriber_id)''',
    '''CREATE TABLE IF NOT EXISTS cached_meter_periods (
      meter_id INTEGER PRIMARY KEY,
      last_reading REAL NOT NULL DEFAULT 0,
      period_start TEXT NOT NULL,
      period_end TEXT NOT NULL,
      auto_issue_invoice INTEGER NOT NULL DEFAULT 0,
      has_subscription_fee INTEGER NOT NULL DEFAULT 0,
      subscription_installment REAL NOT NULL DEFAULT 0,
      cached_at TEXT NOT NULL
    )''',
    '''CREATE TABLE IF NOT EXISTS cached_readings (
      local_id TEXT PRIMARY KEY,
      server_id INTEGER UNIQUE,
      meter_id INTEGER NOT NULL,
      reading_date TEXT NOT NULL,
      actual_reading_date TEXT,
      period_start TEXT NOT NULL,
      period_end TEXT NOT NULL,
      previous_reading REAL NOT NULL DEFAULT 0,
      current_reading REAL NOT NULL,
      consumption_units REAL NOT NULL DEFAULT 0,
      is_invoiced INTEGER NOT NULL DEFAULT 0,
      invoice_id INTEGER,
      image_path TEXT,
      sync_error TEXT,
      updated_at TEXT NOT NULL
    )''',
    '''CREATE INDEX IF NOT EXISTS cached_readings_meter_period_idx
      ON cached_readings(meter_id, period_end DESC)''',
    '''CREATE INDEX IF NOT EXISTS cached_readings_invoice_idx
      ON cached_readings(is_invoiced, reading_date DESC)''',
    '''CREATE TABLE IF NOT EXISTS pending_sync_actions (
      id TEXT PRIMARY KEY,
      action_type TEXT NOT NULL,
      payload_json TEXT NOT NULL,
      state TEXT NOT NULL DEFAULT 'pending',
      attempt_count INTEGER NOT NULL DEFAULT 0,
      created_at TEXT NOT NULL,
      updated_at TEXT NOT NULL,
      last_error TEXT,
      server_response_json TEXT,
      owner_user_id TEXT,
      next_attempt_at TEXT,
      last_status_code INTEGER
    )''',
    '''CREATE INDEX IF NOT EXISTS pending_sync_state_created_idx
      ON pending_sync_actions(state, created_at)''',
    '''CREATE TABLE IF NOT EXISTS cached_settings (
      setting_key TEXT PRIMARY KEY,
      setting_value TEXT NOT NULL,
      updated_at TEXT NOT NULL
    )''',
  ];

  Future<List<Map<String, Object?>>> _select(
    String sql, [
    List<Variable> variables = const <Variable>[],
  ]) async {
    final rows = await customSelect(sql, variables: variables).get();
    return rows.map((row) => Map<String, Object?>.from(row.data)).toList(growable: false);
  }

  Future<void> _execute(String sql, [List<Object?>? arguments]) =>
      customStatement(sql, arguments);

  String _now() => DateTime.now().toUtc().toIso8601String();

  String? _date(Object? value) {
    if (value == null) return null;
    final text = value.toString();
    return text.contains('T') ? text.split('T').first : text;
  }

  String? _endOfMonthDate(Object? value) {
    final date = DateTime.tryParse(_date(value) ?? '');
    if (date == null) return null;
    return DateTime(date.year, date.month + 1, 0).toIso8601String().split('T').first;
  }

  int? _int(Object? value) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse(value?.toString() ?? '');
  }

  double _double(Object? value) {
    if (value is num) return value.toDouble();
    return double.tryParse(value?.toString() ?? '') ?? 0;
  }

  bool _bool(Object? value) =>
      value == true || value == 1 || value?.toString().toLowerCase() == 'true';

  Future<void> cacheSquares(Iterable<Map<String, dynamic>> squares) async {
    await transaction(() async {
      for (final square in squares) {
        final id = _int(square['id'] ?? square['server_id']);
        if (id == null) continue;
        await _execute(
          '''INSERT OR REPLACE INTO cached_squares(server_id, name, code, description, updated_at)
             VALUES (?, ?, ?, ?, ?)''',
          [id, (square['name'] ?? '').toString(), square['code'], square['description'], _now()],
        );
      }
    });
  }

  Future<List<Map<String, Object?>>> getCachedSquares() =>
      _select('SELECT * FROM cached_squares ORDER BY name COLLATE NOCASE');

  Future<void> cacheSubscribers(Iterable<Map<String, dynamic>> subscribers) async {
    await transaction(() async {
      for (final subscriber in subscribers) {
        final id = _int(subscriber['id'] ?? subscriber['server_id']);
        if (id == null) continue;
        final square = _asMap(subscriber['square']);
        final meters = subscriber['meters'];
        // Historical meter records must not inflate the operational badge.
        // In the absence of an explicit count, resolve the one current meter.
        final serverCount = _int(subscriber['meters_count']);
        final hasCurrent = subscriber.containsKey('current_meter_id')
            ? _int(subscriber['current_meter_id']) != null
            : subscriber.containsKey('current_meter')
                ? subscriber['current_meter'] is Map
                : meters is List
                    ? meters.any((meter) =>
                        meter is Map &&
                        meter['removed_at'] == null &&
                        meter['deleted_at'] == null)
                    : (serverCount ?? 0) > 0;
        final currentMeterCount = hasCurrent ? 1 : 0;
        await _execute(
          '''INSERT OR REPLACE INTO cached_subscribers(
             server_id, name, phone, address, square_id, square_name, status,
             subscription_start_date, balance, meters_count
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)''',
          [
            id,
            (subscriber['name'] ?? '').toString(),
            subscriber['phone'],
            subscriber['address'],
            _int(subscriber['square_id'] ?? square?['id']),
            squareLabelOrNull(subscriber['square_name'] ?? square?['name'], subscriber['square_code'] ?? square?['code']),
            (subscriber['status'] ?? 'active').toString(),
            _date(subscriber['subscription_start_date']),
            _double(subscriber['balance'] ?? subscriber['account_balance']),
            currentMeterCount,
          ],
        );
      }
    });
  }

  Future<List<Map<String, Object?>>> getCachedSubscribers({String? query}) async {
    final normalized = query?.trim();
    if (normalized == null || normalized.isEmpty) {
      return _select('SELECT * FROM cached_subscribers ORDER BY name COLLATE NOCASE');
    }
    final like = '%$normalized%';
    return _select(
      '''SELECT * FROM cached_subscribers
         WHERE name LIKE ? OR phone LIKE ? ORDER BY name COLLATE NOCASE''',
      [Variable.withString(like), Variable.withString(like)],
    );
  }

  Future<void> cacheMeters(
    Iterable<Map<String, dynamic>> meters, {
    bool fullSnapshot = false,
  }) async {
    final rows = meters.toList(growable: false);
    // Only an unfiltered, fully paginated server response may authorize
    // eviction of IDs missing from the server. A partial search cannot.
    final authoritativeIds = <int>{};
    if (fullSnapshot) {
      for (final meter in rows) {
        final id = _int(meter['id'] ?? meter['server_id']);
        if (id == null || id <= 0) {
          throw const FormatException('Invalid meter ID in authoritative snapshot');
        }
        authoritativeIds.add(id);
      }
    }
    await transaction(() async {
      if (fullSnapshot) {
        final cached = await _select('SELECT server_id FROM cached_meters');
        for (final row in cached) {
          final cachedId = _int(row['server_id']);
          if (cachedId == null || authoritativeIds.contains(cachedId)) continue;
          await _execute('DELETE FROM cached_meter_periods WHERE meter_id = ?', [cachedId]);
          await _execute('DELETE FROM cached_readings WHERE meter_id = ?', [cachedId]);
          await _execute('DELETE FROM cached_meters WHERE server_id = ?', [cachedId]);
        }
      }
      for (final meter in rows) {
        final id = _int(meter['id'] ?? meter['server_id']);
        if (id == null) continue;
        final subscriberId = _int(meter['subscriber_id'] ??
            _asMap(meter['subscriber'])?['id']);
        // Server contract: one physical meter per subscriber. Receiving a
        // new meter must evict stale older IDs and their reading caches even
        // when the replacement originated on another device.
        if (subscriberId != null && meter['removed_at'] == null) {
          final oldRows = await _select(
            'SELECT server_id FROM cached_meters WHERE subscriber_id = ? AND server_id <> ?',
            [Variable.withInt(subscriberId), Variable.withInt(id)],
          );
          for (final old in oldRows) {
            final oldId = _int(old['server_id']);
            if (oldId == null) continue;
            await _execute(
              'DELETE FROM cached_meter_periods WHERE meter_id = ?',
              [oldId],
            );
            await _execute(
              'DELETE FROM cached_readings WHERE meter_id = ?',
              [oldId],
            );
            await _execute(
              'DELETE FROM cached_meters WHERE server_id = ?',
              [oldId],
            );
          }
        }
        final subscriber = _asMap(meter['subscriber']);
        final square = _asMap(meter['square'] ?? subscriber?['square']);
        final lastReading = meter['last_reading'];
        final lastReadingMap = _asMap(lastReading);
        await _execute(
          '''INSERT OR REPLACE INTO cached_meters(
             server_id, unique_code, path_number, subscriber_id, subscriber_name,
             square_id, square_name, subscription_start_date,
             status, balance, initial_reading, last_reading, installed_at, removed_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)''',
          [
            id,
            (meter['unique_code'] ?? '').toString(),
            _int(meter['path_number']),
            _int(meter['subscriber_id'] ?? subscriber?['id']),
            meter['subscriber_name'] ?? subscriber?['name'],
            _int(meter['square_id'] ?? subscriber?['square_id'] ?? square?['id']),
            squareLabelOrNull(meter['square_name'] ?? square?['name'], meter['square_code'] ?? square?['code']),
            _date(meter['subscription_start_date'] ?? subscriber?['subscription_start_date']),
            (meter['status'] ?? 'active').toString(),
            _double(meter['balance']),
            _double(meter['initial_reading']),
            _double(meter['last_reading_value'] ??
                lastReadingMap?['current_reading'] ??
                (lastReading is num ? lastReading : 0)),
            _date(meter['installed_at']),
            _date(meter['removed_at']),
          ],
        );
      }
    });
  }

  Future<List<Map<String, Object?>>> getCachedMeters({
    int? squareId,
    String? status,
    String? query,
  }) async {
    final conditions = <String>[];
    final variables = <Variable>[];
    if (squareId != null) {
      conditions.add('square_id = ?');
      variables.add(Variable.withInt(squareId));
    }
    if (status != null && status.isNotEmpty) {
      conditions.add('status = ?');
      variables.add(Variable.withString(status));
    }
    final normalized = query?.trim();
    if (normalized != null && normalized.isNotEmpty) {
      final like = '%$normalized%';
      conditions.add('''(unique_code LIKE ? OR CAST(path_number AS TEXT) LIKE ?
          OR subscriber_name LIKE ?)''');
      variables.addAll(List<Variable>.filled(3, Variable.withString(like)));
    }
    final where = conditions.isEmpty ? '' : 'WHERE ${conditions.join(' AND ')}';
    return _select(
      '''SELECT * FROM cached_meters $where
         ORDER BY CASE WHEN path_number IS NULL THEN 1 ELSE 0 END, path_number, unique_code''',
      variables,
    );
  }

  Future<Map<String, Object?>?> getCachedMeter(int meterId) async {
    final rows = await _select(
      'SELECT * FROM cached_meters WHERE server_id = ?',
      [Variable.withInt(meterId)],
    );
    return rows.isEmpty ? null : rows.first;
  }

  /// An accepted replacement physically removes the old meter on the server.
  /// Remove its stale local caches too. Pending sync actions stay in the queue
  /// so the server can reject obsolete readings visibly as non-retryable.
  Future<void> evictMeterCache(int meterId) async {
    await transaction(() async {
      await _execute('DELETE FROM cached_meter_periods WHERE meter_id = ?', [meterId]);
      await _execute('DELETE FROM cached_readings WHERE meter_id = ?', [meterId]);
      await _execute('DELETE FROM cached_meters WHERE server_id = ?', [meterId]);
    });
  }

  Future<void> cacheMeterPeriod(int meterId, Map<String, dynamic> period) async {
    await _execute(
      '''INSERT OR REPLACE INTO cached_meter_periods(
        meter_id, last_reading, period_start, period_end, auto_issue_invoice,
        has_subscription_fee, subscription_installment, cached_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)''',
      [
        meterId,
        _double(period['last_reading']),
        _date(period['period_start']) ?? DateTime.now().toIso8601String().split('T').first,
        _date(period['period_end']) ?? DateTime.now().toIso8601String().split('T').first,
        _bool(period['auto_issue_invoice']) ? 1 : 0,
        _bool(period['has_subscription_fee']) ? 1 : 0,
        _double(period['subscription_installment']),
        _now(),
      ],
    );
  }

  Future<Map<String, Object?>?> getCachedMeterPeriod(int meterId) async {
    final rows = await _select(
      'SELECT * FROM cached_meter_periods WHERE meter_id = ?',
      [Variable.withInt(meterId)],
    );
    return rows.isEmpty ? null : rows.first;
  }

  /// بيانات الميدان المحلية كاملةً — تُستخدم في النسخ الاحتياطي المحلي (S7-08).
  ///
  /// نُصدّر الصفوف كما هي في الجداول (لا نماذج ناقصة) حتى تعود النسخة مطابقة:
  /// المزامنة تعتمد على `local_id` و`server_id` معاً، وأي تحويل يفقد أحدهما
  /// يُنشئ صفوفاً مكرّرة عند الاستعادة.
  Future<Map<String, dynamic>> exportFieldData() async => <String, dynamic>{
        'squares': await _select('SELECT * FROM cached_squares ORDER BY server_id'),
        'subscribers': await _select('SELECT * FROM cached_subscribers ORDER BY server_id'),
        'meters': await _select('SELECT * FROM cached_meters ORDER BY server_id'),
        'meter_periods': await _select('SELECT * FROM cached_meter_periods ORDER BY meter_id'),
        'readings': await _select('SELECT * FROM cached_readings ORDER BY local_id'),
        'pending_actions': await _select('SELECT * FROM pending_sync_actions ORDER BY created_at'),
        'settings': await _select('SELECT * FROM cached_settings ORDER BY setting_key'),
      };

  /// استعادة نسخة محلية.
  ///
  /// الجداول الموجودة في `data` تُستبدل بالكامل (حذف ثم إدراج) داخل معاملة
  /// واحدة، فلا يبقى نصف استعادة عند الفشل. الجداول الغائبة تُترك كما هي —
  /// نسخة جزئية لا يجوز أن تمحو ما ليس فيها.
  ///
  /// [pendingActions] يتحكم في عمليات المزامنة غير المرسلة: استعادتها مفيدة
  /// (قراءات ميدانية لم تُرسل بعد) لكنها قد تُكرّر عملية أُرسلت من الجهاز
  /// الأصلي بعد أخذ النسخة؛ لذلك يقرّره المستخدم في الواجهة.
  Future<void> importFieldData(
    Map<String, dynamic> data, {
    bool pendingActions = true,
  }) async {
    const tables = <String, List<String>>{
      'squares': <String>['server_id', 'name', 'code', 'description', 'updated_at'],
      'subscribers': <String>[
        'server_id',
        'name',
        'phone',
        'address',
        'square_id',
        'square_name',
        'status',
        'subscription_start_date',
        'balance',
        'meters_count',
      ],
      'meters': <String>[
        'server_id',
        'unique_code',
        'path_number',
        'subscriber_id',
        'subscriber_name',
        'square_id',
        'square_name',
        'subscription_start_date',
        'status',
        'balance',
        'initial_reading',
        'last_reading',
        'installed_at',
        'removed_at',
      ],
      'meter_periods': <String>[
        'meter_id',
        'last_reading',
        'period_start',
        'period_end',
        'auto_issue_invoice',
        'has_subscription_fee',
        'subscription_installment',
        'cached_at',
      ],
      'readings': <String>[
        'local_id',
        'server_id',
        'meter_id',
        'reading_date',
        'actual_reading_date',
        'period_start',
        'period_end',
        'previous_reading',
        'current_reading',
        'consumption_units',
        'is_invoiced',
        'invoice_id',
        'image_path',
        'sync_error',
        'updated_at',
      ],
      'settings': <String>['setting_key', 'setting_value', 'updated_at'],
    };

    await transaction(() async {
      // Never replace the field cache while this device has unsent work.
      final active = await _select(
        'SELECT 1 FROM pending_sync_actions WHERE state IN '
        "('pending', 'retrying', 'syncing', 'failed', 'needs_attention') LIMIT 1",
      );
      final localReadings = await _select(
        'SELECT 1 FROM cached_readings WHERE server_id IS NULL LIMIT 1',
      );
      if (active.isNotEmpty || localReadings.isNotEmpty) {
        throw StateError(
          'يوجد عمل ميداني غير مرسل على هذا الجهاز. زامنه أو عالجه قبل استعادة النسخة حتى لا تُفقد البيانات.',
        );
      }
      for (final entry in tables.entries) {
        final rows = data[entry.key];
        if (rows is! List) continue;
        await _execute('DELETE FROM ${_tableName(entry.key)}');
        final columns = entry.value;
        final placeholders = List<String>.filled(columns.length, '?').join(', ');
        final statement =
            'INSERT OR REPLACE INTO ${_tableName(entry.key)}(${columns.join(', ')}) '
            'VALUES ($placeholders)';
        for (final row in rows.whereType<Map>()) {
          await _execute(
            statement,
            columns.map((column) => row[column]).toList(growable: false),
          );
        }
      }

      if (pendingActions) {
        final actions = data['pending_actions'];
        if (actions is List) {
          await _execute("DELETE FROM pending_sync_actions WHERE state != 'synced'");
          for (final action in actions.whereType<Map>()) {
            final id = action['id']?.toString();
            if (id == null || id.isEmpty) continue;
            await _execute(
              '''INSERT OR REPLACE INTO pending_sync_actions(
                 id, action_type, payload_json, state, attempt_count, created_at,
                 updated_at, last_error, server_response_json, owner_user_id,
                 next_attempt_at, last_status_code
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)''',
              [
                id,
                action['action_type'] ?? action['type'] ?? 'unknown',
                action['payload_json'] ?? '{}',
                action['state'] ?? 'pending',
                _int(action['attempt_count']) ?? 0,
                action['created_at'] ?? _now(),
                _now(),
                action['last_error'],
                action['server_response_json'],
                action['owner_user_id'],
                action['next_attempt_at'],
                _int(action['last_status_code']),
              ],
            );
          }
        }
      }
    });
    _notifySyncQueueChanged();
  }

  /// اسم الجدول الفعلي للمفتاح المنطقي في النسخة الاحتياطية.
  static String _tableName(String key) => switch (key) {
        'squares' => 'cached_squares',
        'subscribers' => 'cached_subscribers',
        'meters' => 'cached_meters',
        'meter_periods' => 'cached_meter_periods',
        'readings' => 'cached_readings',
        'settings' => 'cached_settings',
        _ => key,
      };

  Future<void> setCachedSetting(String key, Object? value) => _execute(
        '''INSERT OR REPLACE INTO cached_settings(setting_key, setting_value, updated_at)
           VALUES (?, ?, ?)''',
        [key, value?.toString() ?? '', _now()],
      );

  Future<String?> getCachedSetting(String key) async {
    final rows = await _select(
      'SELECT setting_value FROM cached_settings WHERE setting_key = ?',
      [Variable.withString(key)],
    );
    return rows.isEmpty ? null : rows.first['setting_value']?.toString();
  }

  Future<void> cacheReadings(Iterable<Map<String, dynamic>> readings) async {
    await transaction(() async {
      for (final reading in readings) {
        final id = _int(reading['id'] ?? reading['server_id']);
        if (id == null) continue;
        final meter = _asMap(reading['meter']);
        final localId = (reading['local_id'] ?? 'server-$id').toString();
        final previous = _double(reading['previous_reading']);
        final current = _double(reading['current_reading']);
        await _execute(
          '''INSERT OR REPLACE INTO cached_readings(
             local_id, server_id, meter_id, reading_date, actual_reading_date,
             period_start, period_end, previous_reading, current_reading,
             consumption_units, is_invoiced, invoice_id, image_path, sync_error, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)''',
          [
            localId,
            id,
            _int(reading['meter_id'] ?? meter?['id']) ?? 0,
            _date(reading['reading_date']) ?? _date(reading['period_end']) ?? _now(),
            _date(reading['actual_reading_date']),
            _date(reading['period_start']) ?? _date(reading['reading_date']) ?? _now(),
            _date(reading['period_end']) ?? _endOfMonthDate(reading['reading_date']) ?? _now(),
            previous,
            current,
            _double(reading['consumption_units'] ?? (current - previous)),
            _bool(reading['is_invoiced']) ? 1 : 0,
            _int(reading['invoice_id']),
            reading['image_path'],
            _now(),
          ],
        );
        final meterId = _int(reading['meter_id'] ?? meter?['id']);
        if (meterId != null) {
          // Readings arrive newest-first from the API; never let an older cache
          // row overwrite the meter's newer totalizer value.
          await _execute(
            '''UPDATE cached_meters SET last_reading = MAX(last_reading, ?)
               WHERE server_id = ?''',
            [current, meterId],
          );
        }
      }
    });
  }

  Future<List<Map<String, Object?>>> getCachedReadings({
    int? meterId,
    int? squareId,
    bool? isInvoiced,
    DateTime? fromDate,
    DateTime? toDate,
  }) async {
    final conditions = <String>[];
    final variables = <Variable>[];
    if (meterId != null) {
      conditions.add('r.meter_id = ?');
      variables.add(Variable.withInt(meterId));
    }
    if (squareId != null) {
      conditions.add('m.square_id = ?');
      variables.add(Variable.withInt(squareId));
    }
    if (isInvoiced != null) {
      conditions.add('r.is_invoiced = ?');
      variables.add(Variable.withInt(isInvoiced ? 1 : 0));
    }
    if (fromDate != null) {
      conditions.add('r.reading_date >= ?');
      variables.add(Variable.withString(_date(fromDate)!));
    }
    if (toDate != null) {
      conditions.add('r.reading_date <= ?');
      variables.add(Variable.withString(_date(toDate)!));
    }
    final where = conditions.isEmpty ? '' : 'WHERE ${conditions.join(' AND ')}';
    return _select(
      '''SELECT r.*, m.unique_code, m.path_number, m.square_id,
                m.square_name, m.subscriber_name
         FROM cached_readings r LEFT JOIN cached_meters m ON m.server_id = r.meter_id
         $where ORDER BY r.reading_date DESC, r.local_id DESC''',
      variables,
    );
  }

  Future<Map<String, Object?>?> getLatestCachedReading(int meterId) async {
    final rows = await _select(
      '''SELECT * FROM cached_readings WHERE meter_id = ?
         ORDER BY period_end DESC, reading_date DESC, updated_at DESC LIMIT 1''',
      [Variable.withInt(meterId)],
    );
    return rows.isEmpty ? null : rows.first;
  }

  Future<bool> hasCachedReadingPeriod({
    required int meterId,
    required DateTime periodStart,
    required DateTime periodEnd,
  }) async {
    final rows = await _select(
      '''SELECT 1 AS found FROM cached_readings
         WHERE meter_id = ? AND period_start = ? AND period_end = ? LIMIT 1''',
      [
        Variable.withInt(meterId),
        Variable.withString(_date(periodStart)!),
        Variable.withString(_date(periodEnd)!),
      ],
    );
    return rows.isNotEmpty;
  }

  Future<void> saveOfflineReading({
    required String localId,
    required Map<String, dynamic> reading,
    required String actionId,
    String? ownerUserId,
  }) async {
    final enrichedReading = <String, dynamic>{
      ...reading,
      'local_id': reading['local_id'] ?? localId,
      'client_action_id': reading['client_action_id'] ?? actionId,
      'sync_uuid': reading['sync_uuid'] ?? actionId,
    };
    final effectiveOwner = ownerUserId ?? reading['owner_user_id']?.toString();
    await transaction(() async {
      await _execute(
        '''INSERT INTO cached_readings(
          local_id, server_id, meter_id, reading_date, actual_reading_date,
          period_start, period_end, previous_reading, current_reading,
          consumption_units, is_invoiced, invoice_id, image_path, sync_error, updated_at
        ) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL, ?, NULL, ?)''',
        [
          localId,
          _int(enrichedReading['meter_id']) ?? 0,
          _date(enrichedReading['reading_date']) ?? _now(),
          _date(enrichedReading['actual_reading_date']),
          _date(enrichedReading['period_start']) ?? _now(),
          _date(enrichedReading['period_end']) ?? _now(),
          _double(enrichedReading['previous_reading']),
          _double(enrichedReading['current_reading']),
          _double(enrichedReading['consumption_units']),
          enrichedReading['image_path'],
          _now(),
        ],
      );
      await _execute(
        '''INSERT INTO pending_sync_actions(
          id, action_type, payload_json, state, attempt_count, created_at, updated_at, owner_user_id
        ) VALUES (?, 'reading.create', ?, 'pending', 0, ?, ?, ?)''',
        [actionId, _jsonEncode(enrichedReading), _now(), _now(), effectiveOwner],
      );
      await _execute(
        'UPDATE cached_meters SET last_reading = ? WHERE server_id = ?',
        [_double(enrichedReading['current_reading']), _int(enrichedReading['meter_id']) ?? 0],
      );
    });
    _notifySyncQueueChanged();
  }

  Future<void> updateCachedReading({
    required String localId,
    required Map<String, dynamic> values,
  }) async {
    await _execute(
      '''UPDATE cached_readings SET reading_date = ?, actual_reading_date = ?,
         period_start = ?, period_end = ?, previous_reading = ?, current_reading = ?,
         consumption_units = ?, image_path = COALESCE(?, image_path), updated_at = ?
         WHERE local_id = ?''',
      [
        _date(values['reading_date']),
        _date(values['actual_reading_date']),
        _date(values['period_start']),
        _date(values['period_end']),
        _double(values['previous_reading']),
        _double(values['current_reading']),
        _double(values['consumption_units']),
        values['image_path'],
        _now(),
        localId,
      ],
    );
  }

  Future<void> deleteCachedReadingByLocalId(String localId) async {
    final rows = await _select(
      'SELECT meter_id FROM cached_readings WHERE local_id = ? LIMIT 1',
      [Variable.withString(localId)],
    );
    final meterId = rows.isEmpty ? null : _int(rows.first['meter_id']);
    await transaction(() async {
      await _execute('DELETE FROM cached_readings WHERE local_id = ?', [localId]);
      // Offline readings use their local UUID as the queue action ID as well.
      await _execute(
        '''DELETE FROM pending_sync_actions WHERE action_type = 'reading.create'
           AND id = ?''',
        [localId],
      );
      if (meterId != null) await _recalculateMeterLastReading(meterId);
    });
    _notifySyncQueueChanged();
  }

  Future<void> deleteCachedReadingsByServerIds(List<int> ids) async {
    if (ids.isEmpty) return;
    final placeholders = List<String>.filled(ids.length, '?').join(',');
    final variables = ids.map(Variable.withInt).toList(growable: false);
    final affectedMeters = await _select(
      'SELECT DISTINCT meter_id FROM cached_readings WHERE server_id IN ($placeholders)',
      variables,
    );
    await transaction(() async {
      await _execute(
        'DELETE FROM cached_readings WHERE server_id IN ($placeholders)',
        ids,
      );
      for (final row in affectedMeters) {
        final meterId = _int(row['meter_id']);
        if (meterId != null) await _recalculateMeterLastReading(meterId);
      }
    });
  }

  Future<void> _recalculateMeterLastReading(int meterId) => _execute(
        '''UPDATE cached_meters SET last_reading = COALESCE(
             (SELECT current_reading FROM cached_readings
              WHERE meter_id = ?
              ORDER BY period_end DESC, reading_date DESC, updated_at DESC LIMIT 1),
             initial_reading
           ) WHERE server_id = ?''',
        [meterId, meterId],
      );

  Future<void> markReadingSynced({
    required String localId,
    required int serverId,
    bool? isInvoiced,
    int? invoiceId,
    Map<String, dynamic>? serverReading,
    String? serverResponseJson,
  }) async {
    final hasServerValue = serverReading != null;
    await transaction(() async {
      // A refresh may already have cached the same server row under `server-ID`
      // while its local queue action was awaiting acknowledgement. Keep the
      // richer local row and remove that duplicate before applying the unique ID.
      await _execute(
        'DELETE FROM cached_readings WHERE server_id = ? AND local_id != ?',
        [serverId, localId],
      );
      await _execute(
        '''UPDATE cached_readings SET server_id = ?,
           reading_date = COALESCE(?, reading_date),
           actual_reading_date = COALESCE(?, actual_reading_date),
           period_start = COALESCE(?, period_start),
           period_end = COALESCE(?, period_end),
           previous_reading = COALESCE(?, previous_reading),
           current_reading = COALESCE(?, current_reading),
           consumption_units = COALESCE(?, consumption_units),
           is_invoiced = COALESCE(?, is_invoiced),
           invoice_id = COALESCE(?, invoice_id),
           sync_error = NULL, updated_at = ? WHERE local_id = ?''',
        [
          serverId,
          _date(serverReading?['reading_date']),
          _date(serverReading?['actual_reading_date']),
          _date(serverReading?['period_start']),
          _date(serverReading?['period_end']),
          hasServerValue && serverReading.containsKey('previous_reading')
              ? _double(serverReading['previous_reading'])
              : null,
          hasServerValue && serverReading.containsKey('current_reading')
              ? _double(serverReading['current_reading'])
              : null,
          hasServerValue && serverReading.containsKey('consumption_units')
              ? _double(serverReading['consumption_units'])
              : null,
          isInvoiced == null ? null : (isInvoiced ? 1 : 0),
          invoiceId,
          _now(),
          localId,
        ],
      );
      await _execute(
        '''UPDATE pending_sync_actions SET state = 'synced', last_error = NULL,
           server_response_json = COALESCE(?, server_response_json), updated_at = ?
           WHERE id = ?''',
        [serverResponseJson, _now(), localId],
      );
      if (serverReading?.containsKey('current_reading') == true) {
        await _execute(
          '''UPDATE cached_meters SET last_reading = MAX(last_reading, ?)
             WHERE server_id = (SELECT meter_id FROM cached_readings WHERE local_id = ?)''',
          [_double(serverReading!['current_reading']), localId],
        );
      }
    });
    _notifySyncQueueChanged();
  }

  Future<void> setReadingSyncError(String localId, String error) => _execute(
        'UPDATE cached_readings SET sync_error = ?, updated_at = ? WHERE local_id = ?',
        [error, _now(), localId],
      );

  Future<void> enqueueAction({
    required String id,
    required String type,
    required String payloadJson,
    String state = 'pending',
    int attemptCount = 0,
    String? createdAt,
    String? lastError,
    String? ownerUserId,
    String? nextAttemptAt,
  }) async {
    final timestamp = createdAt ?? _now();
    await _execute(
      '''INSERT OR REPLACE INTO pending_sync_actions(
        id, action_type, payload_json, state, attempt_count, created_at, updated_at, last_error, owner_user_id, next_attempt_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)''',
      [
        id,
        type,
        payloadJson,
        state,
        attemptCount,
        timestamp,
        _now(),
        lastError,
        ownerUserId,
        nextAttemptAt,
      ],
    );
    _notifySyncQueueChanged();
  }

  Future<List<Map<String, Object?>>> getActiveSyncActions({int limit = 500}) => _select(
        '''SELECT * FROM pending_sync_actions
           WHERE state IN ('pending', 'retrying', 'syncing', 'failed', 'needs_attention')
           ORDER BY created_at ASC, id ASC LIMIT ${limit.clamp(1, 1000)}''',
      );

  Future<List<Map<String, Object?>>> getPendingActions({
    List<String> states = const ['pending', 'retrying'],
    int limit = 50,
  }) async {
    if (states.isEmpty) return const [];
    final placeholders = List<String>.filled(states.length, '?').join(',');
    return _select(
      '''SELECT * FROM pending_sync_actions WHERE state IN ($placeholders)
         ORDER BY created_at, id LIMIT ${limit.clamp(1, 500)}''',
      states.map(Variable.withString).toList(growable: false),
    );
  }

  Future<Map<String, Object?>?> getSyncAction(String id) async {
    final rows = await _select(
      'SELECT * FROM pending_sync_actions WHERE id = ? LIMIT 1',
      [Variable.withString(id)],
    );
    return rows.isEmpty ? null : rows.first;
  }

  Future<List<Map<String, Object?>>> getSyncHistory({int limit = 100}) => _select(
        '''SELECT * FROM pending_sync_actions
           ORDER BY created_at DESC LIMIT ${limit.clamp(1, 500)}''',
      );

  Future<int> getPendingActionCount() async {
    final rows = await _select(
      '''SELECT COUNT(*) AS count FROM pending_sync_actions
         WHERE state IN ('pending', 'retrying', 'syncing', 'failed')''',
    );
    return rows.isEmpty ? 0 : (_int(rows.first['count']) ?? 0);
  }

  Future<void> updateSyncAction({
    required String id,
    required String state,
    int? attemptCount,
    String? lastError,
    String? serverResponseJson,
    String? ownerUserId,
    String? nextAttemptAt,
    bool clearNextAttempt = false,
    int? lastStatusCode,
  }) async {
    await _execute(
      '''UPDATE pending_sync_actions SET state = ?,
        attempt_count = COALESCE(?, attempt_count), last_error = ?,
        server_response_json = COALESCE(?, server_response_json),
        owner_user_id = COALESCE(?, owner_user_id),
        updated_at = ?
        WHERE id = ?''',
      [state, attemptCount, lastError, serverResponseJson, ownerUserId, _now(), id],
    );
    // مهلة المحاولة التالية ورمز الخطأ يحتاجان إلى مسح صريح، ولهذا لا
    // يستخدمان COALESCE مثل بقية الأعمدة.
    if (clearNextAttempt) {
      await _execute(
        'UPDATE pending_sync_actions SET next_attempt_at = NULL WHERE id = ?',
        [id],
      );
    } else if (nextAttemptAt != null) {
      await _execute(
        'UPDATE pending_sync_actions SET next_attempt_at = ? WHERE id = ?',
        [nextAttemptAt, id],
      );
    }
    if (lastStatusCode != null) {
      await _execute(
        'UPDATE pending_sync_actions SET last_status_code = ? WHERE id = ?',
        [lastStatusCode, id],
      );
    }
    _notifySyncQueueChanged();
  }

  Future<void> updateSyncActionPayload({
    required String id,
    required String payloadJson,
  }) async {
    await _execute(
      '''UPDATE pending_sync_actions SET payload_json = ?, updated_at = ?
         WHERE id = ?''',
      [payloadJson, _now(), id],
    );
    _notifySyncQueueChanged();
  }

  Future<void> removeSyncAction(String id) async {
    await _execute(
      'DELETE FROM pending_sync_actions WHERE id = ?',
      [id],
    );
    _notifySyncQueueChanged();
  }

  /// Clears server-cached snapshots when switching to a different backend server.
  /// Must only be called after confirming `getPendingActionCount() == 0`.
  Future<void> clearCachedServerData() async {
    await transaction(() async {
      await _execute('DELETE FROM cached_squares');
      await _execute('DELETE FROM cached_subscribers');
      await _execute('DELETE FROM cached_meters');
      await _execute('DELETE FROM cached_meter_periods');
      await _execute('DELETE FROM cached_settings');
      await _execute('DELETE FROM cached_readings WHERE server_id IS NOT NULL');
      await _execute("DELETE FROM pending_sync_actions WHERE state = 'synced'");
    });
    _notifySyncQueueChanged();
  }

  Future<void> closeDatabase() async {
    await _syncQueueChanges.close();
    await close();
  }

  String _jsonEncode(Map<String, dynamic> value) {
    // Keep dart:convert out of callers so queue writes and reading rows share
    // the same transaction boundary.
    return _encodeJson(value);
  }
}

Map<String, dynamic>? _asMap(Object? value) {
  if (value is Map<String, dynamic>) return value;
  if (value is Map) return Map<String, dynamic>.from(value);
  return null;
}

String _encodeJson(Map<String, dynamic> value) {
  // Imported lazily at file scope in a tiny helper to avoid leaking codecs into
  // feature data sources.
  return jsonEncode(value);
}
