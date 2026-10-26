import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/network/device_identity.dart';
import '../../domain/device_record.dart';
import '../../domain/repositories/devices_repository.dart';

enum DevicesStatus { initial, loading, success, failure }

class DevicesState extends Equatable {
  const DevicesState({
    this.status = DevicesStatus.initial,
    this.devices = const <DeviceRecord>[],
    this.revokingId,
    this.errorMessage,
    this.successMessage,
    this.currentDeviceUuid,
  });

  final DevicesStatus status;
  final List<DeviceRecord> devices;

  /// معرّف الجهاز الجاري إبطاله — لتعطيل زرّه وحده بدل تعطيل الشاشة.
  final int? revokingId;
  final String? errorMessage;
  final String? successMessage;
  final String? currentDeviceUuid;

  List<DeviceRecord> get active =>
      devices.where((device) => !device.isRevoked).toList(growable: false);
  List<DeviceRecord> get revoked =>
      devices.where((device) => device.isRevoked).toList(growable: false);

  DevicesState copyWith({
    DevicesStatus? status,
    List<DeviceRecord>? devices,
    int? revokingId,
    String? errorMessage,
    String? successMessage,
    String? currentDeviceUuid,
    bool clearRevoking = false,
    bool clearMessages = false,
  }) => DevicesState(
    status: status ?? this.status,
    devices: devices ?? this.devices,
    revokingId: clearRevoking ? null : revokingId ?? this.revokingId,
    errorMessage: errorMessage ?? (clearMessages ? null : this.errorMessage),
    successMessage:
        successMessage ?? (clearMessages ? null : this.successMessage),
    currentDeviceUuid: currentDeviceUuid ?? this.currentDeviceUuid,
  );

  @override
  List<Object?> get props => <Object?>[
    status,
    devices,
    revokingId,
    errorMessage,
    successMessage,
    currentDeviceUuid,
  ];
}

/// إدارة أجهزة الحساب (S7-07).
///
/// قاعدة الأمان الوحيدة هنا: لا يُبطل المستخدم الجهاز الذي بيده. الإبطال
/// يُخرجه من الحساب فوراً، وهذا ليس ما يقصده حين يفتح الشاشة ليُبطل جهازاً
/// مفقوداً — فنمنعه في الواجهة ونُظهر السبب.
class DevicesCubit extends Cubit<DevicesState> {
  DevicesCubit({
    required DevicesRepository repository,
    this.currentDeviceUuid,
    Future<String?> Function()? currentUuidLoader,
  }) : _repository = repository,
       _loadCurrentUuid =
           currentUuidLoader ??
           (currentDeviceUuid == null ? () => DeviceIdentity().id() : null),
       super(DevicesState(currentDeviceUuid: currentDeviceUuid));

  final DevicesRepository _repository;
  final String? currentDeviceUuid;
  final Future<String?> Function()? _loadCurrentUuid;

  Future<void> load() async {
    emit(state.copyWith(status: DevicesStatus.loading, clearMessages: true));
    try {
      final uuid = state.currentDeviceUuid ?? await _resolveCurrentUuid();
      if (uuid != null && uuid != state.currentDeviceUuid) {
        emit(state.copyWith(currentDeviceUuid: uuid));
      }
      final devices = await _repository.getDevices(currentDeviceUuid: uuid);
      emit(
        state.copyWith(
          status: DevicesStatus.success,
          devices: sortDevices(devices),
        ),
      );
    } catch (error) {
      emit(
        state.copyWith(
          status: DevicesStatus.failure,
          errorMessage: _messageFor(error),
        ),
      );
    }
  }

  /// معرّف الجهاز الحالي من التخزين الآمن — فشله لا يمنع عرض القائمة.
  Future<String?> _resolveCurrentUuid() async {
    try {
      return await _loadCurrentUuid?.call();
    } catch (_) {
      return null;
    }
  }

  /// يعيد `false` إن كان الجهاز هو الحالي — والواجهة تشرح السبب.
  bool canRevoke(DeviceRecord device) => !device.isCurrent && !device.isRevoked;

  Future<bool> revoke(DeviceRecord device) async {
    if (!canRevoke(device)) {
      emit(
        state.copyWith(
          clearMessages: true,
          errorMessage: device.isCurrent
              ? 'هذا هو الجهاز الذي تستخدمه الآن. لا يمكن إبطاله من داخله.'
              : 'الجهاز مُبطل بالفعل.',
        ),
      );
      return false;
    }

    emit(state.copyWith(revokingId: device.id, clearMessages: true));
    try {
      await _repository.revoke(device.id);
      final updated = state.devices
          .map(
            (item) =>
                item.id == device.id ? item.asRevoked(DateTime.now()) : item,
          )
          .toList(growable: false);
      emit(
        state.copyWith(
          devices: sortDevices(updated),
          clearRevoking: true,
          successMessage:
              'أُبطل الجهاز «${device.displayName}» ولن يستطيع الدخول بعد الآن.',
        ),
      );
      return true;
    } catch (error) {
      emit(
        state.copyWith(clearRevoking: true, errorMessage: _messageFor(error)),
      );
      return false;
    }
  }

  String _messageFor(Object error) {
    final text = error.toString();
    if (text.contains('SocketException') || text.contains('connectionError')) {
      return 'لا يوجد اتصال. إدارة الأجهزة تحتاج الشبكة.';
    }
    if (text.contains('403')) return 'لا تملك صلاحية إبطال هذا الجهاز.';
    if (text.contains('404')) return 'الجهاز غير موجود — حدّث القائمة.';
    return 'تعذّر تنفيذ الطلب: $text';
  }

  void clearMessages() => emit(state.copyWith(clearMessages: true));
}
