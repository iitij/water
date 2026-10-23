import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'app.dart';
import 'bootstrap.dart';
import 'core/config/app_config.dart';
import 'core/setup/server_setup_app.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  final preferences = await SharedPreferences.getInstance();
  final server = preferences.getString(ServerSetupApp.preferenceKey);
  if (server == null) {
    runApp(const ServerSetupApp());
    return;
  }
  AppConfig.configureServer(server);
  await bootstrap();
  runApp(const WaterApp());
}
