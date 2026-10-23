import 'core/di/injection_container.dart';

Future<void> bootstrap() async {
  await configureDependencies();
}
