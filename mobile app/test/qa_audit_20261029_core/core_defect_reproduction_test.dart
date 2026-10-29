// Diagnostic characterization tests: PASS means the named defect was reproduced.
// These deliberately document current behavior; they are not acceptance tests.
import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:water_project/core/auth/session_service.dart';
import 'package:water_project/core/backup/local_backup.dart';
import 'package:water_project/core/network/interceptors/offline_cache_interceptor.dart';
import 'package:water_project/core/network/secure_storage_service.dart';
import 'package:water_project/core/network/token_refresh_service.dart';
import 'package:water_project/core/security/app_lock_service.dart';
import 'package:water_project/features/field_route/data/route_visit_store.dart';
import 'package:water_project/features/offline_sync/services/field_data_sync_service.dart';
import 'package:water_project/features/readings/data/reading_fault_log.dart';
import 'package:water_project/features/readings/domain/reading_fault.dart';

class _Adapter implements HttpClientAdapter {
  _Adapter(this.respond);
  final FutureOr<ResponseBody> Function(RequestOptions) respond;
  final requests = <RequestOptions>[];
  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    requests.add(options);
    return respond(options);
  }

  @override
  void close({bool force = false}) {}
}

ResponseBody _json(Map<String, dynamic> data, [int status = 200]) =>
    ResponseBody.fromString(
      jsonEncode(data),
      status,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );

class _Store implements FieldDataStore {
  Map<String, dynamic> data = {
    'meters': [
      {'server_id': 1},
    ],
  };
  @override
  Future<Map<String, dynamic>> exportFieldData() async => data;
  @override
  Future<void> importFieldData(
    Map<String, dynamic> data, {
    bool pendingActions = true,
  }) async => this.data = data;
}

class _BackupFiles extends BackupStorage {
  final files = <String, String>{};
  @override
  Future<String> write(String name, String contents) async {
    files[name] = contents;
    return name;
  }

  @override
  Future<List<String>> list() async => files.keys.toList();
  @override
  Future<String> read(String name) async => files[name]!;
  @override
  Future<void> delete(String name) async => files.remove(name);
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  setUp(() {
    FlutterSecureStorage.setMockInitialValues({});
    SharedPreferences.setMockInitialValues({});
  });

  group(
    'Known core defect diagnostic probes',
    () {
      test(
        'AUDIT BUG: remember=false token becomes persistent after refresh',
        () async {
          final storage = SecureStorageService();
          final dio = Dio(BaseOptions(baseUrl: 'https://water.test/api/'));
          dio.httpClientAdapter = _Adapter(
            (options) => options.path == 'login'
                ? _json({
                    'access_token': 'temporary-token',
                    'user': {'id': 1},
                  })
                : _json({'access_token': 'refreshed-token'}),
          );
          final session = SessionService(
            dio: dio,
            secureStorage: storage,
            offlineCache: OfflineCacheInterceptor(),
          );
          await session.login(
            email: 'audit@water.test',
            password: 'test',
            rememberSession: false,
          );
          expect(
            await const FlutterSecureStorage().read(key: 'water.access_token'),
            isNull,
          );
          await TokenRefreshService(dio: dio, secureStorage: storage).refresh();
          // A new storage instance simulates process restart (no volatile token).
          expect(
            await SecureStorageService().readAccessToken(),
            'refreshed-token',
          );
          session.dispose();
        },
      );

      test(
        'AUDIT BUG: in-flight refresh resurrects token after logout reset',
        () async {
          final storage = SecureStorageService();
          await storage.writeAccessToken('account-a-token');
          final started = Completer<void>();
          final response = Completer<ResponseBody>();
          final dio = Dio(BaseOptions(baseUrl: 'https://water.test/api/'));
          dio.httpClientAdapter = _Adapter((_) {
            started.complete();
            return response.future;
          });
          final service = TokenRefreshService(dio: dio, secureStorage: storage);
          final pending = service.refresh();
          await started.future;
          await storage.clearSession();
          service.reset();
          expect(await storage.readAccessToken(), isNull);
          response.complete(_json({'access_token': 'late-account-a-token'}));
          await pending;
          expect(await storage.readAccessToken(), 'late-account-a-token');
        },
      );

      test(
        'AUDIT BUG: late account A refresh overwrites newly logged-in account B token',
        () async {
          final storage = SecureStorageService();
          await storage.writeAccessToken('account-a-token');
          final started = Completer<void>();
          final response = Completer<ResponseBody>();
          final dio = Dio(BaseOptions(baseUrl: 'https://water.test/api/'));
          dio.httpClientAdapter = _Adapter((_) {
            started.complete();
            return response.future;
          });
          final service = TokenRefreshService(dio: dio, secureStorage: storage);
          final pending = service.refresh();
          await started.future;
          await storage.clearSession();
          service.reset();
          await storage.writeAccessToken('account-b-token');
          expect(await storage.readAccessToken(), 'account-b-token');
          response.complete(_json({'access_token': 'late-account-a-token'}));
          await pending;
          expect(await storage.readAccessToken(), 'late-account-a-token');
        },
      );

      test(
        'AUDIT BUG: 201 faults are sent unsplit against server max 200',
        () async {
          final day = DateTime(2026, 10, 29, 12);
          final faults = List.generate(
            201,
            (i) => ReadingFault(
              meterId: i + 1,
              reason: ReadingFaultReason.meterBroken,
              recordedAt: day,
            ),
          );
          final dio = Dio(BaseOptions(baseUrl: 'https://water.test/api/'));
          final adapter = _Adapter((options) {
            final payload = options.data as Map;
            final count = (payload['faults'] as List).length;
            return count > 200
                ? _json({
                    'message':
                        'The faults field must not have more than 200 items.',
                  }, 422)
                : _json({'synced_count': count});
          });
          dio.httpClientAdapter = adapter;
          final service = FieldDataSyncService(
            dio: dio,
            faultLog: InMemoryReadingFaultLog(faults),
            visitStore: InMemoryRouteVisitStore(),
          );
          final result = await service.syncReadingFaults(now: day);
          expect(result.ok, isFalse);
          expect(
            result.error,
            isA<DioException>().having(
              (e) => e.response?.statusCode,
              'status',
              422,
            ),
          );
          expect(adapter.requests, hasLength(1));
          expect(
            ((adapter.requests.single.data as Map)['faults'] as List),
            hasLength(201),
          );
        },
      );

      test(
        'AUDIT BUG: unsynced 14-day-old fault is omitted without warning',
        () async {
          final day = DateTime(2026, 10, 29, 12);
          final fault = ReadingFault(
            meterId: 1,
            reason: ReadingFaultReason.meterBroken,
            recordedAt: day.subtract(const Duration(days: 14)),
          );
          final dio = Dio(BaseOptions(baseUrl: 'https://water.test/api/'));
          final adapter = _Adapter((_) => _json({'synced_count': 1}));
          dio.httpClientAdapter = adapter;
          final service = FieldDataSyncService(
            dio: dio,
            faultLog: InMemoryReadingFaultLog([fault]),
            visitStore: InMemoryRouteVisitStore(),
          );
          final result = await service.syncReadingFaults(now: day);
          expect(result.ok, isTrue);
          expect(result.synced, 0);
          expect(result.skipped, 0);
          expect(adapter.requests, isEmpty);
        },
      );

      test(
        'AUDIT BUG: field records survive account change and upload as new account',
        () async {
          final day = DateTime(2026, 10, 29, 12);
          final firstAccountStore = SharedPreferencesReadingFaultLog();
          await firstAccountStore.record(
            ReadingFault(
              meterId: 19,
              reason: ReadingFaultReason.meterBroken,
              recordedAt: day,
            ),
          );
          // Account A signs out; the real store has no account parameter or session listener.
          final nextAccountStore = SharedPreferencesReadingFaultLog();
          final dio = Dio(
            BaseOptions(
              baseUrl: 'https://water.test/api/',
              headers: {'Authorization': 'Bearer account-b-token'},
            ),
          );
          final adapter = _Adapter((_) => _json({'synced_count': 1}));
          dio.httpClientAdapter = adapter;
          final result = await FieldDataSyncService(
            dio: dio,
            faultLog: nextAccountStore,
            visitStore: InMemoryRouteVisitStore(),
          ).syncReadingFaults(now: day);
          expect(result.synced, 1);
          expect(
            adapter.requests.single.headers['Authorization'],
            'Bearer account-b-token',
          );
          final sentFaults =
              (adapter.requests.single.data as Map)['faults'] as List;
          expect((sentFaults.single as Map)['meter_id'], 19);
        },
      );

      test(
        'AUDIT BUG: two backups in same second overwrite previous snapshot',
        () async {
          final store = _Store();
          final files = _BackupFiles();
          final service = LocalBackupService(
            store: store,
            storage: files,
            clock: () => DateTime.utc(2026, 10, 29, 12),
          );
          final first = await service.createBackup();
          store.data = {
            'meters': [
              {'server_id': 2},
            ],
          };
          final second = await service.createBackup();
          expect(first.name, second.name);
          expect(files.files, hasLength(1));
          final backup = jsonDecode(files.files[first.name]!) as Map;
          final data = backup['data'] as Map;
          final meters = data['meters'] as List;
          expect((meters.single as Map)['server_id'], 2);
        },
      );

      test(
        'AUDIT BUG: PIN cooldown can be bypassed by recreating service',
        () async {
          final storage = InMemoryLockStorage();
          final service = AppLockService(storage: storage);
          await service.load();
          await service.setPin('1234');
          for (var i = 0; i < 5; i++) {
            await service.unlock('9999');
          }
          expect(service.isLockedOut, isTrue);
          final restarted = AppLockService(storage: storage);
          await restarted.load();
          expect(restarted.isLockedOut, isFalse);
          expect(await restarted.unlock('9999'), UnlockResult.wrongPin);
          service.dispose();
          restarted.dispose();
        },
      );
    },
    skip: !const bool.fromEnvironment('RUN_KNOWN_BUG_PROBES'),
  );
}
