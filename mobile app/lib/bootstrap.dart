import 'core/config/app_config.dart';
import 'core/di/injection_container.dart';
import 'core/theme/app_theme.dart';

Future<void> bootstrap() async {
  await configureDependencies(appVersion: AppConfig.appVersion);
  await getIt<AppThemeController>().initialize();
}
