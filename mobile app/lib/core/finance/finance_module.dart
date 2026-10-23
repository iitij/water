/// نقطة تركيب الوحدة المالية (الفواتير + السندات + التسعيرات + محركات المخرجات).
///
/// النمط متبع مع `core/field_operations/field_operations_module.dart` عند Dev 2:
/// طبقة Dev 3 تسجّل تبعياتها بنفسها في `getIt` الخاص بـ Dev 1 عند أول استخدام
/// (`sl<T>()` يستدعي [ensure])، فلا يعدّل أي ملف من ملفات Dev 1، ولا يحتاج المضيف
/// إلى سطر تهيئة إجباري — وإن أراد التهيئة المبكرة فسطر واحد يكفي:
///
/// ```dart
/// // في bootstrap() بعد configureDependencies():
/// FinanceModule.ensure();
/// ```
///
/// لتعريض مسارات SCR-13…SCR-21 على `GoRouter` الرئيسي:
///
/// ```dart
/// routes: <RouteBase>[...financeRoutes, ...FinanceModule.routes]
/// ```
import 'package:flutter/foundation.dart' show visibleForTesting;
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';

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
import '../network/dio_client.dart';
import '../network/network_info.dart';
import '../router/finance_routes.dart';
import '../services/pdf_print_service.dart';
import '../services/thermal_printer_service.dart';
import '../services/whatsapp_launcher_service.dart';
import '../settings/finance_config.dart';
import 'finance_api_client.dart';
import 'finance_sync_queue.dart';

class FinanceModule {
  const FinanceModule._();

  static bool _registered = false;

  /// مسارات الوحدة المالية (SCR-13…SCR-21) جاهزة للإضافة إلى `GoRouter` الرئيسي.
  static List<RouteBase> get routes => buildFinanceRoutes();

  /// هل التبعيات مسجّلة؟ (لا副作用).
  static bool get isRegistered => GetIt.I.isRegistered<InvoicesRepository>();

  /// تسجيل idempotent — يُستدعى من `sl<T>()` عند أول استخدام.
  static void ensure() {
    if (_registered) return;
    final getIt = GetIt.I;
    if (getIt.isRegistered<InvoicesRepository>()) {
      _registered = true;
      return;
    }
    if (!getIt.isRegistered<DioClient>()) {
      throw StateError(
        'الوحدة المالية تحتاج `configureDependencies()` الخاص بـ Dev 1 أولاً '
        '(يُستدعى في bootstrap قبل runApp).',
      );
    }

    final client = FinanceApiClient(getIt<DioClient>().dio);
    financeApiBaseUrl = client.dio.options.baseUrl;
    final configRepository = FinanceConfigRepository(client: client);

    getIt
      ..registerLazySingleton<FinanceApiClient>(() => client)
      ..registerLazySingleton<FinanceConfigRepository>(() => configRepository)
      // مصادر البيانات
      ..registerLazySingleton<InvoicesRemoteDataSource>(
        () => InvoicesRemoteDataSource(client),
      )
      ..registerLazySingleton<ReferencesRemoteDataSource>(
        () => ReferencesRemoteDataSource(client),
      )
      ..registerLazySingleton<PaymentsRemoteDataSource>(
        () => PaymentsRemoteDataSource(client),
      )
      ..registerLazySingleton<TariffsRemoteDataSource>(
        () => TariffsRemoteDataSource(client),
      )
      // طابور المزامنة المحلي (يُستبدل بـ SyncQueueManager عند Dev 1 عند اكتماله)
      ..registerLazySingleton<FinanceSyncQueueRepository>(
        LocalSyncQueueRepository.new,
      )
      // المستودعات
      ..registerLazySingleton<InvoicesRepository>(
        () => InvoicesRepositoryImpl(remote: getIt<InvoicesRemoteDataSource>()),
      )
      ..registerLazySingleton<PaymentsRepository>(
        () => PaymentsRepositoryImpl(
          remote: getIt<PaymentsRemoteDataSource>(),
          invoices: getIt<InvoicesRemoteDataSource>(),
          networkInfo: getIt<NetworkInfo>(),
          syncQueue: getIt<FinanceSyncQueueRepository>(),
        ),
      )
      ..registerLazySingleton<TariffsRepository>(
        () => TariffsRepositoryImpl(getIt<TariffsRemoteDataSource>()),
      )
      // محركات المخرجات
      ..registerLazySingleton<PdfPrintService>(
        () => PdfPrintService(client: client),
      )
      ..registerLazySingleton<ThermalPrinterService>(
        () => ThermalPrinterService(
          channel: const BluetoothThermalChannel(),
          configRepository: configRepository,
        ),
      )
      ..registerLazySingleton<WhatsAppLauncherService>(
        () => WhatsAppLauncherService(
          client: client,
          configRepository: configRepository,
        ),
      );

    _registered = true;
  }

  /// يُستدعى في الاختبارات فقط: يفرّغ تسجيلات الوحدة ثم يعيد [ensure] قابلاً للتنفيذ.
  @visibleForTesting
  static void resetForTests() {
    final getIt = GetIt.I;
    if (getIt.isRegistered<FinanceApiClient>()) getIt.unregister<FinanceApiClient>();
    if (getIt.isRegistered<FinanceConfigRepository>()) {
      getIt.unregister<FinanceConfigRepository>();
    }
    if (getIt.isRegistered<InvoicesRemoteDataSource>()) {
      getIt.unregister<InvoicesRemoteDataSource>();
    }
    if (getIt.isRegistered<ReferencesRemoteDataSource>()) {
      getIt.unregister<ReferencesRemoteDataSource>();
    }
    if (getIt.isRegistered<PaymentsRemoteDataSource>()) {
      getIt.unregister<PaymentsRemoteDataSource>();
    }
    if (getIt.isRegistered<TariffsRemoteDataSource>()) {
      getIt.unregister<TariffsRemoteDataSource>();
    }
    if (getIt.isRegistered<FinanceSyncQueueRepository>()) {
      getIt.unregister<FinanceSyncQueueRepository>();
    }
    if (getIt.isRegistered<InvoicesRepository>()) getIt.unregister<InvoicesRepository>();
    if (getIt.isRegistered<PaymentsRepository>()) getIt.unregister<PaymentsRepository>();
    if (getIt.isRegistered<TariffsRepository>()) getIt.unregister<TariffsRepository>();
    if (getIt.isRegistered<PdfPrintService>()) getIt.unregister<PdfPrintService>();
    if (getIt.isRegistered<ThermalPrinterService>()) {
      getIt.unregister<ThermalPrinterService>();
    }
    if (getIt.isRegistered<WhatsAppLauncherService>()) {
      getIt.unregister<WhatsAppLauncherService>();
    }
    _registered = false;
  }
}
