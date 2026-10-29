import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'app.dart';
import 'bootstrap.dart';
import 'core/config/app_config.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  final preferences = await SharedPreferences.getInstance();
  final stored = preferences.getString(AppConfig.serverUrlPreferenceKey);
  AppConfig.configureStartupServer(stored);
  await bootstrap();
  runApp(const WaterApp());
}
