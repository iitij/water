import 'package:flutter/foundation.dart';

class AppConfig {
  const AppConfig._();

  /// Set this per environment, e.g. --dart-define=API_BASE_URL=https://water.example.com/api
  /// Suggested first-launch URL; physical devices need the server's LAN URL.
  static const String _configuredApiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: '',
  );

  /// يسمح البناء الاختباري الصريح باستخدام HTTP خارج الشبكة المحلية أيضاً.
  static const bool _allowInsecureHttp = bool.fromEnvironment(
    'ALLOW_INSECURE_HTTP',
    defaultValue: false,
  );

  static bool get isInsecureHttpAllowed => !kReleaseMode || _allowInsecureHttp;

  /// نفس المفتاح الذي يحفظه محرر الخادم في وضع المطور وتسجيل الدخول.
  static const String serverUrlPreferenceKey = 'water.server_url';

  /// الهاتف الحقيقي يستعمل عنوان الكمبيوتر على الشبكة المحفوظ في المحرر.
  /// الافتراضي لأندرويد هو عنوان المضيف داخل المحاكي، وليس IP شبكة مفترضاً.
  static String get defaultApiBaseUrl =>
      defaultApiBaseUrlForPlatform(defaultTargetPlatform);

  static String defaultApiBaseUrlForPlatform(TargetPlatform platform) {
    if (_configuredApiBaseUrl.trim().isNotEmpty) {
      return _configuredApiBaseUrl;
    }
    return platform == TargetPlatform.android
        ? 'http://10.0.2.2:8000/api'
        : 'http://localhost:8000/api';
  }

  /// HTTP المحلي مطلوب لتشغيل Laravel على الكمبيوتر وشبكة Wi-Fi الخاصة.
  /// لا نعد أسماء DNS الشبيهة بعنوان محلي أو نطاقات localhost الفرعية محلية.
  static bool isHttpAllowedForHost(
    String host, {
    bool? releaseMode,
    bool? allowInsecureHttp,
  }) {
    if (!(releaseMode ?? kReleaseMode) ||
        (allowInsecureHttp ?? _allowInsecureHttp)) {
      return true;
    }
    final normalized = host.toLowerCase();
    if (const ['localhost', '::1', '[::1]'].contains(normalized)) {
      return true;
    }
    final parts = normalized.split('.');
    if (parts.length != 4 ||
        parts.any((part) => !RegExp(r'^(0|[1-9][0-9]{0,2})$').hasMatch(part))) {
      return false;
    }
    final octets = parts.map(int.parse).toList(growable: false);
    if (octets.any((octet) => octet > 255)) return false;
    return octets[0] == 127 ||
        octets[0] == 10 ||
        (octets[0] == 172 && octets[1] >= 16 && octets[1] <= 31) ||
        (octets[0] == 192 && octets[1] == 168);
  }

  /// العنوان المحفوظ له الأولوية على عنوان البناء والافتراضي.
  /// إن كان المحفوظ غير صالح نبقى بلا اتصال حتى إصلاحه من تسجيل الدخول؛
  /// لا ننقل الجلسة أو العمليات المعلقة تلقائياً إلى خادم مختلف.
  static String resolveStartupApiBaseUrl(
    String? storedUrl, {
    TargetPlatform? platform,
  }) {
    final stored = storedUrl?.trim() ?? '';
    final selected = stored.isNotEmpty
        ? stored
        : defaultApiBaseUrlForPlatform(platform ?? defaultTargetPlatform);
    try {
      return normalizeApiBaseUrl(selected);
    } on FormatException {
      return '';
    }
  }

  static void configureStartupServer(String? storedUrl) {
    _runtimeApiBaseUrl = resolveStartupApiBaseUrl(storedUrl);
  }

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
      throw const FormatException(
        'Enter an HTTP(S) API URL without credentials, query or fragment.',
      );
    }
    if (uri.scheme == 'http' && !isHttpAllowedForHost(uri.host)) {
      throw const FormatException(
        'يجب استخدام رابط HTTPS آمن (https://...) في نسخة الإنتاج.',
      );
    }
    final path = uri.path.replaceAll(RegExp(r'/+$'), '');
    return uri.replace(path: '${path.isEmpty ? '/api' : path}/').toString();
  }

  static void configureServer(String url) {
    _runtimeApiBaseUrl = normalizeApiBaseUrl(url);
  }

  static String? get currentApiBaseUrl {
    if (_runtimeApiBaseUrl != null) {
      return _runtimeApiBaseUrl!.isEmpty ? null : _runtimeApiBaseUrl;
    }
    try {
      return normalizeApiBaseUrl(defaultApiBaseUrl);
    } on FormatException {
      return null;
    }
  }

  /// يعيد رابطاً صالحاً أو نصاً فارغاً عند تعذر الاتصال بالعنوان المختار.
  static String get apiBaseUrl {
    final raw = _runtimeApiBaseUrl ?? defaultApiBaseUrl;
    if (raw.trim().isEmpty) return '';
    try {
      return normalizeApiBaseUrl(raw);
    } on FormatException {
      return '';
    }
  }

  static const Duration connectTimeout = Duration(seconds: 15);
  static const Duration receiveTimeout = Duration(seconds: 30);
  static const Duration sendTimeout = Duration(seconds: 30);

  /// نسخة التطبيق الظاهرة للخادم في `X-Client-Version`.
  /// تُحدَّث يدوياً مع `version:` في pubspec (وليس عبر `package_info_plus`
  /// لتجنّب اعتماد إضافي في مسار الإقلاع).
  static const String appVersion = String.fromEnvironment(
    'APP_VERSION',
    defaultValue: '1.0.0+1',
  );

  static const String appName = 'مشروع مياه الوشاح';
  static const String defaultProjectName = 'مشروع مياه الوشاح';
  static const String locale = 'ar';
}
