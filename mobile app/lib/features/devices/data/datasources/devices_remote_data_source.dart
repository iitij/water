import '../../../admin_shared/data/admin_api_client.dart';
import '../../../admin_shared/data/admin_api_endpoints.dart';
import '../../../admin_shared/data/admin_json.dart';
import '../../domain/device_record.dart';

/// قراءة الأجهزة وإبطالها — يستخدم خادم `/devices` الموجود في routes/api.php.
class DevicesRemoteDataSource {
  const DevicesRemoteDataSource(this._api);

  final AdminApiClient _api;

  Future<List<DeviceRecord>> getDevices({String? currentDeviceUuid}) async {
    final response = await _api.get(AdminApiEndpoints.devices);
    return adminList(response, const <String>['devices'])
        .map((item) => DeviceRecord.fromJson(item, currentDeviceUuid: currentDeviceUuid))
        .where((device) => device.id > 0)
        .toList(growable: false);
  }

  Future<void> revoke(int id) => _api.delete(AdminApiEndpoints.device(id));
}
