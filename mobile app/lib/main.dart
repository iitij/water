import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'app.dart';
import 'bootstrap.dart';
import 'core/config/app_config.dart';
import 'core/setup/server_setup_app.dart';

/// هل يمكن استخدام هذا العنوان في النسخة الحالية؟
///
/// نسخة الإنتاج تقبل HTTPS فقط، لذا عنوان محفوظ من نسخة تطوير (HTTP) لا يجوز
/// أن يُسقط التطبيق عند الإقلاع — نعرض شاشة ربط الخادم بدلاً من ذلك.
bool _isUsableServer(String url) {
  if (url.trim().isEmpty) return false;
  try {
    AppConfig.normalizeApiBaseUrl(url);
    return true;
  } on FormatException {
    return false;
  }
}

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  final preferences = await SharedPreferences.getInstance();
  final stored = preferences.getString(ServerSetupApp.preferenceKey)?.trim();
  final server = (stored != null && stored.isNotEmpty)
      ? stored
      : AppConfig.defaultApiBaseUrl;

  if (!_isUsableServer(server)) {
    runApp(const ServerSetupApp());
    return;
  }

  AppConfig.configureServer(server);
  await bootstrap();
  runApp(const WaterApp());
}
