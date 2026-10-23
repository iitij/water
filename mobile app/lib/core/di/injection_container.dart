import 'package:dio/dio.dart';
import 'package:get_it/get_it.dart';

import '../../features/admin_shared/data/admin_api_client.dart';
import '../../features/auth/presentation/cubit/auth_cubit.dart';
import '../../features/dashboard/dashboard.dart';
import '../../features/exports/exports.dart';
import '../../features/meters/domain/meters_repository.dart';
import '../../features/offline_sync/data/drift_sync_queue_repository.dart';
import '../../features/offline_sync/domain/sync_queue_repository.dart';
import '../../features/offline_sync/services/sync_queue_manager.dart';
import '../../features/readings/data/services/reading_invoice_actions_service.dart';
import '../../features/readings/domain/readings_repository.dart';
import '../../features/settings/settings.dart';
import '../../features/users_roles/users_roles.dart';
import '../auth/session_service.dart';
import '../database/app_database.dart';
import '../database/offline_pack_downloader.dart';
import '../field_operations/field_operations_module.dart';
import '../finance/finance_module.dart';
import '../fouad/fouad_module.dart';
import '../network/dio_client.dart';
import '../network/network_info.dart';
import '../network/secure_storage_service.dart';
import '../permissions/permission_service.dart';
import '../router/app_router.dart';
import '../theme/app_theme.dart';

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
  getIt.registerFactory<AuthCubit>(() => AuthCubit(session));

  // Dev 2: Field Operations & Shared Drift SQLite Database (`AppDatabase`).
  // `autoStartSync: false` ensures only the unified `SyncQueueManager` below
  // listens to connectivity and replays readings + payments in strict FIFO order.
  final fieldModule = await FieldOperationsModule.initialize(
    dio: dioClient.dio,
    autoStartSync: false,
    ownerUserIdProvider: () => session.user?['id']?.toString(),
  );
  getIt.registerSingleton<FieldOperationsModule>(fieldModule);
  getIt.registerSingleton<AppDatabase>(fieldModule.database);
  getIt.registerSingleton<MetersRepository>(fieldModule.metersRepository);
  getIt.registerSingleton<ReadingsRepository>(fieldModule.readingsRepository);
  getIt.registerSingleton<OfflinePackDownloader>(fieldModule.offlinePackDownloader);
  getIt.registerSingleton<ReadingInvoiceActionsService>(fieldModule.invoiceActions);

  // Unified Drift/SQLite Offline Sync Queue (migrates any legacy SharedPreferences items).
  final queueRepository = DriftSyncQueueRepository(database: fieldModule.database);
  final cachedUser = await secureStorage.readCachedUser();
  await queueRepository.migrateLegacyQueuesIfNeeded(
    fallbackOwnerUserId: cachedUser?['id']?.toString(),
  );
  getIt.registerSingleton<SyncQueueRepository>(queueRepository);
  final syncManager = SyncQueueManager(
    dio: dioClient.dio,
    repository: queueRepository,
    networkInfo: getIt<NetworkInfo>(),
    session: session,
  );
  getIt.registerSingleton<SyncQueueManager>(syncManager);

  // Dev 3: Finance Module (automatically binds `UnifiedFinanceSyncQueueAdapter` to `SyncQueueManager`).
  FinanceModule.ensure();

  // Dev 4: Subscribers 360°, Squares, Subscription Fees, Tankers & Ledger.
  FouadModule.ensure(dio: dioClient.dio);

  // Dev 5: Dashboard, Reports, Settings Studio, WhatsApp Local Server, RBAC & Exports.
  final adminApi = DioAdminApiClient(dioClient.dio);
  final settingsCache = SharedPreferencesSettingsCache();
  await settingsCache.rehydrate();
  final settingsRepository = SettingsRepositoryImpl(
    SettingsRemoteDataSource(adminApi),
    settingsCache,
  );
  final dashboardRepository = DashboardRepositoryImpl(
    DashboardRemoteDataSource(adminApi),
    DashboardLocalDataSource(),
  );
  final rbacRepository = RbacRepositoryImpl(RbacRemoteDataSource(adminApi));
  final exportRepository = ExportRepositoryImpl(ExportRemoteDataSource(adminApi));
  final excelService = ExcelExportService.platform(exportRepository);

  getIt.registerSingleton<AdminApiClient>(adminApi);
  getIt.registerSingleton<SettingsCache>(settingsCache);
  getIt.registerSingleton<SettingsRepository>(settingsRepository);
  getIt.registerSingleton<DashboardRepository>(dashboardRepository);
  getIt.registerSingleton<RbacRepository>(rbacRepository);
  getIt.registerSingleton<ExportRepository>(exportRepository);
  getIt.registerSingleton<ExcelExportService>(excelService);

  getIt.registerSingleton<AppRouter>(AppRouter(session: session));

  await syncManager.initialize();
}
