import '../device_record.dart';

/// إدارة أجهزة الحساب: عرض القائمة وإبطال جهاز مفقود (خطة 2.0 — الفصل 9.7).
abstract class DevicesRepository {
  Future<List<DeviceRecord>> getDevices({String? currentDeviceUuid});

  Future<void> revoke(int id);
}
