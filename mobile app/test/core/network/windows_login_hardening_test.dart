import 'dart:async';
import 'dart:io';

import 'package:dio/dio.dart';
import 'package:dio/io.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/auth/session_service.dart';
import 'package:water_project/core/diagnostics/api_log_manager.dart';
import 'package:water_project/core/diagnostics/error_reporter.dart';
import 'package:water_project/core/error/failures.dart';
import 'package:water_project/core/l10n/app_strings.dart';
import 'package:water_project/core/network/device_identity.dart';
import 'package:water_project/core/network/dio_client.dart';
import 'package:water_project/core/network/interceptors/error_interceptor.dart';
import 'package:water_project/core/network/secure_storage_service.dart';
import 'package:water_project/core/network/windows_secure_storage_lock.dart';
import 'package:water_project/core/security/app_lock_service.dart';
import 'package:water_project/features/auth/presentation/cubit/auth_cubit.dart';
import 'package:water_project/features/auth/presentation/pages/login_screen.dart';

class _MockSessionService extends Mock implements SessionService {}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('ErrorInterceptor and FailureMapper unknown transport classification', () {
    final req = RequestOptions(path: 'login');

    test('maps TLS/handshake failures to a dedicated TLS message and NetworkFailure', () async {
      final interceptor = ErrorInterceptor();
      for (final errObj in <Object>[
        const HandshakeException('TLS handshake failed'),
        const TlsException('TLS error'),
        const CertificateException('Untrusted certificate'),
      ]) {
        final dioError = DioException(
          requestOptions: req,
          type: DioExceptionType.unknown,
          error: errObj,
        );
        final completer = Completer<DioException>();
        interceptor.onError(dioError, _CaptureErrorHandler(completer));
        final rejected = await completer.future;
        expect(rejected.message, contains('TLS'));
        expect(FailureMapper.from(dioError), isA<NetworkFailure>());
      }
    });

    test('maps post-connect SocketException and HttpException to connection interruption message', () async {
      final interceptor = ErrorInterceptor();
      for (final errObj in <Object>[
        const SocketException('An established connection was aborted by the software in your host machine'),
        const HttpException('Connection closed while receiving data'),
      ]) {
        final dioError = DioException(
          requestOptions: req,
          type: DioExceptionType.unknown,
          error: errObj,
        );
        final completer = Completer<DioException>();
        interceptor.onError(dioError, _CaptureErrorHandler(completer));
        final rejected = await completer.future;
        expect(rejected.message, contains('انقطع الاتصال'));
        expect(FailureMapper.from(dioError), isA<NetworkFailure>());
      }
    });

    test('maps FormatException under unknown type to invalid server format message', () async {
      final interceptor = ErrorInterceptor();
      final dioError = DioException(
        requestOptions: req,
        type: DioExceptionType.unknown,
        error: const FormatException('Unexpected character <'),
      );
      final completer = Completer<DioException>();
      interceptor.onError(dioError, _CaptureErrorHandler(completer));
      final rejected = await completer.future;
      expect(rejected.message, contains('بصيغة غير متوقعة'));
      final mapped = FailureMapper.from(dioError);
      expect(mapped, isA<ServerFailure>());
      expect(mapped.message, AppStringsDefaults.invalidResponse);
    });
  });

  test('DioClient sets a short idleTimeout HttpClient on Windows', () {
    final client = DioClient(SecureStorageService());
    if (!kIsWeb && Platform.isWindows) {
      expect(client.dio.httpClientAdapter, isA<IOHttpClientAdapter>());
      final adapter = client.dio.httpClientAdapter as IOHttpClientAdapter;
      final httpClient = adapter.createHttpClient!();
      addTearDown(() => httpClient.close(force: true));
      expect(httpClient.idleTimeout, const Duration(seconds: 2));
    }
  });

  test('WindowsSecureStorageLock serializes concurrent writes across independent wrappers', () async {
    var inFlight = 0;
    var maxInFlight = 0;
    final store = <String, String>{};

    Future<T> tracked<T>(Future<T> Function() fn) async {
      inFlight++;
      if (inFlight > maxInFlight) maxInFlight = inFlight;
      try {
        await Future<void>.delayed(const Duration(milliseconds: 10));
        return await fn();
      } finally {
        inFlight--;
      }
    }

    final spyStorage = _SpySecureStorage(store, tracked);
    final deviceIdentity = DeviceIdentity(store: SecureDeviceIdStore(storage: spyStorage));
    final secureStorage = SecureStorageService(storage: spyStorage);
    final lockService = AppLockService(storage: SecureLockStorage(storage: spyStorage));

    await Future.wait<void>(<Future<void>>[
      lockService.load(),
      deviceIdentity.id().then((_) {}),
      secureStorage.writeAccessToken('1|win-token', persist: true),
      secureStorage.writeUserId('7'),
      secureStorage.writeCachedUser(<String, dynamic>{'id': 7, 'email': 'ali@water.test'}),
    ]);

    if (!kIsWeb && Platform.isWindows) {
      expect(maxInFlight, 1);
    }
    expect(await secureStorage.readAccessToken(), '1|win-token');
    expect((await secureStorage.readCachedUser())?['id'], 7);
    expect(await deviceIdentity.id(), isNotEmpty);

    await expectLater(
      WindowsSecureStorageLock.run<void>(
        () async => throw StateError('boom'),
        isWindows: true,
      ),
      throwsStateError,
    );
    expect(
      await WindowsSecureStorageLock.run<int>(
        () async => 42,
        isWindows: true,
      ),
      42,
    );
  });

  test('AuthCubit logs non-Dio errors and stores redacted diagnosticDetail', () async {
    final session = _MockSessionService();
    when(() => session.login(
      email: any(named: 'email'),
      password: any(named: 'password'),
      rememberSession: any(named: 'rememberSession'),
    )).thenThrow(StateError('Failed for ali@alweshah.online on 777123456'));

    final reporter = RecordingErrorReporter();
    final apiLogs = ApiLogManager(maxEntries: 10);
    final cubit = AuthCubit(
      session,
      errorReporter: reporter,
      apiLogManager: apiLogs,
    );
    addTearDown(cubit.close);

    await cubit.login(email: 'ali@alweshah.online', password: 'pw');

    expect(cubit.state.status, AuthStatus.failure);
    expect(cubit.state.message, 'حدث خطأ غير متوقع. حاول مرة أخرى.');
    expect(cubit.state.diagnosticDetail, isNotNull);
    expect(cubit.state.diagnosticDetail, contains('[email]'));
    expect(cubit.state.diagnosticDetail, isNot(contains('ali@alweshah.online')));
    expect(cubit.state.diagnosticDetail, isNot(contains('777123456')));
    expect(reporter.recent, hasLength(1));
    expect(apiLogs.totalCount, 1);
  });

  testWidgets(
    'LoginScreen displays copyable diagnostic detail on Windows',
    variant: TargetPlatformVariant.only(TargetPlatform.windows),
    (tester) async {
      final session = _MockSessionService();
      when(() => session.login(
        email: any(named: 'email'),
        password: any(named: 'password'),
        rememberSession: any(named: 'rememberSession'),
      )).thenThrow(
        DioException(
          requestOptions: RequestOptions(path: 'login'),
          type: DioExceptionType.unknown,
          error: const HttpException('Connection closed while receiving data'),
        ),
      );

      final cubitWin = AuthCubit(session);
      addTearDown(cubitWin.close);
      await tester.pumpWidget(
        MaterialApp(
          home: BlocProvider.value(value: cubitWin, child: const LoginScreen()),
        ),
      );
      await cubitWin.login(email: 'a@b.c', password: 'pw');
      await tester.pump();
      expect(find.text('نسخ التفاصيل'), findsOneWidget);
    },
  );

  testWidgets(
    'LoginScreen hides copyable diagnostic detail on Android',
    variant: TargetPlatformVariant.only(TargetPlatform.android),
    (tester) async {
      final session = _MockSessionService();
      when(() => session.login(
        email: any(named: 'email'),
        password: any(named: 'password'),
        rememberSession: any(named: 'rememberSession'),
      )).thenThrow(
        DioException(
          requestOptions: RequestOptions(path: 'login'),
          type: DioExceptionType.unknown,
          error: const HttpException('Connection closed while receiving data'),
        ),
      );

      final cubitAndroid = AuthCubit(session);
      addTearDown(cubitAndroid.close);
      await tester.pumpWidget(
        MaterialApp(
          home: BlocProvider.value(value: cubitAndroid, child: const LoginScreen()),
        ),
      );
      await cubitAndroid.login(email: 'a@b.c', password: 'pw');
      await tester.pump();
      expect(find.text('نسخ التفاصيل'), findsNothing);
    },
  );
}

class _SpySecureStorage extends Fake implements FlutterSecureStorage {
  _SpySecureStorage(this.store, this.track);

  final Map<String, String> store;
  final Future<T> Function<T>(Future<T> Function() fn) track;

  @override
  Future<String?> read({
    required String key,
    IOSOptions? iOptions,
    AndroidOptions? aOptions,
    LinuxOptions? lOptions,
    WebOptions? webOptions,
    MacOsOptions? mOptions,
    WindowsOptions? wOptions,
  }) =>
      track(() async => store[key]);

  @override
  Future<void> write({
    required String key,
    required String? value,
    IOSOptions? iOptions,
    AndroidOptions? aOptions,
    LinuxOptions? lOptions,
    WebOptions? webOptions,
    MacOsOptions? mOptions,
    WindowsOptions? wOptions,
  }) =>
      track(() async {
        if (value == null) {
          store.remove(key);
        } else {
          store[key] = value;
        }
      });

  @override
  Future<void> delete({
    required String key,
    IOSOptions? iOptions,
    AndroidOptions? aOptions,
    LinuxOptions? lOptions,
    WebOptions? webOptions,
    MacOsOptions? mOptions,
    WindowsOptions? wOptions,
  }) =>
      track(() async {
        store.remove(key);
      });
}

class _CaptureErrorHandler extends ErrorInterceptorHandler {
  _CaptureErrorHandler(this.completer);

  final Completer<DioException> completer;

  @override
  void reject(DioException err, [bool callFollowingErrorInterceptor = false]) {
    if (!completer.isCompleted) {
      completer.complete(err);
    }
  }
}
