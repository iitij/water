import 'core/di/injection_container.dart';
import 'core/theme/app_theme.dart';

Future<void> bootstrap() async {
  await configureDependencies();
  await getIt<AppThemeController>().initialize();
}
