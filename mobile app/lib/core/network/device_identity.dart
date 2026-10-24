import 'dart:io';
import 'dart:math';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// مخزن بسيط لمعرّف الجهاز، حتى يمكن استبداله في الاختبارات دون لمس قناة
/// التخزين الآمن الأصلية.
abstract interface class DeviceIdStore {
  Future<String?> read();

  Future<void> write(String value);
}

class SecureDeviceIdStore implements DeviceIdStore {
  SecureDeviceIdStore({FlutterSecureStorage? storage})
      : _storage = storage ?? const FlutterSecureStorage();

  static const String key = 'water.device_id';
  final FlutterSecureStorage _storage;

  @override
  Future<String?> read() => _storage.read(key: key);

  @override
  Future<void> write(String value) => _storage.write(key: key, value: value);
}

/// هوية ثابتة للجهاز تُرسل مع كل طلب.
///
/// الخادم يستخدمها لربط الجهاز بالحساب وإبطال جهاز مفقود (خطة 2.0 — الفصل 9.7).
/// المعرّف يُولَّد مرة واحدة ويُحفظ في التخزين الآمن، ولا يحتوي أي بيانات شخصية.
class DeviceIdentity {
  DeviceIdentity({DeviceIdStore? store}) : _store = store ?? SecureDeviceIdStore();

  final DeviceIdStore _store;
  String? _cached;

  Future<String> id() async {
    final cached = _cached;
    if (cached != null && cached.isNotEmpty) return cached;

    final stored = await _store.read();
    if (stored != null && stored.trim().isNotEmpty) {
      _cached = stored.trim();
      return _cached!;
    }

    final generated = uuidV4();
    await _store.write(generated);
    _cached = generated;
    return generated;
  }

  /// تُرسل كترويسة `X-Client-Platform` وتساعد الدعم على تمييز المشكلات.
  static String get platform {
    if (Platform.isAndroid) return 'android';
    if (Platform.isIOS) return 'ios';
    if (Platform.isWindows) return 'windows';
    if (Platform.isLinux) return 'linux';
    if (Platform.isMacOS) return 'macos';
    return 'unknown';
  }

  static String uuidV4() {
    final random = Random.secure();
    final bytes = List<int>.generate(16, (_) => random.nextInt(256));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    final hex = bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
    return '${hex.substring(0, 8)}-${hex.substring(8, 12)}-'
        '${hex.substring(12, 16)}-${hex.substring(16, 20)}-${hex.substring(20)}';
  }
}
