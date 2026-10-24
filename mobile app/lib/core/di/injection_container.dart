import 'dart:async';

import 'package:dio/dio.dart';
import 'package:get_it/get_it.dart';

import '../../features/admin_shared/data/admin_api_client.dart';
import '../backup/database_field_data_store.dart';
import '../backup/local_backup.dart';
import '../config/app_config.dart';
import '../../features/audit/data/audit_event_store.dart';
import '../../features/audit/services/audit_log_service.dart';
import '../../features/auth/presentation/cubit/auth_cubit.dart';
import '../../features/cash_sessions/data/cash_session_api.dart';
import '../../features/cash_sessions/services/cash_session_service.dart';
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
import '../database/database_encryption.dart';
import '../database/offline_pack_downloader.dart';
import '../diagnostics/error_reporter.dart';
import '../field_operations/field_operations_module.dart';
import '../finance/finance_module.dart';
import '../fouad/fouad_module.dart';
import '../network/api_endpoints.dart';
import '../network/dio_client.dart';
import '../network/network_info.dart';
import '../network/secure_storage_service.dart';
import '../permissions/permission_service.dart';
import '../router/app_router.dart';
import '../security/app_lock_service.dart';
import '../theme/app_theme.dart';

final GetIt getIt = GetIt.instance;

/// نسخة التطبيق تُمرَّر من `main.dart` (موجودة في pubspec) وتُرسل مع كل طلب
/// كترويسة `X-Client-Version` لتسهيل تتبّع الأخطاء في الميدان.
Future<void> configureDependencies({String? appVersion}) async {
  if (getIt.isRegistered<SessionService>()) return;

  final secureStorage = SecureStorageService();
  final errorReporter = RecordingErrorReporter();
  final dioClient = DioClient(
    secureStorage,
    errorReporter: errorReporter,
    appVersion: appVersion,
  );
  getIt.registerSingleton<SecureStorageService>(secureStorage);
  getIt.registerSingleton<ErrorReporter>(errorReporter);

  // قفل التطبيق (S5-01): رقم محلي + مهلة خمول. يُحمَّل قبل أول رسم للشاشة.
  final appLock = AppLockService();
  getIt.registerSingleton<AppLockService>(appLock);
  unawaited(appLock.load());
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
    // S5-02: مفتاح SQLCipher من المخزن الآمن للنظام. يعيد `null` تلقائياً
    // عندما لا يكون البناء بـ`--dart-define=ENCRYPT_LOCAL_DB=true`.
    keyProvider: getIt.isRegistered<DatabaseKeyProvider>()
        ? getIt<DatabaseKeyProvider>()
        : SecureStorageDatabaseKeyProvider(),
  );
  getIt.registerSingleton<FieldOperationsModule>(fieldModule);
  getIt.registerSingleton<AppDatabase>(fieldModule.database);
  // S7-08: نسخة احتياطية محلية لبيانات الميدان (تكتب داخل مستندات التطبيق).
  getIt.registerSingleton<LocalBackupService>(
    LocalBackupService(
      store: DatabaseFieldDataStore(fieldModule.database),
      storage: DeviceBackupStorage(),
      appVersion: AppConfig.appVersion,
    ),
  );
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

  // سجل التدقيق المحلي (S4-05): يُسجّل أحداث الميدان الحسّاسة محلياً ثم
  // يرفعها مجمّعة إلى `POST /audit-events` — الفاعل يحدده الخادم من التوكن.
  final auditLog = AuditLogService(
    store: SharedPreferencesAuditStore(),
    upload: (events) => dioClient.dio.post<dynamic>(
      ApiEndpoints.auditEvents,
      data: <String, dynamic>{'events': events},
    ),
  );
  getIt.registerSingleton<AuditLogService>(auditLog);

  // Dev 3: Finance Module (automatically binds `UnifiedFinanceSyncQueueAdapter` to `SyncQueueManager`).
  FinanceModule.ensure();

  // Dev 4: Subscribers 360°, Squares, Subscription Fees, Tankers & Ledger.
  FouadModule.ensure(dio: dioClient.dio);

  // صندوق المحصّل (2.0 — الفصل 5.2): حالة واحدة مشتركة بين شاشة التحصيل
  // وشاشة الصندوق، وتُصفَّر تلقائياً عند انتهاء الجلسة.
  final cashSessions = CashSessionService(
    api: CashSessionApi(dioClient.dio),
    audit: auditLog,
  );
  getIt.registerSingleton<CashSessionService>(cashSessions);
  session.addSessionClearedListener(cashSessions.reset);
  session.addSessionClearedListener(dioClient.tokenRefresh.reset);
  String? lastCashUser;
  session.addListener(() {
    final userId = session.user?['id']?.toString();
    if (!session.isReady || !session.isAuthenticated) {
      lastCashUser = null;
      return;
    }
    // نجلب الصندوق مرة واحدة لكل مستخدم، لا مع كل تحديث للجلسة.
    if (userId != null && userId != lastCashUser) {
      lastCashUser = userId;
      unawaited(cashSessions.refresh());
    }
  });

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
  final devicesRepository = DevicesRepositoryImpl(DevicesRemoteDataSource(adminApi));
  final excelService = ExcelExportService.platform(exportRepository);

  getIt.registerSingleton<AdminApiClient>(adminApi);
  getIt.registerSingleton<SettingsCache>(settingsCache);
  getIt.registerSingleton<SettingsRepository>(settingsRepository);
  getIt.registerSingleton<DashboardRepository>(dashboardRepository);
  getIt.registerSingleton<RbacRepository>(rbacRepository);
  getIt.registerSingleton<ExportRepository>(exportRepository);
  getIt.registerSingleton<DevicesRepository>(devicesRepository);
  getIt.registerSingleton<ExcelExportService>(excelService);

  getIt.registerSingleton<AppRouter>(AppRouter(session: session));

  await syncManager.initialize();
}
