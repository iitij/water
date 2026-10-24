import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/features/devices/devices.dart';

/// إدارة الأجهزة وإبطالها (S7-07).
///
/// الحماية الأهم: لا يُبطل المستخدم الجهاز الذي بيده، وترتيب القائمة يضع
/// النشط أولاً حتى لا يبحث المشرف تحت الأجهزة المُبطلة.
class _FakeDevicesRepository implements DevicesRepository {
  _FakeDevicesRepository({this.devices = const <DeviceRecord>[], this.failRevoke = false});

  List<DeviceRecord> devices;
  final bool failRevoke;
  final List<int> revokedIds = <int>[];

  @override
  Future<List<DeviceRecord>> getDevices({String? currentDeviceUuid}) async => devices
      .map((device) => DeviceRecord(
            id: device.id,
            deviceUuid: device.deviceUuid,
            name: device.name,
            platform: device.platform,
            appVersion: device.appVersion,
            lastSeenAt: device.lastSeenAt,
            revokedAt: device.revokedAt,
            isCurrent: device.deviceUuid == currentDeviceUuid,
          ))
      .toList(growable: false);

  @override
  Future<void> revoke(int id) async {
    if (failRevoke) throw Exception('500');
    revokedIds.add(id);
  }
}

DeviceRecord _device(
  int id, {
  String uuid = '',
  String? name,
  String? platform = 'android',
  DateTime? lastSeen,
  DateTime? revokedAt,
}) =>
    DeviceRecord(
      id: id,
      deviceUuid: uuid.isEmpty ? 'uuid-$id' : uuid,
      name: name,
      platform: platform,
      lastSeenAt: lastSeen,
      revokedAt: revokedAt,
    );

void main() {
  group('DeviceRecord', () {
    test('displayName يتدرّج: الاسم ثم المنصّة ثم آخر 6 من المعرّف', () {
      expect(_device(1, name: 'جوال المحصّل').displayName, 'جوال المحصّل');
      expect(_device(1, platform: 'android').displayName, 'أندرويد • uuid-1');
      expect(_device(1, platform: 'windows').displayName, 'ويندوز • uuid-1');
      expect(
        _device(1, uuid: 'abcdef123456', platform: null).displayName,
        'جهاز • 123456',
      );
    });

    test('fromJson يقرأ التواريخ ويوسم الجهاز الحالي', () {
      final record = DeviceRecord.fromJson(<String, dynamic>{
        'id': 5,
        'device_uuid': 'me',
        'name': 'هاتفي',
        'platform': 'android',
        'app_version': '2.0.0',
        'last_seen_at': '2026-10-24T09:30:00+00:00',
        'revoked_at': null,
      }, currentDeviceUuid: 'me');

      expect(record.id, 5);
      expect(record.isCurrent, isTrue);
      expect(record.isRevoked, isFalse);
      expect(record.appVersion, '2.0.0');
      expect(record.lastSeenAt, isNotNull);
    });

    test('asRevoked يحفظ الهوية ويضيف وقت الإبطال', () {
      final revoked = _device(3, name: 'لوحي').asRevoked(DateTime(2026, 10, 24));
      expect(revoked.id, 3);
      expect(revoked.name, 'لوحي');
      expect(revoked.isRevoked, isTrue);
    });

    test('sortDevices: النشطة أولاً ثم الأحدث ظهوراً', () {
      final sorted = sortDevices(<DeviceRecord>[
        _device(1, lastSeen: DateTime(2026, 10, 1)),
        _device(2, revokedAt: DateTime(2026, 10, 23), lastSeen: DateTime(2026, 10, 23)),
        _device(3, lastSeen: DateTime(2026, 10, 20)),
      ]);
      expect(sorted.map((device) => device.id), <int>[3, 1, 2]);
    });
  });

  group('DevicesCubit', () {
    test('load يوسم الجهاز الحالي ويفصل النشطة عن المُبطلة', () async {
      final cubit = DevicesCubit(
        repository: _FakeDevicesRepository(devices: <DeviceRecord>[
          _device(1, uuid: 'me'),
          _device(2, revokedAt: DateTime(2026, 10, 20)),
        ]),
        currentUuidLoader: () async => 'me',
      );
      await cubit.load();

      expect(cubit.state.status, DevicesStatus.success);
      expect(cubit.state.active.single.id, 1);
      expect(cubit.state.active.single.isCurrent, isTrue);
      expect(cubit.state.revoked.single.id, 2);
    });

    test('لا يُبطل الجهاز الحالي ويشرح السبب', () async {
      final repository = _FakeDevicesRepository(devices: <DeviceRecord>[_device(1, uuid: 'me')]);
      final cubit = DevicesCubit(repository: repository, currentDeviceUuid: 'me');
      await cubit.load();

      final ok = await cubit.revoke(cubit.state.devices.single);

      expect(ok, isFalse);
      expect(repository.revokedIds, isEmpty);
      expect(cubit.state.errorMessage, contains('الجهاز الذي تستخدمه الآن'));
    });

    test('إبطال جهاز آخر يُحدّث القائمة ويُصدر رسالة نجاح', () async {
      final repository = _FakeDevicesRepository(devices: <DeviceRecord>[
        _device(1, uuid: 'me'),
        _device(2, name: 'جوال مفقود'),
      ]);
      final cubit = DevicesCubit(repository: repository, currentDeviceUuid: 'me');
      await cubit.load();

      final target = cubit.state.devices.firstWhere((device) => device.id == 2);
      final ok = await cubit.revoke(target);

      expect(ok, isTrue);
      expect(repository.revokedIds, <int>[2]);
      expect(cubit.state.active.single.id, 1);
      expect(cubit.state.revoked.single.id, 2);
      expect(cubit.state.successMessage, contains('جوال مفقود'));
      expect(cubit.state.revokingId, isNull);
    });

    test('فشل الإبطال يُبقي الجهاز نشطاً ويُظهر الخطأ', () async {
      final repository = _FakeDevicesRepository(
        devices: <DeviceRecord>[_device(2)],
        failRevoke: true,
      );
      final cubit = DevicesCubit(repository: repository, currentDeviceUuid: 'me');
      await cubit.load();

      final ok = await cubit.revoke(cubit.state.devices.single);

      expect(ok, isFalse);
      expect(cubit.state.devices.single.isRevoked, isFalse);
      expect(cubit.state.errorMessage, isNotNull);
      expect(cubit.state.revokingId, isNull);
    });

    test('إبطال جهاز مُبطل سابقاً لا يرسل طلباً', () async {
      final repository = _FakeDevicesRepository(devices: <DeviceRecord>[
        _device(2, revokedAt: DateTime(2026, 10, 1)),
      ]);
      final cubit = DevicesCubit(repository: repository, currentDeviceUuid: 'me');
      await cubit.load();

      expect(await cubit.revoke(cubit.state.devices.single), isFalse);
      expect(repository.revokedIds, isEmpty);
      expect(cubit.state.errorMessage, contains('مُبطل بالفعل'));
    });

    test('تعذّر قراءة معرّف الجهاز لا يُفشل التحميل', () async {
      final cubit = DevicesCubit(
        repository: _FakeDevicesRepository(devices: <DeviceRecord>[_device(1)]),
        currentUuidLoader: () async => throw Exception('secure storage'),
      );
      await cubit.load();

      expect(cubit.state.status, DevicesStatus.success);
      expect(cubit.state.devices.single.isCurrent, isFalse);
    });
  });
}
