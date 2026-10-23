import 'package:dio/dio.dart';

import '../../features/meters/data/repositories/meters_repository_impl.dart';
import '../../features/meters/domain/meters_repository.dart';
import '../../features/readings/data/datasources/readings_remote_data_source.dart';
import '../../features/readings/data/repositories/readings_repository_impl.dart';
import '../../features/readings/data/services/reading_invoice_actions_service.dart';
import '../../features/readings/domain/readings_repository.dart';
import '../database/app_database.dart';
import '../database/offline_pack_downloader.dart';
import '../database/sync_queue_manager.dart';
import '../database/sync_queue_repository.dart';

/// Composition root for the meter-reader feature set.
///
/// Pass the application's authenticated Dio client so the field module shares
/// the same base URL, token refresh, and interceptors as the rest of the app.
class FieldOperationsModule {
  FieldOperationsModule._({
    required this.database,
    required this.metersRepository,
    required this.readingsRepository,
    required this.offlinePackDownloader,
    required this.syncQueueManager,
    required this.syncQueueRepository,
    required this.invoiceActions,
  });

  static Future<FieldOperationsModule> initialize({
    required Dio dio,
    AppDatabase? database,
    bool autoStartSync = true,
    String? Function()? ownerUserIdProvider,
  }) {
    final appDatabase = database ?? AppDatabase.open();
    final metersRepository = MetersRepositoryImpl(
      dio: dio,
      database: appDatabase,
    );
    final readingsRepository = ReadingsRepositoryImpl(
      dio: dio,
      database: appDatabase,
      ownerUserIdProvider: ownerUserIdProvider,
    );
    final readingsRemote = ReadingsRemoteDataSource(dio);
    final syncManager = SyncQueueManager(
      database: appDatabase,
      sendBatch: readingsRemote.syncBatch,
    );
    if (autoStartSync) {
      syncManager.startListening();
    }

    return Future<FieldOperationsModule>.value(
      FieldOperationsModule._(
        database: appDatabase,
        metersRepository: metersRepository,
        readingsRepository: readingsRepository,
        offlinePackDownloader: OfflinePackDownloader(
          metersRepository: metersRepository,
          readingsRepository: readingsRepository,
          database: appDatabase,
          dio: dio,
        ),
        syncQueueManager: syncManager,
        syncQueueRepository: SyncQueueRepository(
          database: appDatabase,
          manager: syncManager,
        ),
        invoiceActions: ReadingInvoiceActionsService(dio),
      ),
    );
  }

  final AppDatabase database;
  final MetersRepository metersRepository;
  final ReadingsRepository readingsRepository;
  final OfflinePackDownloader offlinePackDownloader;
  final SyncQueueManager syncQueueManager;
  final SyncQueueRepository syncQueueRepository;
  final ReadingInvoiceActionsService invoiceActions;

  /// API paths are relative; the shared Dio base URL must end with `/api`.
  static const apiBasePath = '/api';

  Future<void> close() async {
    await syncQueueManager.dispose();
    await database.closeDatabase();
  }
}
