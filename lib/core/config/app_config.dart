class AppConfig {
  const AppConfig._();

  /// Set this per environment, e.g. --dart-define=API_BASE_URL=https://water.example.com/api
  /// Android emulator default; physical devices should use the server's LAN URL.
  static const String _configuredApiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2:8000/api',
  );

  static String get apiBaseUrl {
    final value = _configuredApiBaseUrl.trim().replaceAll(RegExp(r'/+$'), '');
    return '$value/';
  }

  static const Duration connectTimeout = Duration(seconds: 15);
  static const Duration receiveTimeout = Duration(seconds: 30);
  static const Duration sendTimeout = Duration(seconds: 30);
  static const String appName = 'مشروع مياه الوشاح';
  static const String defaultProjectName = 'مشروع مياه الوشاح';
  static const String locale = 'ar';
}
