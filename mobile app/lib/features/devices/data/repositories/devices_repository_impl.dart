import '../../domain/device_record.dart';
import '../../domain/repositories/devices_repository.dart';
import '../datasources/devices_remote_data_source.dart';

class DevicesRepositoryImpl implements DevicesRepository {
  const DevicesRepositoryImpl(this._remote);

  final DevicesRemoteDataSource _remote;

  @override
  Future<List<DeviceRecord>> getDevices({String? currentDeviceUuid}) =>
      _remote.getDevices(currentDeviceUuid: currentDeviceUuid);

  @override
  Future<void> revoke(int id) => _remote.revoke(id);
}
