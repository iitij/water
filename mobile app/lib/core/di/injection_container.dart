import 'dart:async';
import 'dart:convert';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:dio/dio.dart';
import 'package:get_it/get_it.dart';
import 'package:water_project/core/notifications/notification_service.dart';

import '../../features/admin_shared/data/admin_api_client.dart';
import '../../features/audit/data/audit_event_store.dart';
import '../../features/audit/services/audit_log_service.dart';
import '../../features/auth/presentation/cubit/auth_cubit.dart';
import '../../features/cash_sessions/data/cash_session_api.dart';
import '../../features/cash_sessions/services/cash_session_service.dart';
import '../../features/dashboard/dashboard.dart';
import '../../features/devices/devices.dart';
import '../../features/exports/exports.dart';
import '../../features/field_route/data/route_visit_store.dart';
import '../../features/meters/domain/meters_repository.dart';
import '../../features/offline_sync/data/drift_sync_queue_repository.dart';
import '../../features/offline_sync/domain/sync_queue_repository.dart';
import '../../features/offline_sync/services/field_data_sync_service.dart';
import '../../features/offline_sync/services/sync_queue_manager.dart';
import '../../features/readings/data/reading_fault_log.dart';
import '../../features/readings/data/services/reading_invoice_actions_service.dart';
import '../../features/readings/domain/readings_repository.dart';
import '../../features/settings/settings.dart';
import '../auth/session_service.dart';
import '../backup/database_field_data_store.dart';
import '../backup/local_backup.dart';
import '../config/app_config.dart';
import '../database/app_database.dart';
import '../database/database_encryption.dart';
import '../database/offline_pack_downloader.dart';
import '../diagnostics/api_log_manager.dart';
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
import '../security/developer_access_service.dart';
import '../theme/app_theme.dart';

final GetIt getIt = GetIt.instance;

/// نسخة التطبيق تُمرَّر من `main.dart` (موجودة في pubspec) وتُرسل مع كل طلب
/// كترويسة `X-Client-Version` لتسهيل تتبّع الأخطاء في الميدان.
Future<void> configureDependencies({String? appVersion}) async {
  if (getIt.isRegistered<SessionService>()) return;

  final secureStorage = SecureStorageService();
  final errorReporter = RecordingErrorReporter(
    // رفع تقارير الأعطال إلى الخادم عند أول اتصال ناجح — كانت no-op سابقاً.
    // يُجلب Dio من الحاوية داخل الإغلاق لأنه يُسجّل بعد هذه النقطة.
    uploader: (reports) => getIt<Dio>().post<dynamic>(
      'system/error-reports',
      data: <String, dynamic>{
        'reports': reports
            .map(
              (report) => <String, dynamic>{
                'message': report.message,
                'context': <String, dynamic>{
                  if (report.context != null) 'name': report.context,
                  if (report.statusCode != null)
                    'status_code': report.statusCode,
                  if (report.endpoint != null) 'endpoint': report.endpoint,
                  'fatal': report.fatal,
                },
                'occurred_at': report.occurredAt.toIso8601String(),
                'app_version': appVersion ?? AppConfig.appVersion,
              },
            )
            .toList(growable: false),
      },
    ),
  );
  final apiLogManager = ApiLogManager.instance;
  final dioClient = DioClient(
    secureStorage,
    errorReporter: errorReporter,
    apiLogManager: apiLogManager,
    appVersion: appVersion,
  );
  getIt.registerSingleton<SecureStorageService>(secureStorage);
  getIt.registerSingleton<ErrorReporter>(errorReporter);
  getIt.registerSingleton<ApiLogManager>(apiLogManager);

  // قفل التطبيق (S5-01): رقم محلي + مهلة خمول. يُحمَّل قبل أول رسم للشاشة.
  final appLock = AppLockService();
  getIt.registerSingleton<AppLockService>(appLock);
  unawaited(appLock.load());
  getIt.registerSingleton<DioClient>(dioClient);
  getIt.registerSingleton<Dio>(dioClient.dio);
  final developerAccess = DeveloperAccessService(dio: dioClient.dio);
  getIt.registerSingleton<DeveloperAccessService>(developerAccess);
  getIt.registerSingleton(dioClient.offlineCache);
  getIt.registerSingleton<NetworkInfo>(NetworkInfo());

  final session = SessionService(
    dio: dioClient.dio,
    secureStorage: secureStorage,
    offlineCache: dioClient.offlineCache,
  );
  getIt.registerSingleton<SessionService>(session);
  session.addSessionClearedListener(developerAccess.lock);
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
  // S7-09: تذكيرات محلية — تُسجّل مرة واحدة، والجدولة تُعاد في bootstrap.
  getIt.registerLazySingleton<ReminderScheduler>(FlutterReminderScheduler.new);
  getIt.registerLazySingleton<ReminderPreferencesStore>(
    SharedPrefsReminderStore.new,
  );
  getIt.registerSingleton<LocalBackupService>(
    LocalBackupService(
      store: DatabaseFieldDataStore(fieldModule.database),
      storage: DeviceBackupStorage(),
      appVersion: AppConfig.appVersion,
    ),
  );
  getIt.registerSingleton<MetersRepository>(fieldModule.metersRepository);
  getIt.registerSingleton<ReadingsRepository>(fieldModule.readingsRepository);
  getIt.registerSingleton<OfflinePackDownloader>(
    fieldModule.offlinePackDownloader,
  );
  getIt.registerSingleton<ReadingInvoiceActionsService>(
    fieldModule.invoiceActions,
  );

  // Unified Drift/SQLite Offline Sync Queue (migrates any legacy SharedPreferences items).
  final queueRepository = DriftSyncQueueRepository(
    database: fieldModule.database,
  );
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
  String? auditScope() {
    final userId = session.user?['id']?.toString();
    if (!session.isAuthenticated || userId == null || userId.isEmpty) {
      return null;
    }
    return jsonEncode([dioClient.dio.options.baseUrl, userId]);
  }

  final auditLog = AuditLogService(
    store: SharedPreferencesAuditStore(),
    scopeProvider: auditScope,
    scopedStoreFactory: (scope) => SharedPreferencesAuditStore(scopeKey: scope),
    upload: (events) async {
      final scope = auditScope();
      final sessionToken = session.token;
      final baseUrl = dioClient.dio.options.baseUrl;
      final token = await secureStorage.readAccessToken();
      if (scope == null ||
          scope != auditScope() ||
          sessionToken != session.token ||
          token == null ||
          token.isEmpty) {
        throw StateError('تغيرت الجلسة؛ سيبقى سجل التدقيق محفوظاً لحسابه.');
      }
      // Pin both destination and credential. Auth refresh must never retry this
      // account's payload with a different user's newly stored token.
      final response = await dioClient.dio.post<dynamic>(
        Uri.parse(baseUrl).resolve(ApiEndpoints.auditEvents).toString(),
        data: <String, dynamic>{'events': events},
        options: Options(
          headers: {'Authorization': 'Bearer $token'},
          extra: {'skipAuth': true, 'skipRefresh': true},
          followRedirects: false,
          // A late 401 from a previous account must not expire a new session.
          validateStatus: (status) =>
              status == 401 ||
              (status != null && status >= 200 && status < 300),
        ),
      );
      if (response.statusCode == 401) {
        throw StateError('تعذّر اعتماد رفع سجل التدقيق؛ أعد تسجيل الدخول.');
      }
    },
  );
  session.addListener(auditLog.sessionChanged);
  session.addSessionClearedListener(auditLog.sessionChanged);
  getIt.registerSingleton<AuditLogService>(auditLog);

  // مزامنة بيانات الميدان المحلية (أعطال القراءة + زيارات الجولة) — كانت
  // محلية فقط وتموت بفقدان الجهاز؛ الآن تُرفع فور التسجيل وعند عودة الاتصال.
  final fieldDataSync = FieldDataSyncService(
    dio: dioClient.dio,
    faultLog: SharedPreferencesReadingFaultLog(),
    visitStore: SharedPreferencesRouteVisitStore(),
  );
  getIt.registerSingleton<FieldDataSyncService>(fieldDataSync);
  getIt.registerSingleton<RouteVisitStore>(SharedPreferencesRouteVisitStore());

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
  final exportRepository = ExportRepositoryImpl(
    ExportRemoteDataSource(adminApi),
  );
  final devicesRepository = DevicesRepositoryImpl(
    DevicesRemoteDataSource(adminApi),
  );
  final excelService = ExcelExportService.platform(exportRepository);

  getIt.registerSingleton<AdminApiClient>(adminApi);
  getIt.registerSingleton<SettingsCache>(settingsCache);
  getIt.registerSingleton<SettingsRepository>(settingsRepository);
  getIt.registerSingleton<DashboardRepository>(dashboardRepository);
  getIt.registerSingleton<ExportRepository>(exportRepository);
  getIt.registerSingleton<DevicesRepository>(devicesRepository);
  getIt.registerSingleton<ExcelExportService>(excelService);

  getIt.registerSingleton<AppRouter>(AppRouter(session: session));

  // عند عودة الاتصال: ارفع كل ما تعذّر رفعه سابقاً — الأحداث المدققة،
  // تقارير الأعطال، أعطال القراءة، وزيارات الجولة. (قائمة القراءات/المدفوعات
  // لدى SyncQueueManager مستقلّة ويعيد المحاولة بنفسه عند الاتصال.)
  NetworkInfo().onConnectivityChanged.listen((results) {
    final online = results.any((result) => result != ConnectivityResult.none);
    if (!online) return;
    unawaited(auditLog.flush());
    unawaited(errorReporter.flush());
    unawaited(fieldDataSync.syncAll());
  });

  await syncManager.initialize();
}
