import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/backup/local_backup.dart';

/// نسخة احتياطية محلية واستعادتها (S7-08).
///
/// الخطر الحقيقي ليس فشل النسخ بل الاستعادة الخاطئة: ملف مقطوع أو معدّل يجب
/// أن يُرفض بالكامل، ولا يُكتب فوق بيانات الجهاز.
class _MemoryStore implements FieldDataStore {
  _MemoryStore(this.data);

  Map<String, dynamic> data;
  bool pendingActions = true;
  int imports = 0;

  @override
  Future<Map<String, dynamic>> exportFieldData() async => data;

  @override
  Future<void> importFieldData(Map<String, dynamic> data, {bool pendingActions = true}) async {
    imports++;
    this.pendingActions = pendingActions;
    this.data = data;
  }
}

class _MemoryStorage extends BackupStorage {
  final Map<String, String> files = <String, String>{};
  int shares = 0;

  @override
  Future<String> write(String name, String contents) async {
    if (files.containsKey(name)) throw const BackupNameConflict();
    files[name] = contents;
    return '/tmp/$name';
  }

  @override
  Future<List<String>> list() async => files.keys.toList()..sort((a, b) => b.compareTo(a));

  @override
  Future<String> read(String name) async {
    final contents = files[name];
    if (contents == null) throw const BackupException('الملف غير موجود');
    return contents;
  }

  @override
  Future<void> delete(String name) async => files.remove(name);

  @override
  Future<void> share(String name, String path) async => shares++;
}

Map<String, dynamic> _sampleData() => <String, dynamic>{
      'squares': <Map<String, dynamic>>[
        <String, dynamic>{'server_id': 1, 'name': 'مربع 1'},
      ],
      'subscribers': <Map<String, dynamic>>[
        <String, dynamic>{'server_id': 10, 'name': 'أحمد'},
      ],
      'meters': <Map<String, dynamic>>[
        <String, dynamic>{'server_id': 100, 'unique_code': 'MTR-100'},
      ],
      'meter_periods': const <Map<String, dynamic>>[],
      'readings': <Map<String, dynamic>>[
        <String, dynamic>{'local_id': 'r1', 'meter_id': 100, 'current_reading': 42.5},
        <String, dynamic>{'local_id': 'r2', 'meter_id': 100, 'current_reading': 51.0},
      ],
      'pending_actions': <Map<String, dynamic>>[
        <String, dynamic>{'id': 'a1', 'action_type': 'save_reading', 'state': 'pending'},
      ],
      'settings': const <Map<String, dynamic>>[],
    };

void main() {
  late _MemoryStore store;
  late _MemoryStorage storage;
  late LocalBackupService service;

  setUp(() {
    store = _MemoryStore(_sampleData());
    storage = _MemoryStorage();
    service = LocalBackupService(
      store: store,
      storage: storage,
      appVersion: '2.0.0',
      clock: () => DateTime.utc(2026, 10, 24, 9, 5, 7),
    );
  });

  group('createBackup', () {
    test('يكتب ملفاً يحمل البصمة والأعداد واسم الإصدار', () async {
      final created = await service.createBackup();

      expect(created.name, 'water-backup-20261024-090507.json');
      expect(storage.files, hasLength(1));
      final decoded = jsonDecode(storage.files[created.name]!) as Map<String, dynamic>;
      expect(decoded['format'], 'water-field-backup');
      expect(decoded['version'], BackupFormat.version);
      expect(decoded['app_version'], '2.0.0');
      expect((decoded['counts'] as Map<String, dynamic>)['readings'], 2);
      expect(decoded['checksum'], LocalBackupService.checksumOf(_sampleData()));
      expect(created.totalRows, 1 + 1 + 1 + 0 + 2 + 1 + 0);
    });

    test('same-second backups keep both snapshots', () async {
      final first = await service.createBackup();
      final original = storage.files[first.name];
      store.data = _sampleData()..['meters'] = [{'server_id': 200}];
      final second = await service.createBackup();
      expect(second.name, 'water-backup-20261024-090507-0001.json');
      expect(storage.files, hasLength(2));
      expect(storage.files[first.name], original);
      expect(storage.files[second.name], isNot(original));
    });

    test('concurrent physical writes cannot overwrite an existing backup', () async {
      final dir = await Directory.systemTemp.createTemp('water-backup-test-');
      addTearDown(() => dir.delete(recursive: true));
      final firstStore = DeviceBackupStorage(directory: dir);
      final secondStore = DeviceBackupStorage(directory: dir);
      final firstService = LocalBackupService(store: store, storage: firstStore,
          clock: () => DateTime.utc(2026, 10, 24, 9, 5, 7));
      final otherStore = _MemoryStore(_sampleData()..['meters'] = [{'server_id': 200}]);
      final secondService = LocalBackupService(store: otherStore, storage: secondStore,
          clock: () => DateTime.utc(2026, 10, 24, 9, 5, 7));
      final backups = await Future.wait([firstService.createBackup(), secondService.createBackup()]);
      expect(backups.map((b) => b.name).toSet(), hasLength(2));
      expect(await firstStore.list(), hasLength(2));
      final original = await firstStore.read(backups.first.name);
      await expectLater(firstStore.write(backups.first.name, 'replacement'),
          throwsA(isA<BackupNameConflict>()));
      expect(await firstStore.read(backups.first.name), original);
      final other = jsonDecode(await secondStore.read(backups.last.name)) as Map;
      expect(((other['data'] as Map)['meters'] as List).single, {'server_id': 200});
    });

    test('قاعدة فارغة تُرفض برسالة عربية واضحة', () async {
      final empty = _MemoryStore(<String, dynamic>{
        for (final table in LocalBackupService.trackedTables) table: const <Object>[],
      });
      final emptyService = LocalBackupService(store: empty, storage: _MemoryStorage());

      await expectLater(
        emptyService.createBackup(),
        throwsA(isA<BackupException>().having(
          (error) => error.message,
          'message',
          contains('لا توجد بيانات محلية'),
        )),
      );
    });
  });

  group('checksumOf', () {
    test('لا يعتمد على ترتيب المفاتيح', () {
      final a = LocalBackupService.checksumOf(<String, dynamic>{'x': 1, 'y': <int>[1, 2]});
      final b = LocalBackupService.checksumOf(<String, dynamic>{'y': <int>[1, 2], 'x': 1});
      expect(a, b);
    });

    test('يتغيّر بتغيّر أي قيمة', () {
      expect(
        LocalBackupService.checksumOf(<String, dynamic>{'x': 1}),
        isNot(LocalBackupService.checksumOf(<String, dynamic>{'x': 2})),
      );
    });
  });

  group('restore', () {
    test('استعادة ناجحة تُمرّر البيانات وتُبلغ بالأعداد', () async {
      final created = await service.createBackup();
      store.data = <String, dynamic>{};
      store.imports = 0;

      final result = await service.restore(created.name, includePendingActions: true);

      expect(store.imports, 1);
      expect(store.data['readings'], hasLength(2));
      expect(result.restoredRows, 5);
      expect(result.pendingActions, 1);
      expect(result.warnings.single, contains('عملية مزامنة'));
    });

    test('تعطيل عمليات المزامنة يمرّر الخيار للمخزن', () async {
      final created = await service.createBackup();
      final result = await service.restore(created.name);

      expect(store.pendingActions, isFalse);
      expect(result.pendingActions, 0);
      expect(store.data['pending_actions'], isNull);
      expect(store.data['readings'], isEmpty);
      expect(result.warnings.single, contains('قراءة غير مرسلة'));
    });

    test('ملف معدّل بعد النسخ يُرفض ولا يلمس البيانات', () async {
      final created = await service.createBackup();
      final tampered = storage.files[created.name]!
          .replaceFirst('42.5', '99.9');
      storage.files[created.name] = tampered;

      await expectLater(
        service.restore(created.name),
        throwsA(isA<BackupException>().having(
          (error) => error.message,
          'message',
          contains('بصمة الملف'),
        )),
      );
      expect(store.imports, 0);
    });

    test('listing marks checksum mismatch and fractional format version incompatible', () async {
      final created = await service.createBackup();
      final original = jsonDecode(storage.files[created.name]!) as Map<String, dynamic>;
      storage.files[created.name] = jsonEncode({...original, 'checksum': 'bad'});
      expect((await service.listBackups()).single.isCompatible, isFalse);
      storage.files[created.name] = jsonEncode({...original, 'version': 1.5});
      expect((await service.listBackups()).single.isCompatible, isFalse);
      await expectLater(service.restore(created.name), throwsA(isA<BackupException>()));
    });

    test('ملف بلا بصمة يُرفض', () async {
      storage.files['water-backup-20261024-090507.json'] = jsonEncode(<String, dynamic>{
        'format': 'water-field-backup',
        'version': 1,
        'data': <String, dynamic>{'readings': const <Object>[]},
      });

      await expectLater(
        service.restore('water-backup-20261024-090507.json'),
        throwsA(isA<BackupException>().having(
          (error) => error.message,
          'message',
          contains('بلا بصمة'),
        )),
      );
    });

    test('صيغة أحدث من التطبيق تُرفض برسالة تحديث', () async {
      storage.files['future.json'] = jsonEncode(<String, dynamic>{
        'format': 'water-field-backup',
        'version': BackupFormat.version + 1,
        'data': <String, dynamic>{},
        'checksum': LocalBackupService.checksumOf(<String, dynamic>{}),
      });

      await expectLater(
        service.restore('future.json'),
        throwsA(isA<BackupException>().having(
          (error) => error.message,
          'message',
          contains('إصدار أحدث'),
        )),
      );
    });

    test('ملف ليس نسخة من التطبيق يُرفض', () async {
      storage.files['other.json'] = jsonEncode(<String, dynamic>{'hello': 'world'});

      await expectLater(service.restore('other.json'), throwsA(isA<BackupException>()));
    });

    test('نص غير JSON يُرفض برسالة تلف', () async {
      storage.files['broken.json'] = '{{{';

      await expectLater(
        service.restore('broken.json'),
        throwsA(isA<BackupException>().having(
          (error) => error.message,
          'message',
          contains('تالف'),
        )),
      );
    });
  });

  group('listBackups', () {
    test('يرتّب الأحدث أولاً ويقرأ الأعداد', () async {
      final first = await service.createBackup();
      final later = LocalBackupService(
        store: store,
        storage: storage,
        clock: () => DateTime.utc(2026, 10, 24, 12, 0, 0),
      );
      final second = await later.createBackup();

      final files = await service.listBackups();

      expect(files.map((file) => file.name), <String>[second.name, first.name]);
      expect(files.first.isCompatible, isTrue);
      expect(files.first.counts['meters'], 1);
      expect(files.every((file) => file.isCompatible), isTrue);
    });

    test('ملف تالف يظهر كغير متوافق بدل إخفائه', () async {
      final created = await service.createBackup();
      storage.files['water-backup-20261001-000000.json'] = 'ليس JSON';

      final files = await service.listBackups();

      final broken = files.firstWhere((file) => file.name == 'water-backup-20261001-000000.json');
      expect(broken.isCompatible, isFalse);
      expect(files.any((file) => file.name == created.name), isTrue);
    });

    test('اسم الملف يعطي التاريخ نفسه عند فكّه', () async {
      final created = await service.createBackup();
      expect(created.createdAt, DateTime.utc(2026, 10, 24, 9, 5, 7));
      expect(created.name, 'water-backup-20261024-090507.json');
    });
  });

  group('share/delete', () {
    test('المشاركة تُمرّر الملف للتخزين وتُسجّل', () async {
      final created = await service.createBackup();
      await service.shareBackup(created.name);
      expect(storage.shares, 1);
    });

    test('الحذف يزيل الملف من القائمة', () async {
      final created = await service.createBackup();
      await service.delete(created.name);
      expect(await service.listBackups(), isEmpty);
    });
  });
}
