class AppConfig {
  const AppConfig._();

  /// Set this per environment, e.g. --dart-define=API_BASE_URL=https://water.example.com/api
  /// Suggested first-launch URL; physical devices need the server's LAN URL.
  static const String _configuredApiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: '',
  );

  static String? _runtimeApiBaseUrl;

  static String get configuredApiBaseUrl => _configuredApiBaseUrl;

  static String normalizeApiBaseUrl(String input) {
    final uri = Uri.tryParse(input.trim());
    if (uri == null ||
        !const ['http', 'https'].contains(uri.scheme) ||
        uri.host.isEmpty ||
        uri.userInfo.isNotEmpty ||
        uri.hasQuery ||
        uri.hasFragment) {
      throw const FormatException('Enter an HTTP(S) API URL without credentials, query or fragment.');
    }
    final path = uri.path.replaceAll(RegExp(r'/+$'), '');
    return uri.replace(path: '${path.isEmpty ? '/api' : path}/').toString();
  }

  static void configureServer(String url) {
    _runtimeApiBaseUrl = normalizeApiBaseUrl(url);
  }

  static String get apiBaseUrl => _runtimeApiBaseUrl ??
      normalizeApiBaseUrl(_configuredApiBaseUrl);

  static const Duration connectTimeout = Duration(seconds: 15);
  static const Duration receiveTimeout = Duration(seconds: 30);
  static const Duration sendTimeout = Duration(seconds: 30);
  static const String appName = 'مشروع مياه الوشاح';
  static const String defaultProjectName = 'مشروع مياه الوشاح';
  static const String locale = 'ar';
}
