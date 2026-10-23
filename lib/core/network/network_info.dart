import 'package:connectivity_plus/connectivity_plus.dart';

/// حالة الشبكة — تُستخدم لتحويل التحصيل أوفلاين إلى طابور المزامنة (`SyncQueue`).
class NetworkInfo {
  NetworkInfo({Connectivity? connectivity})
      : _connectivity = connectivity ?? Connectivity();

  final Connectivity _connectivity;

  Future<bool> get isConnected async {
    try {
      final results = await _connectivity.checkConnectivity();
      return results.isNotEmpty &&
          !results.every((r) => r == ConnectivityResult.none);
    } catch (_) {
      // المنصة غير مدعومة (اختبارات/ديسكتوب): نتعامل كمتصل ليُجرب الطلب فعلياً.
      return true;
    }
  }

  Stream<bool> get onStatusChange async* {
    yield await isConnected;
    yield* _connectivity.onConnectivityChanged
        .map((results) => results.isNotEmpty &&
            !results.every((r) => r == ConnectivityResult.none));
  }
}
