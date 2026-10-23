import 'package:get_it/get_it.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../auth/session_store.dart';
import '../config/app_config.dart';
import '../settings/finance_config.dart';
import '../local_storage/sync_queue.dart';
import '../network/api_client.dart';
import '../network/network_info.dart';
import '../services/pdf_print_service.dart';
import '../services/thermal_printer_service.dart';
import '../services/whatsapp_launcher_service.dart';
import '../settings/finance_config.dart';
import '../../features/invoices/data/datasources/invoices_remote_datasource.dart';
import '../../features/invoices/data/datasources/references_remote_datasource.dart';
import '../../features/invoices/data/repositories/invoices_repository_impl.dart';
import '../../features/invoices/domain/repositories/invoices_repository.dart';
import '../../features/payments/data/datasources/payments_remote_datasource.dart';
import '../../features/payments/data/repositories/payments_repository_impl.dart';
import '../../features/payments/domain/repositories/payments_repository.dart';
import '../../features/tariffs/data/datasources/tariffs_remote_datasource.dart';
import '../../features/tariffs/data/repositories/tariffs_repository_impl.dart';
import '../../features/tariffs/domain/repositories/tariffs_repository.dart';

/// وعاء حقن التبعيات العام (`get_it`).
///
/// ⚠️ ملاحظة دمج: الملف الأصلي يملكه Dev 1. المناطق المعلَّمة بـ `[DEV-3]` هي
/// تسجيلات Dev 3 فقط — عند الدمج تُضاف داخل نفس الملف بين علامتي BEGIN/END
/// دون تعديل بقية الأقسام.
final GetIt sl = GetIt.instance;

void registerCoreContracts({
  required AppConfig config,
  required SharedPreferences prefs,
  required SessionStore session,
}) {
  sl
    ..registerLazySingleton<SharedPreferences>(() => prefs)
    ..registerLazySingleton<SessionStore>(() => session)
    ..registerLazySingleton<AppConfig>(() => config)
    ..registerLazySingleton<NetworkInfo>(() => NetworkInfo())
    ..registerLazySingleton<AuthTokenProvider>(() => session)
    ..registerLazySingleton<DioClient>(
      () => DioClient(
        config: sl<AppConfig>(),
        tokenProvider: sl<AuthTokenProvider>(),
      ),
    )
    ..registerLazySingleton<FinanceConfigRepository>(
      () => FinanceConfigRepository(client: sl<DioClient>(), prefs: sl<SharedPreferences>()),
    );
}

// ─── BEGIN [DEV-3] ────────────────────────────────────────────────────────
void registerFinanceModule({required bool offlineEnabled}) {
  sl
    // مصادر البيانات
    ..registerLazySingleton<InvoicesRemoteDataSource>(
      () => InvoicesRemoteDataSource(sl<DioClient>()),
    )
    ..registerLazySingleton<ReferencesRemoteDataSource>(
      () => ReferencesRemoteDataSource(sl<DioClient>()),
    )
    ..registerLazySingleton<PaymentsRemoteDataSource>(
      () => PaymentsRemoteDataSource(sl<DioClient>()),
    )
    ..registerLazySingleton<TariffsRemoteDataSource>(
      () => TariffsRemoteDataSource(sl<DioClient>()),
    )
    // المستودعات
    ..registerLazySingleton<InvoicesRepository>(
      () => InvoicesRepositoryImpl(
        remote: sl<InvoicesRemoteDataSource>(),
        config: sl<AppConfig>(),
      ),
    )
    ..registerLazySingleton<PaymentsRepository>(
      () => PaymentsRepositoryImpl(
        remote: sl<PaymentsRemoteDataSource>(),
        invoices: sl<InvoicesRemoteDataSource>(),
        networkInfo: sl<NetworkInfo>(),
        syncQueue: offlineEnabled ? sl<SyncQueueRepository>() : null,
        config: sl<AppConfig>(),
      ),
    )
    ..registerLazySingleton<TariffsRepository>(
      () => TariffsRepositoryImpl(sl<TariffsRemoteDataSource>()),
    )
    // خدمات الطباعة والواتساب
    ..registerLazySingleton<PdfPrintService>(
      () => PdfPrintService(client: sl<DioClient>(), config: sl<AppConfig>()),
    )
    ..registerLazySingleton<ThermalPrinterService>(
      () => ThermalPrinterService(
        channel: const BluetoothThermalChannel(),
        configRepository: sl<FinanceConfigRepository>(),
        prefs: sl<SharedPreferences>(),
      ),
    )
    ..registerLazySingleton<WhatsAppLauncherService>(
      () => WhatsAppLauncherService(
        client: sl<DioClient>(),
        configRepository: sl<FinanceConfigRepository>(),
      ),
    );
}

/// يُسجَّل تنفيذ Dev 2 (Drift) عند الدمج؛ وإلا نستخدم التنفيذ المحلي المبسط.
void registerSyncQueueFallback(SharedPreferences prefs) {
  if (!sl.isRegistered<SyncQueueRepository>()) {
    sl.registerLazySingleton<SyncQueueRepository>(
      () => LocalSyncQueueRepository(prefs),
    );
  }
}
// ─── END [DEV-3] ──────────────────────────────────────────────────────────
