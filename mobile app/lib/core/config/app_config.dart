import 'dart:io';

import 'package:flutter/foundation.dart';

class AppConfig {
  const AppConfig._();

  /// Set this per environment, e.g. --dart-define=API_BASE_URL=https://water.example.com/api
  /// Suggested first-launch URL; physical devices need the server's LAN URL.
  static const String _configuredApiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: '',
  );

  /// يُسمح بـ HTTP فقط في نسخ التطوير أو عند بناء اختباري صريح.
  /// نسخة الإنتاج (release) ترفض أي رابط غير مشفّر — خطة 2.0، الفصل 9.3.
  static const bool _allowInsecureHttp = bool.fromEnvironment(
    'ALLOW_INSECURE_HTTP',
    defaultValue: false,
  );

  static bool get isInsecureHttpAllowed => !kReleaseMode || _allowInsecureHttp;

  /// Default API URL based on platform:
  /// - Windows / Desktop: http://localhost:8000/api
  /// - Mobile / Physical Device: http://192.168.1.20:8000/api
  static String get defaultApiBaseUrl {
    if (_configuredApiBaseUrl.trim().isNotEmpty) {
      return _configuredApiBaseUrl;
    }
    return Platform.isWindows
        ? 'http://localhost:8000/api'
        : 'http://192.168.1.20:8000/api';
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
      throw const FormatException('Enter an HTTP(S) API URL without credentials, query or fragment.');
    }
    if (!isInsecureHttpAllowed && uri.scheme != 'https') {
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
    if (_runtimeApiBaseUrl != null && _runtimeApiBaseUrl!.isNotEmpty) {
      return _runtimeApiBaseUrl;
    }
    try {
      return normalizeApiBaseUrl(defaultApiBaseUrl);
    } on FormatException {
      return null;
    }
  }

  /// يعيد رابطاً صالحاً أو نصاً فارغاً إن كان الافتراضي محظوراً (HTTP في
  /// الإنتاج). الشاشة الأولى تطلب عندئذٍ رابط خادم صحيحاً بدل الانهيار.
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
