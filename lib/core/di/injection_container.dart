import 'package:dio/dio.dart';
import 'package:get_it/get_it.dart';

import '../auth/session_service.dart';
import '../network/dio_client.dart';
import '../network/network_info.dart';
import '../network/secure_storage_service.dart';
import '../permissions/permission_service.dart';
import '../theme/app_theme.dart';
import '../../features/auth/presentation/cubit/auth_cubit.dart';
import '../../features/offline_sync/data/shared_preferences_sync_queue_repository.dart';
import '../../features/offline_sync/domain/sync_queue_repository.dart';
import '../../features/offline_sync/services/sync_queue_manager.dart';
import '../router/app_router.dart';

final GetIt getIt = GetIt.instance;

Future<void> configureDependencies() async {
  if (getIt.isRegistered<SessionService>()) return;

  final secureStorage = SecureStorageService();
  final dioClient = DioClient(secureStorage);
  getIt.registerSingleton<SecureStorageService>(secureStorage);
  getIt.registerSingleton<DioClient>(dioClient);
  getIt.registerSingleton<Dio>(dioClient.dio);
  getIt.registerSingleton(dioClient.offlineCache);
  getIt.registerSingleton<NetworkInfo>(NetworkInfo());

  final session = SessionService(
    dio: dioClient.dio,
    secureStorage: secureStorage,
    offlineCache: dioClient.offlineCache,
  );
  getIt.registerSingleton<SessionService>(session);
  dioClient.errorInterceptor.onUnauthorized = session.expireSession;
  getIt.registerSingleton<PermissionService>(PermissionService(session));
  getIt.registerSingleton<AppThemeController>(AppThemeController());

  final queueRepository = SharedPreferencesSyncQueueRepository();
  getIt.registerSingleton<SyncQueueRepository>(queueRepository);
  final syncManager = SyncQueueManager(
    dio: dioClient.dio,
    repository: queueRepository,
    networkInfo: getIt<NetworkInfo>(),
    session: session,
  );
  getIt.registerSingleton<SyncQueueManager>(syncManager);
  getIt.registerFactory<AuthCubit>(() => AuthCubit(session));
  getIt.registerSingleton<AppRouter>(AppRouter(session: session));

  await syncManager.initialize();
}
