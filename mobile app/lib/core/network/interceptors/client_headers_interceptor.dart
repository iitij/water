import 'package:dio/dio.dart';

import '../device_identity.dart';

/// يضيف هوية الجهاز ومصدر الطلب لكل طلب صادر من التطبيق.
///
/// المصدر (`X-Client-Source: mobile`) يُستخدم في الخادم لتمييز الإجراءات
/// القادمة من الميدان في سجل التدقيق.
class ClientHeadersInterceptor extends Interceptor {
  ClientHeadersInterceptor({DeviceIdentity? deviceIdentity, this.appVersion})
      : _deviceIdentity = deviceIdentity ?? DeviceIdentity();

  final DeviceIdentity _deviceIdentity;
  final String? appVersion;
  String? _deviceId;

  /// يُستخدم في الاختبارات أو عند تثبيت معرّف الجهاز من الإعدادات.
  ClientHeadersInterceptor.withIdentity(
    DeviceIdentity identity, {
    this.appVersion,
  }) : _deviceIdentity = identity;

  @override
  Future<void> onRequest(
    RequestOptions options,
    RequestInterceptorHandler handler,
  ) async {
    try {
      _deviceId ??= await _deviceIdentity.id();
      options.headers['X-Device-Id'] = _deviceId;
      options.headers['X-Client-Platform'] = DeviceIdentity.platform;
      options.headers['X-Client-Source'] = 'mobile';
      final version = appVersion;
      if (version != null && version.isNotEmpty) {
        options.headers['X-Client-Version'] = version;
      }
    } catch (_) {
      // فشل قراءة التخزين الآمن لا يجوز أن يمنع الطلب نفسه.
    }
    handler.next(options);
  }
}
